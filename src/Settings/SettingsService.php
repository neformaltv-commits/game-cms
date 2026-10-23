<?php

declare(strict_types=1);

namespace App\Settings;

use App\Audit\Logger;
use App\GenreService;
use App\GameService;
use App\Storage\FileStore;
use App\Validation\SettingsValidator;

/**
 * Управление, звуки и настройки движка (ТЗ блок 9).
 * Все три поля карточки игры сохраняются атомарно через FileStore::mutate (flock).
 */
final class SettingsService
{
    public function __construct(
        private readonly FileStore $store,
        private readonly Logger $audit,
        private readonly GenreService $genres,
        private readonly GameService $games,
    ) {
    }

    /** @return array{game:array<string,mixed>,genre:?array<string,mixed>}|null */
    private function context(string $gameId): ?array
    {
        $game = $this->games->find($gameId);
        if ($game === null) {
            return null;
        }
        $genre = $this->genres->find((string)$game['genre_id']);
        return ['game' => $game, 'genre' => $genre];
    }

    /**
     * Активные действия управления для движка + легенды/настроек игры (§2.2).
     * @return array{required:list<string>,optional:list<string>}
     */
    public function activeActions(string $engine, array $legend, array $overrides): array
    {
        $diggable = false;
        foreach ($legend as $el) {
            if (is_array($el) && ($el['role'] ?? '') === 'obstacle' && ($el['diggable'] ?? false) === true) {
                $diggable = true;
                break;
            }
        }
        $movementType = (string)($overrides['movement_type'] ?? 'gravity');
        $jumpPower = $overrides['physics']['jump_power'] ?? 0.0;
        switch ($engine) {
            case 'vertical-scroll':
                return ['required' => ['left', 'right', 'up', 'down', 'fire', 'pause', 'start'], 'optional' => []];
            case 'horizontal-scroll':
                return ['required' => ['left', 'right', 'up', 'down', 'jump', 'fire', 'pause', 'start'], 'optional' => ['action']];
            default: // static-screen
                $optional = [];
                if ($movementType === 'gravity' && is_numeric($jumpPower) && (float)$jumpPower < 0) {
                    $optional[] = 'jump';
                }
                if ($diggable) {
                    $optional[] = 'dig_left';
                    $optional[] = 'dig_right';
                }
                // fire/action активны при player_attacks (блок 10) — пока опциональны
                $optional[] = 'fire';
                $optional[] = 'action';
                return ['required' => ['left', 'right', 'up', 'down', 'pause', 'start'], 'optional' => $optional];
        }
    }

