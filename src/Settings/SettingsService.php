<?php

declare(strict_types=1);

namespace App\Settings;

use App\Audit\Logger;
use App\GenreService;
use App\GameService;
use App\Storage\FileStore;
use App\Validation\CombatValidator;
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
        // В форме вкладки присутствуют только активные действия (§3.1); неактивные
        // остаются в JSON как есть (§3.6). Пустой POST = ничего не редактируем.
        $activeNames = array_merge($actions['required'], $actions['optional']);
        foreach ($input as $action => $raw) {
            $action = (string)$action;
            if (!in_array($action, SettingsValidator::ACTIONS, true)) {
                continue; // неизвестное действие — отбрасываем
            }
            if (!in_array($action, $activeNames, true)) {
                continue; // неактивное для движка/легенды действие не трогаем (§3.6)
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
                // пустое значение = действие отключено (опциональное) — только если
                // пользователь реально отправил обе строки поля (пустые inputs),
                // иначе поле вне формы не должно затырать существующее назначение.
                $rows = array_key_exists($action, (array)($_POST['controls_present'] ?? []));
                if ($rows || !array_key_exists($action, $controls)) {
                    unset($controls[$action]);
                }
            } else {
                $controls[$action] = $keys;
            }
        }

        // Обязательные действия проверяются по merged-картине: значения формы поверх
        // сохранённых controls (в т.ч. дефолтов жанра для старых игр без поля — §10.6-40).
        $merged = $controls;
        foreach ($input as $action => $raw) {
            $action = (string)$action;
            if (!in_array($action, SettingsValidator::ACTIONS, true) || !in_array($action, $activeNames, true)) {
                continue;
            }
            $keys = is_array($raw)
                ? array_values(array_filter(array_map(static fn ($k) => trim((string)$k), $raw)))
                : array_values(array_filter(array_map('trim', explode(',', (string)$raw))));
            if ($keys === []) {
                unset($merged[$action]);
            } else {
                $merged[$action] = array_values(array_unique($keys));
            }
        }
        $errors = SettingsValidator::validateControls($merged, $actions['required']);
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

    // ==================== Блок 10: боевая система ====================

    /**
     * Сохранение player_attacks (ТЗ блок 10 §2.1–§2.2, валидация §6.1/§7).
     * @param array<string,mixed> $input из формы (вложенные melee/ranged/stomp/special)
     * @return array{ok:bool,errors:list<string>}
     */
    public function savePlayerAttacks(string $gameId, array $input): array
    {
        $ctx = $this->context($gameId);
        if ($ctx === null) {
            return ['ok' => false, 'errors' => ['Игра не найдена.']];
        }
        $engine = (string)($ctx['genre']['engine'] ?? '');
        $legend = $this->games->effectiveLegend($ctx['game']);
        $overrides = self::toArray($ctx['game']['engine_overrides'] ?? []);

        $pa = self::normalizeAttacks($input);
        $errors = CombatValidator::validatePlayerAttacks($pa);

        // Кросс-проверки §6.1/§7 (по merged-картине controls игры)
        $controls = (array)($ctx['game']['controls'] ?? []);
        if (($pa['melee']['enabled'] ?? false) === true && (array)($controls['fire'] ?? []) === []) {
            $errors[] = 'melee включён: действию «fire» должна быть назначена клавиша (вкладка «Управление»).';
        }
        if (($pa['ranged']['enabled'] ?? false) === true) {
            $pe = (string)($pa['ranged']['projectile_emoji'] ?? '');
            $el = $legend[$pe] ?? null;
            if (!is_array($el) || ($el['role'] ?? '') !== 'projectile') {
                $errors[] = sprintf('ranged включён: снаряд «%s» должен существовать в легенде с ролью projectile.', $pe);
            } elseif ((float)($el['damage'] ?? 0) < 1) {
                $errors[] = sprintf('ranged включён: снаряд «%s» должен иметь damage ≥ 1.', $pe);
            }
            if ((array)($controls['fire'] ?? []) === []) {
                $errors[] = 'ranged включён: действию «fire» должна быть назначена клавиша.';
            }
        }
        if (($pa['stomp']['enabled'] ?? false) === true) {
            $movementType = (string)($overrides['movement_type'] ?? 'gravity');
            if ($engine === 'vertical-scroll' || str_starts_with($movementType, 'top_down')) {
                $errors[] = 'stomp доступен только для движков с гравитацией (static-screen/gravity, horizontal-scroll).';
            }
        }
        if (($pa['special']['enabled'] ?? false) === true) {
            $action = (string)($pa['special']['action'] ?? 'action');
            if ((array)($controls[$action] ?? []) === []) {
                $errors[] = sprintf('спецприём включён: действию «%s» должна быть назначена клавиша.', $action);
            }
            $recharge = $pa['special']['recharge_by_pickup'] ?? null;
            if ($recharge !== null && $recharge !== '') {
                $found = false;
                foreach ($legend as $el2) {
                    if (is_array($el2) && ($el2['role'] ?? '') === 'pickup' && (string)($el2['pickup_id'] ?? '') === (string)$recharge) {
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    $errors[] = sprintf('special.recharge_by_pickup: pickup_id «%s» не найден в легенде.', (string)$recharge);
                }
            }
            if (($pa['special']['type'] ?? '') === 'time_stop' && !array_key_exists('freeze_duration_ms', $pa['special'])) {
                $errors[] = 'special.type=time_stop: требуется freeze_duration_ms.';
            }
            if (($pa['special']['type'] ?? '') === 'shield' && !array_key_exists('shield_duration_ms', $pa['special'])) {
                $errors[] = 'special.type=shield: требуется shield_duration_ms.';
            }
        }

        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors];
        }

        $old = self::toArray($overrides['player_attacks'] ?? []);
        $changed = self::diffFields($old, $pa);
        $this->mutate($gameId, static function (array &$game) use ($pa): void {
            $ov = self::toArray($game['engine_overrides'] ?? []);
            $ov['player_attacks'] = $pa;
            $game['engine_overrides'] = $ov;
            $game['updated_at'] = gmdate('Y-m-d\\TH:i:s\\Z');
        });
        $this->audit->log('game.player_attacks_update', $gameId, ['changed' => array_values(array_unique($changed))]);
        return ['ok' => true, 'errors' => []];
    }

    /**
     * Сохранение player (lives, health_max, invulnerability_ms, dodge, parry) — §2.3, §6.2.
     * @return array{ok:bool,errors:list<string>}
     */
    public function savePlayer(string $gameId, array $input): array
    {
        $ctx = $this->context($gameId);
        if ($ctx === null) {
            return ['ok' => false, 'errors' => ['Игра не найдена.']];
        }
        $player = [];
        foreach (['lives', 'health_max', 'invulnerability_ms'] as $f) {
            if (array_key_exists($f, $input) && is_numeric($input[$f])) {
                $player[$f] = (int)$input[$f];
            }
        }
        foreach (['dodge', 'parry'] as $sub) {
            $in = (array)($input[$sub] ?? []);
            $obj = [];
            if (array_key_exists('enabled', $in)) {
                $obj['enabled'] = filter_var($in['enabled'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;
            }
            foreach (['duration_ms', 'invulnerability_ms', 'cooldown_ms', 'window_ms'] as $f) {
                if (array_key_exists($f, $in) && is_numeric($in[$f])) {
                    $obj[$f] = (int)$in[$f];
                }
            }
            foreach (['action'] as $f) {
                $v = trim((string)($in[$f] ?? ''));
                if ($v !== '' && in_array($v, SettingsValidator::ACTIONS, true)) {
                    $obj[$f] = $v;
                }
            }
            if ($obj !== []) {
                $player[$sub] = $obj;
            }
        }
        // fuel-поля блока 9 сохраняем как есть, если прислали
        foreach (['fuel_capacity', 'fuel_consumption_per_sec'] as $f) {
            if (array_key_exists($f, $input) && is_numeric($input[$f])) {
                $player[$f] = (float)$input[$f];
            }
        }

        $errors = CombatValidator::validatePlayer($player);
        $controls = (array)($ctx['game']['controls'] ?? []);
        foreach (['dodge', 'parry'] as $sub) {
            if (($player[$sub]['enabled'] ?? false) === true) {
                $act = (string)($player[$sub]['action'] ?? ($sub === 'dodge' ? 'fire' : 'action'));
                if ((array)($controls[$act] ?? []) === []) {
                    $errors[] = sprintf('%s включено: действию «%s» должна быть назначена клавиша.', ucfirst($sub), $act);
                }
            }
        }
        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors];
        }

        $overrides = self::toArray($ctx['game']['engine_overrides'] ?? []);
        $old = self::toArray($overrides['player'] ?? []);
        $changed = self::diffFields($old, $player);
        $this->mutate($gameId, static function (array &$game) use ($player): void {
            $ov = self::toArray($game['engine_overrides'] ?? []);
            $ov['player'] = array_merge(self::toArray($ov['player'] ?? []), $player);
            $game['engine_overrides'] = $ov;
            $game['updated_at'] = gmdate('Y-m-d\\TH:i:s\\Z');
        });
        $this->audit->log('game.player_update', $gameId, ['changed' => array_values(array_unique($changed))]);
        return ['ok' => true, 'errors' => []];
    }

    /**
     * Сохранение scoring (§2.6, §6.3) + миграция старых player.score_on_* (§2.6 примечание).
     * @return array{ok:bool,errors:list<string>}
     */
    public function saveScoring(string $gameId, array $input): array
    {
        $ctx = $this->context($gameId);
        if ($ctx === null) {
            return ['ok' => false, 'errors' => ['Игра не найдена.']];
        }
        $sc = [];
        foreach (['on_enemy_kill' => [0, 100000], 'on_pickup' => [0, 1000]] as $f => [$min, $max]) {
            if (array_key_exists($f, $input) && is_numeric($input[$f])) {
                $sc[$f] = (int)$input[$f];
            }
        }
        foreach (['combo', 'time_bonus'] as $sub) {
            $in = (array)($input[$sub] ?? []);
            $obj = [];
            if (array_key_exists('enabled', $in)) {
                $obj['enabled'] = filter_var($in['enabled'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;
            }
            foreach (['window_ms', 'base', 'per_second_remaining'] as $f) {
                if (array_key_exists($f, $in) && is_numeric($in[$f])) {
                    $obj[$f] = (int)$in[$f];
                }
            }
            foreach (['multiplier_per_hit', 'max_multiplier'] as $f) {
                if (array_key_exists($f, $in) && is_numeric($in[$f])) {
                    $obj[$f] = (float)$in[$f];
                }
            }
            if ($obj !== []) {
                $sc[$sub] = $obj;
            }
        }
        $errors = CombatValidator::validateScoring($sc);
        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors];
        }

        $overrides = self::toArray($ctx['game']['engine_overrides'] ?? []);
        $old = self::toArray($overrides['scoring'] ?? []);
        $changed = self::diffFields($old, $sc);
        $this->mutate($gameId, static function (array &$game) use ($sc): void {
            $ov = self::toArray($game['engine_overrides'] ?? []);
            $ov['scoring'] = array_merge(self::toArray($ov['scoring'] ?? []), $sc);
            // Миграция §2.6: старые player.score_on_* переносятся в scoring.on_* и удаляются.
            $pl = self::toArray($ov['player'] ?? []);
            foreach (['score_on_enemy_kill' => 'on_enemy_kill', 'score_on_pickup' => 'on_pickup'] as $oldKey => $newKey) {
                if (array_key_exists($oldKey, $pl)) {
                    if (!array_key_exists($newKey, $ov['scoring'])) {
                        $ov['scoring'][$newKey] = $pl[$oldKey];
                    }
                    unset($pl[$oldKey]);
                }
            }
            if ($pl !== []) {
                $ov['player'] = $pl;
            } else {
                unset($ov['player']);
            }
            $game['engine_overrides'] = $ov;
            $game['updated_at'] = gmdate('Y-m-d\\TH:i:s\\Z');
        });
        $this->audit->log('game.scoring_update', $gameId, ['changed' => array_values(array_unique($changed))]);
        return ['ok' => true, 'errors' => []];
    }

    /**
     * Нормализация формы player_attacks → схема §2.1 (только white-list полей).
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public static function normalizeAttacks(array $input): array
    {
        $out = [];
        $melee = (array)($input['melee'] ?? []);
        if ($melee !== []) {
            $m = [];
            $m['enabled'] = filter_var($melee['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;
            foreach (['damage', 'range_tiles', 'cooldown_ms', 'knockback_tiles', 'stun_ms'] as $f) {
                if (array_key_exists($f, $melee) && is_numeric($melee[$f])) {
                    $m[$f] = (int)$melee[$f];
                }
            }
            if (isset($melee['shape']) && trim((string)$melee['shape']) !== '') {
                $m['shape'] = trim((string)$melee['shape']);
            }
            $hits = array_values(array_filter(array_map('strval', (array)($melee['hits'] ?? [])), static fn ($h) => $h !== ''));
            if ($hits !== []) {
                $m['hits'] = $hits;
            }
            $out['melee'] = $m;
        }
        $ranged = (array)($input['ranged'] ?? []);
        if ($ranged !== []) {
            $r = [];
            $r['enabled'] = filter_var($ranged['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;
            $pe = trim((string)($ranged['projectile_emoji'] ?? ''));
            if ($pe !== '') {
                $r['projectile_emoji'] = $pe;
            }
            if (is_numeric($ranged['cooldown_ms'] ?? null)) {
                $r['cooldown_ms'] = (int)$ranged['cooldown_ms'];
            }
            if (isset($ranged['direction']) && trim((string)$ranged['direction']) !== '') {
                $r['direction'] = trim((string)$ranged['direction']);
            }
            $ammoIn = (array)($ranged['ammo'] ?? []);
            if ($ammoIn !== []) {
                $ammo = [];
                $ammo['type'] = in_array((string)($ammoIn['type'] ?? ''), CombatValidator::AMMO_TYPES, true)
                    ? (string)$ammoIn['type'] : 'infinite';
                if (is_numeric($ammoIn['start'] ?? null)) {
                    $ammo['start'] = (int)$ammoIn['start'];
                }
                if (is_numeric($ammoIn['max'] ?? null)) {
                    $ammo['max'] = (int)$ammoIn['max'];
                }
                $r['ammo'] = $ammo;
            }
            $chargeIn = (array)($ranged['charge'] ?? []);
            if ($chargeIn !== []) {
                $ch = [];
                $ch['enabled'] = filter_var($chargeIn['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;
                if (is_numeric($chargeIn['charge_ms'] ?? null)) {
                    $ch['charge_ms'] = (int)$chargeIn['charge_ms'];
                }
                if (is_numeric($chargeIn['damage_multiplier'] ?? null)) {
                    $ch['damage_multiplier'] = (float)$chargeIn['damage_multiplier'];
                }
                $r['charge'] = $ch;
            }
            $out['ranged'] = $r;
        }
        $stomp = (array)($input['stomp'] ?? []);
        if ($stomp !== []) {
            $s = [];
            $s['enabled'] = filter_var($stomp['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;
            foreach (['damage', 'stun_ms'] as $f) {
                if (is_numeric($stomp[$f] ?? null)) {
                    $s[$f] = (int)$stomp[$f];
                }
            }
            $morph = $stomp['morph_to'] ?? null;
            $s['morph_to'] = ($morph === null || $morph === '') ? null : (string)$morph;
            if (is_numeric($stomp['bounce_power'] ?? null)) {
                $s['bounce_power'] = (float)$stomp['bounce_power'];
            }
            $out['stomp'] = $s;
        }
        $special = (array)($input['special'] ?? []);
        if ($special !== []) {
            $sp = [];
            $sp['enabled'] = filter_var($special['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;
            foreach (['type', 'action'] as $f) {
                if (isset($special[$f]) && trim((string)$special[$f]) !== '') {
                    $sp[$f] = trim((string)$special[$f]);
                }
            }
            if (is_numeric($special['charges_max'] ?? null)) {
                $sp['charges_max'] = (int)$special['charges_max'];
            }
            $rbp = $special['recharge_by_pickup'] ?? null;
            $sp['recharge_by_pickup'] = ($rbp === null || $rbp === '') ? null : (string)$rbp;
            foreach (['freeze_duration_ms', 'shield_duration_ms'] as $f) {
                if (is_numeric($special[$f] ?? null)) {
                    $sp[$f] = (int)$special[$f];
                }
            }
            $out['special'] = $sp;
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