    /**
     * Сохранение controls из формы вкладки «Управление» (§7.1).
     * Неизвестные действия отбрасываются; неактивные действия сохраняются как есть (§3.6).
     * @param array<string,mixed> $input action => "code1, code2" или list<string>
     * @return list<string> ошибки
     */
    public function saveControls(string $gameId, array $input): array
    {
        $ctx = $this->context($gameId);
        if ($ctx === null) {
            return ['ok' => false, 'errors' => ['Игра не найдена.']];
        }
        $engine = (string)($ctx['genre']['engine'] ?? '');
        $legend = $this->games->effectiveLegend($ctx['game']);
        $currentOverrides = self::toArray($ctx['game']['engine_overrides'] ?? []);
        $actions = $this->activeActions($engine, $legend, $currentOverrides);

        $controls = (array)($ctx['game']['controls'] ?? []);
        $changedActions = [];
        $addedKeys = [];
        foreach ($input as $action => $raw) {
            $action = (string)$action;
            if (!in_array($action, SettingsValidator::ACTIONS, true)) {
                continue; // неизвестное действие — отбрасываем
            }
            $keys = is_array($raw)
                ? array_values(array_filter(array_map(static fn ($k) => trim((string)$k), $raw)))
                : array_values(array_filter(array_map('trim', explode(',', (string)$raw))));
            $keys = array_values(array_unique($keys));
            $old = array_values((array)($controls[$action] ?? []));
            if ($old !== $keys) {
                $changedActions[] = $action;
                foreach ($keys as $k) {
                    if (!in_array($k, $old, true)) {
                        $addedKeys[] = [$action, $k];
                    }
                }
            }
            if ($keys === []) {
                unset($controls[$action]); // пустое значение = действие отключено (опциональное)
            } else {
                $controls[$action] = $keys;
            }
        }

        $errors = SettingsValidator::validateControls($controls, $actions['required']);
        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors]; // данные не изменяются при ошибке (§7.2)
        }

        $this->mutate($gameId, static function (array &$game) use ($controls): void {
            $game['controls'] = $controls;
            $game['updated_at'] = gmdate('Y-m-d\TH:i:s\Z');
        });
        $this->audit->log('game.controls_update', $gameId, [
            'changed_actions' => $changedActions,
            'added_keys' => $addedKeys,
        ]);
        return ['ok' => true, 'errors' => []];
    }

    /** Сброс controls к genre.default_controls (§3.4). @return list<string> */
    public function resetControls(string $gameId): array
    {
        $ctx = $this->context($gameId);
        if ($ctx === null) {
            return ['ok' => false, 'errors' => ['Игра не найдена.']];
        }
        $defaults = (array)($ctx['genre']['engine_config']['default_controls'] ?? []);
        $this->mutate($gameId, static function (array &$game) use ($defaults): void {
            $game['controls'] = $defaults;
            $game['updated_at'] = gmdate('Y-m-d\TH:i:s\Z');
        });
        $this->audit->log('game.settings_reset', $gameId, ['reset' => ['controls'], 'from' => 'genre']);
        return ['ok' => true, 'errors' => []];
    }

    /**
     * Сохранение sounds[] из формы (§7.2). Форма присылает sounds как JSON-строку либо
     * параллельные массивы s_event[], s_wave[], ...
     * @return list<string> ошибки
     */
    public function saveSounds(string $gameId, array $input): array
    {
        $ctx = $this->context($gameId);
        if ($ctx === null) {
            return ['ok' => false, 'errors' => ['Игра не найдена.']];
        }
        if (isset($input['sounds_json']) && is_string($input['sounds_json'])) {
            $decoded = json_decode($input['sounds_json'], true);
            if (!is_array($decoded)) {
                return ['ok' => false, 'errors' => ['sounds: повреждённые данные формы.']];
            }
            $sounds = $decoded;
        } elseif (isset($input['sounds']) && is_array($input['sounds'])) {
            $sounds = $input['sounds'];
        } else {
            $events = (array)($input['s_event'] ?? []);
            $sounds = [];
            foreach ($events as $i => $ev) {
                $sounds[] = [
                    'event' => (string)$ev,
                    'wave' => (string)($input['s_wave'][$i] ?? ''),
                    'frequency_hz' => self::num($input['s_frequency'][$i] ?? null),
                    'frequency_end_hz' => self::numOrNull($input['s_frequency_end'][$i] ?? null),
                    'duration_ms' => self::num($input['s_duration'][$i] ?? null),
                    'volume' => self::num($input['s_volume'][$i] ?? null),
                    'envelope' => (string)($input['s_envelope'][$i] ?? ''),
                ];
            }
        }

        $errors = SettingsValidator::validateSounds($sounds);
        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors];
        }

        $oldEvents = array_column(array_map(static fn ($s) => (array)$s, (array)($ctx['game']['sounds'] ?? [])), 'event');
        $newEvents = array_column($sounds, 'event');
        $changedEvents = array_values(array_unique(array_merge(
            array_diff($oldEvents, $newEvents),
            array_diff($newEvents, $oldEvents),
        )));

        $this->mutate($gameId, static function (array &$game) use ($sounds): void {
            $game['sounds'] = $sounds;
            $game['updated_at'] = gmdate('Y-m-d\TH:i:s\Z');
        });
        $this->audit->log('game.sounds_update', $gameId, ['changed_events' => $changedEvents]);
        return ['ok' => true, 'errors' => []];
    }

    /** Сброс sounds к genre.default_sounds (§5.6). @return list<string> */
    public function resetSounds(string $gameId): array
    {
        $ctx = $this->context($gameId);
        if ($ctx === null) {
            return ['ok' => false, 'errors' => ['Игра не найдена.']];
        }
        $defaults = array_values((array)($ctx['genre']['engine_config']['default_sounds'] ?? []));
        $this->mutate($gameId, static function (array &$game) use ($defaults): void {
            $game['sounds'] = $defaults;
            $game['updated_at'] = gmdate('Y-m-d\TH:i:s\Z');
        });
        $this->audit->log('game.settings_reset', $gameId, ['reset' => ['sounds'], 'from' => 'genre']);
        return ['ok' => true, 'errors' => []];
    }

    /**
     * Сохранение engine_overrides: нормализация типов по white-list (§8), затем валидация (§7.3).
     * @param array<string,mixed> $input плоские поля + physics[...] и т.п. из формы
     * @return list<string> ошибки
     */
    public function saveOverrides(string $gameId, array $input): array
    {
        $ctx = $this->context($gameId);
        if ($ctx === null) {
            return ['ok' => false, 'errors' => ['Игра не найдена.']];
        }
        $engine = (string)($ctx['genre']['engine'] ?? '');
        $source = isset($input['engine_overrides']) && is_array($input['engine_overrides'])
            ? $input['engine_overrides'] : $input;
        $overrides = self::normalize($source);
        $errors = SettingsValidator::validateOverrides($overrides, $engine);
        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors];
        }

        $old = self::toArray($ctx['game']['engine_overrides'] ?? []);
        $changed = self::diffFields($old, $overrides);

        $this->mutate($gameId, static function (array &$game) use ($overrides): void {
            $game['engine_overrides'] = self::jsonObject($overrides);
            $game['updated_at'] = gmdate('Y-m-d\TH:i:s\Z');
        });
        $this->audit->log('game.engine_overrides_update', $gameId, ['changed' => $changed]);
        return ['ok' => true, 'errors' => []];
    }

    /** Сброс engine_overrides к дефолтам жанра = пустой объект (§4.4). */
    public function resetOverridesToGenre(string $gameId): array
    {
        if ($this->context($gameId) === null) {
            return ['ok' => false, 'errors' => ['Игра не найдена.']];
        }
        $this->mutate($gameId, static function (array &$game): void {
            $game['engine_overrides'] = new \stdClass();
            $game['updated_at'] = gmdate('Y-m-d\TH:i:s\Z');
        });
        $this->audit->log('game.settings_reset', $gameId, ['reset' => ['engine_overrides'], 'from' => 'genre']);
        return ['ok' => true, 'errors' => []];
    }

    /**
     * Копирование настроек из другой игры того же движка (§6).
     * @param list<string> $fields подмножество controls|sounds|engine_overrides
     * @return list<string> ошибки
     */
    public function copyFrom(string $gameId, string $sourceId, array $fields): array
    {
        $ctx = $this->context($gameId);
        $src = $this->context($sourceId);
        if ($ctx === null) {
            return ['ok' => false, 'errors' => ['Игра не найдена.']];
        }
        if ($src === null || $sourceId === $gameId) {
            return ['ok' => false, 'errors' => ['Источник не найден.']];
        }
        $e1 = (string)($ctx['genre']['engine'] ?? '');
        $e2 = (string)($src['genre']['engine'] ?? '');
        if ($e1 !== $e2) {
            return ['ok' => false, 'errors' => ['Источник должен быть того же движка.']];
        }
        $allowed = ['controls', 'sounds', 'engine_overrides'];
        $fields = array_values(array_intersect($fields, $allowed));
        if ($fields === []) {
            return ['ok' => false, 'errors' => ['Не выбрано ни одного поля для копирования.']];
        }
        $this->mutate($gameId, static function (array &$game) use ($src, $fields): void {
            foreach ($fields as $f) {
                $val = $src['game'][$f] ?? match ($f) {
                    'sounds' => [],
                    'engine_overrides' => new \stdClass(),
                    default => [],
                };
                $game[$f] = $val;
            }
            $game['updated_at'] = gmdate('Y-m-d\TH:i:s\Z');
        });
        $this->audit->log('game.settings_copy', $gameId, ['source' => $sourceId, 'fields' => $fields]);
        return ['ok' => true, 'errors' => []];
    }

    /**
     * Нормализация input → overrides (только white-list §2.7, приведение типов).
     * Неизвестные поля отбрасываются (§8 «Подмена genre.engine_config»).
     * @return array<string,mixed>
     */
    public static function normalize(array $input): array
    {
        $out = [];
        $boolFields = ['sprite_flicker', 'sound_enabled', 'show_hud'];
        $numFields = [
            'speed_scale', 'sprite_flicker_intensity', 'input_responsiveness', 'sound_volume',
            'animation_speed_scale', 'camera_lerp', 'scroll_speed',
            'hole_duration_ms', 'trap_duration_ms',
        ];
        $strEnums = ['camera_mode', 'scroll_direction', 'world_generation', 'movement_type', 'view_mode'];
        $intEnums = ['fps'];

        foreach ($boolFields as $f) {
            // чекбокс присутствует => true; отсутствует => не переопределяем (пусто = дефолт жанра)
            if (array_key_exists($f, $input)) {
                $out[$f] = filter_var($input[$f], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;
            }
        }
        foreach (array_merge($numFields, $intEnums) as $f) {
            $v = $input[$f] ?? null;
            if ($v !== null && $v !== '' && is_numeric($v)) {
                $out[$f] = in_array($f, $intEnums, true) ? (int)$v : (float)$v;
            }
        }
        foreach ($strEnums as $f) {
            $v = trim((string)($input[$f] ?? ''));
            if ($v !== '') {
                $out[$f] = $v;
            }
        }

        // Вложенные группы physics / combat / player (field => value из формы вида physics[gravity])
        $groupFields = [
            'physics' => [
                'gravity', 'move_speed', 'jump_power', 'climb_speed', 'swim_speed',
                'coyote_time_ms', 'jump_buffer_ms', 'diagonal_movement', 'max_fall_speed',
                'variable_jump', 'double_jump', 'air_control', 'vertical_move_speed',
                'vertical_range', 'boundary_behavior',
            ],
            'combat' => ['invulnerability_ms', 'enemy_contact_damage', 'player_melee_cooldown_ms', 'player_fire_rate_ms'],
            'player' => ['fuel_capacity', 'fuel_consumption_per_sec'],
        ];
        $boolSub = ['diagonal_movement', 'variable_jump', 'double_jump'];
        foreach ($groupFields as $group => $fields) {
            $sub = [];
            foreach ((array)($input[$group] ?? []) as $k => $v) {
                $k = (string)$k;
                if (!in_array($k, $fields, true)) {
                    continue; // белый список
                }
                if ($v === '' || $v === null) {
                    continue;
                }
                if (in_array($k, $boolSub, true)) {
                    $sub[$k] = filter_var($v, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;
                } elseif (is_numeric($v)) {
                    $sub[$k] = (float)$v;
                } else {
                    $sub[$k] = (string)$v; // enum-строки (boundary_behavior)
                }
            }
            if ($sub !== []) {
                $out[$group] = $sub;
            }
        }

        return $out;
    }

    /** Пустые массивы => stdClass, чтобы JSON-объекты не превращались в [] при перечитывании. */
    public static function jsonObject(array $overrides): object|array
    {
        return $overrides === [] ? new \stdClass() : $overrides;
    }

    /** engine_overrides может быть stdClass после round-trip через JSON. */
    public static function toArray(mixed $v): array
    {
        if ($v instanceof \stdClass) {
            return get_object_vars($v);
        }
        return is_array($v) ? $v : [];
    }

    /** @return list<string> отличия old→new с точками для вложенных полей (для аудита) */
    private static function diffFields(array $old, array $new): array
    {
        $changed = [];
        $keys = array_unique(array_merge(array_keys($old), array_keys($new)));
        sort($keys);
        foreach ($keys as $k) {
            $a = $old[$k] ?? null;
            $b = $new[$k] ?? null;
            if (json_encode($a) === json_encode($b)) {
                continue;
            }
            if (is_array($a) && is_array($b)) {
                $sk = array_unique(array_merge(array_keys($a), array_keys($b)));
                sort($sk);
                foreach ($sk as $s) {
                    if (json_encode($a[$s] ?? null) !== json_encode($b[$s] ?? null)) {
                        $changed[] = $k . '.' . $s;
                    }
                }
            } else {
                $changed[] = (string)$k;
            }
        }
        return $changed;
    }

    private static function num(mixed $v): mixed
    {
        if (!is_numeric($v)) {
            return null;
        }
        $s = (string)$v;
        return str_contains($s, '.') || stripos($s, 'e') !== false ? (float)$v : (int)$v;
    }

    private static function numOrNull(mixed $v): mixed
    {
        if ($v === null || $v === '') {
            return null;
        }
        return self::num($v);
    }

    private function mutate(string $gameId, callable $fn): void
    {
        $this->store->mutate('games/' . $gameId . '.json', static function (mixed &$game) use ($fn): bool {
            if (!is_array($game)) {
                return false;
            }
            $fn($game);
            return true;
        });
    }
}
