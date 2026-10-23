<?php

declare(strict_types=1);

namespace App\Validation;

/**
 * Валидация controls / sounds / engine_overrides (ТЗ блок 9 §7).
 * Только допустимые поля из белого списка §2.7; неизвестные отбрасываются (см. SettingsService).
 */
final class SettingsValidator
{
    public const ACTIONS = ['left', 'right', 'up', 'down', 'jump', 'fire', 'dig_left', 'dig_right', 'action', 'pause', 'start'];

    public const KEY_CODE_RE = '/^(Arrow(Up|Down|Left|Right)|Key[A-Z]|Digit[0-9]|Space|Escape|Enter|Tab|Backquote|Minus|Equal|BracketLeft|BracketRight|Backslash|Semicolon|Quote|Comma|Period|Slash|(Shift|Control|Alt|Meta)(Left|Right)|F([1-9]|1[0-2])|Numpad[0-9])$/';

    private const MODIFIERS = ['ShiftLeft', 'ShiftRight', 'ControlLeft', 'ControlRight', 'AltLeft', 'AltRight', 'MetaLeft', 'MetaRight'];

    public const SOUND_EVENTS = ['jump', 'pickup', 'dig', 'hit', 'shoot', 'death', 'win', 'open', 'enemy_trapped', 'level_start'];
    public const WAVES = ['square', 'triangle', 'sawtooth', 'noise'];
    public const ENVELOPES = ['linear', 'decay', 'pluck'];

    /**
     * Валидация controls. @param list<string> $required обязательные действия движка
     * @return list<string>
     */
    public static function validateControls(mixed $controls, array $required): array
    {
        if (!is_array($controls)) {
            return ['controls должен быть объектом.'];
        }
        $errors = [];
        foreach ($controls as $action => $keys) {
            if (!in_array((string)$action, self::ACTIONS, true)) {
                $errors[] = sprintf('Неизвестное действие «%s».', (string)$action);
                continue;
            }
            if (!is_array($keys)) {
                $errors[] = sprintf('Действие «%s»: значение должно быть массивом клавиш.', (string)$action);
                continue;
            }
            if (count($keys) > 2) {
                $errors[] = sprintf('Действие «%s»: максимум 2 клавиши.', (string)$action);
            }
            foreach ($keys as $k) {
                if (!is_string($k) || preg_match(self::KEY_CODE_RE, $k) !== 1) {
                    $errors[] = sprintf('Действие «%s»: недопустимый KeyboardEvent.code «%s».', (string)$action, is_scalar($k) ? (string)$k : gettype($k));
                } elseif (count($keys) === 1 && in_array($k, self::MODIFIERS, true)) {
                    $errors[] = sprintf('Действие «%s»: модификатор «%s» не может быть единственной клавишей.', (string)$action, $k);
                }
            }
        }
        foreach ($required as $act) {
            $keys = (array)($controls[$act] ?? []);
            if ($keys === []) {
                $errors[] = sprintf('Действие «%s» должно иметь минимум одну клавишу.', $act);
            }
        }
        return $errors;
    }

    /**
     * Валидация sounds[].
     * @return list<string>
     */
    public static function validateSounds(mixed $sounds): array
    {
        if (!is_array($sounds)) {
            return ['sounds должен быть массивом.'];
        }
        $errors = [];
        if (count($sounds) > 10) {
            $errors[] = 'sounds: максимум 10 записей.';
        }
        $seenEvents = [];
        foreach ($sounds as $i => $s) {
            if (!is_array($s)) {
                $errors[] = sprintf('sounds[%d]: должен быть объектом.', (int)$i);
                continue;
            }
            $ev = (string)($s['event'] ?? '');
            if (!in_array($ev, self::SOUND_EVENTS, true)) {
                $errors[] = sprintf('sounds[%d]: недопустимое событие «%s».', (int)$i, $ev);
            } elseif (isset($seenEvents[$ev])) {
                $errors[] = sprintf('Событие «%s» дублируется в sounds[].', $ev);
            } else {
                $seenEvents[$ev] = true;
            }
            if (!in_array((string)($s['wave'] ?? ''), self::WAVES, true)) {
                $errors[] = sprintf('sounds[%d]: wave ∈ {square, triangle, sawtooth, noise}.', (int)$i);
            }
            $f = $s['frequency_hz'] ?? null;
            if (!is_numeric($f) || (float)$f < 20 || (float)$f > 4000) {
                $errors[] = sprintf('sounds[%d]: frequency_hz 20–4000.', (int)$i);
            }
            $fe = $s['frequency_end_hz'] ?? null;
            if ($fe !== null && (!is_numeric($fe) || (float)$fe < 20 || (float)$fe > 4000)) {
                $errors[] = sprintf('sounds[%d]: frequency_end_hz — null или 20–4000.', (int)$i);
            }
            $d = $s['duration_ms'] ?? null;
            if (!is_numeric($d) || (float)$d < 10 || (float)$d > 1000) {
                $errors[] = sprintf('sounds[%d]: duration_ms 10–1000.', (int)$i);
            }
            $v = $s['volume'] ?? null;
            if (!is_numeric($v) || (float)$v < 0 || (float)$v > 0.3) {
                $errors[] = sprintf('sounds[%d]: volume 0.0–0.3.', (int)$i);
            }
            if (!in_array((string)($s['envelope'] ?? ''), self::ENVELOPES, true)) {
                $errors[] = sprintf('sounds[%d]: envelope ∈ {linear, decay, pluck}.', (int)$i);
            }
        }
        return $errors;
    }

    /**
     * Валидация engine_overrides (только whitelisted-поля, уже нормализованные).
     * @param array<string,mixed> $overrides
     * @param string $engine движок жанра
     * @return list<string>
     */
    public static function validateOverrides(array $overrides, string $engine): array
    {
        $errors = [];
        $num = static function (string $field, mixed $val, float $min, float $max) use (&$errors): void {
            if (!is_numeric($val) || !is_finite((float)$val) || (float)$val < $min || (float)$val > $max) {
                $errors[] = sprintf('%s: значение вне диапазона %s–%s.', $field, rtrim(rtrim(number_format($min, 2, '.', ''), '0'), '.'), rtrim(rtrim(number_format($max, 2, '.', ''), '0'), '.'));
            }
        };
        $bool = static function (string $field, mixed $val) use (&$errors): void {
            if (!is_bool($val)) {
                $errors[] = sprintf('%s: ожидается true/false.', $field);
            }
        };
        $enum = static function (string $field, mixed $val, array $allowed) use (&$errors): void {
            if (!in_array($val, $allowed, true)) {
                $errors[] = sprintf('%s: допустимо %s.', $field, implode(' | ', array_map(static fn ($v) => (string)$v, $allowed)));
            }
        };

        // Общие 9 полей (§2.7)
        if (array_key_exists('speed_scale', $overrides)) $num('speed_scale', $overrides['speed_scale'], 0.5, 2.0);
        if (array_key_exists('sprite_flicker', $overrides)) $bool('sprite_flicker', $overrides['sprite_flicker']);
        if (array_key_exists('sprite_flicker_intensity', $overrides)) $num('sprite_flicker_intensity', $overrides['sprite_flicker_intensity'], 0.0, 1.0);
        if (array_key_exists('input_responsiveness', $overrides)) $num('input_responsiveness', $overrides['input_responsiveness'], 0.0, 1.0);
        if (array_key_exists('sound_volume', $overrides)) $num('sound_volume', $overrides['sound_volume'], 0.0, 0.3);
        if (array_key_exists('sound_enabled', $overrides)) $bool('sound_enabled', $overrides['sound_enabled']);
        if (array_key_exists('fps', $overrides)) $enum('fps', $overrides['fps'], [30, 60]);
        if (array_key_exists('show_hud', $overrides)) $bool('show_hud', $overrides['show_hud']);
        if (array_key_exists('animation_speed_scale', $overrides)) $num('animation_speed_scale', $overrides['animation_speed_scale'], 0.5, 2.0);

        // physics
        $physics = $overrides['physics'] ?? null;
        if ($physics !== null) {
            if (!is_array($physics)) {
                $errors[] = 'physics: ожидается объект.';
            } else {
                $pn = static function (string $field, mixed $val, float $min, float $max) use (&$errors): void {
                    if (!is_numeric($val) || !is_finite((float)$val) || (float)$val < $min || (float)$val > $max) {
                        $errors[] = sprintf('physics.%s: значение вне диапазона %s–%s.', $field, strval($min), strval($max));
                    }
                };
                if (array_key_exists('gravity', $physics)) $pn('gravity', $physics['gravity'], 0.0, 2.0);
                if (array_key_exists('move_speed', $physics)) $pn('move_speed', $physics['move_speed'], 0.5, 10.0);
                if (array_key_exists('jump_power', $physics)) $pn('jump_power', $physics['jump_power'], -10.0, 0.0);
                if (array_key_exists('climb_speed', $physics)) $pn('climb_speed', $physics['climb_speed'], 0.5, 3.0);
                if (array_key_exists('swim_speed', $physics)) $pn('swim_speed', $physics['swim_speed'], 0.5, 3.0);
                if (array_key_exists('coyote_time_ms', $physics)) $pn('coyote_time_ms', $physics['coyote_time_ms'], 0, 300);
                if (array_key_exists('jump_buffer_ms', $physics)) $pn('jump_buffer_ms', $physics['jump_buffer_ms'], 0, 300);
                if (array_key_exists('diagonal_movement', $physics) && !is_bool($physics['diagonal_movement'])) $errors[] = 'physics.diagonal_movement: ожидается true/false.';
                if (array_key_exists('max_fall_speed', $physics)) $pn('max_fall_speed', $physics['max_fall_speed'], 4.0, 20.0);
                if (array_key_exists('variable_jump', $physics) && !is_bool($physics['variable_jump'])) $errors[] = 'physics.variable_jump: ожидается true/false.';
                if (array_key_exists('double_jump', $physics) && !is_bool($physics['double_jump'])) $errors[] = 'physics.double_jump: ожидается true/false.';
                if (array_key_exists('air_control', $physics)) $pn('air_control', $physics['air_control'], 0.0, 1.0);
                if (array_key_exists('vertical_move_speed', $physics)) $pn('vertical_move_speed', $physics['vertical_move_speed'], 0.5, 10.0);
                if (array_key_exists('vertical_range', $physics)) $pn('vertical_range', $physics['vertical_range'], 1, 15);
                if (array_key_exists('boundary_behavior', $physics)) $enum('physics.boundary_behavior', $physics['boundary_behavior'], ['clamp', 'wrap']);
            }
        }

        // combat (базовый, без player_attacks — блок 10)
        $combat = $overrides['combat'] ?? null;
        if ($combat !== null) {
            if (!is_array($combat)) {
                $errors[] = 'combat: ожидается объект.';
            } else {
                $cn = static function (string $field, mixed $val, float $min, float $max) use (&$errors): void {
                    if (!is_numeric($val) || !is_finite((float)$val) || (float)$val < $min || (float)$val > $max) {
                        $errors[] = sprintf('combat.%s: значение вне диапазона %s–%s.', $field, strval($min), strval($max));
                    }
                };
                if (array_key_exists('invulnerability_ms', $combat)) $cn('invulnerability_ms', $combat['invulnerability_ms'], 0, 5000);
                if (array_key_exists('enemy_contact_damage', $combat)) $cn('enemy_contact_damage', $combat['enemy_contact_damage'], 0, 10);
                if (array_key_exists('player_melee_cooldown_ms', $combat)) $cn('player_melee_cooldown_ms', $combat['player_melee_cooldown_ms'], 100, 2000);
                if (array_key_exists('player_fire_rate_ms', $combat)) $cn('player_fire_rate_ms', $combat['player_fire_rate_ms'], 50, 2000);
            }
        }

        // Камера и скролл
        if (array_key_exists('camera_mode', $overrides)) $enum('camera_mode', $overrides['camera_mode'], ['follow', 'room', 'fixed']);
        if (array_key_exists('camera_lerp', $overrides)) $num('camera_lerp', $overrides['camera_lerp'], 0.05, 0.5);
        if (array_key_exists('scroll_direction', $overrides)) $enum('scroll_direction', $overrides['scroll_direction'], ['up', 'down']);
        if (array_key_exists('scroll_speed', $overrides)) $num('scroll_speed', $overrides['scroll_speed'], 0.5, 10.0);
        if (array_key_exists('world_generation', $overrides)) $enum('world_generation', $overrides['world_generation'], ['grid', 'procedural']);

        // Специфичные для static-screen
        if (array_key_exists('hole_duration_ms', $overrides)) $num('hole_duration_ms', $overrides['hole_duration_ms'], 500, 10000);
        if (array_key_exists('trap_duration_ms', $overrides)) $num('trap_duration_ms', $overrides['trap_duration_ms'], 500, 5000);
        if (array_key_exists('movement_type', $overrides)) $enum('movement_type', $overrides['movement_type'], ['gravity', 'top_down_4dir', 'top_down_8dir', 'top_down_4dir_no_diag']);
        if (array_key_exists('view_mode', $overrides)) $enum('view_mode', $overrides['view_mode'], ['side', 'top']);

        // Топливо (vertical-scroll)
        if (array_key_exists('player', $overrides)) {
            if (!is_array($overrides['player'])) {
                $errors[] = 'player: ожидается объект.';
            } else {
                $pl = $overrides['player'];
                if (array_key_exists('fuel_capacity', $pl) && (!is_numeric($pl['fuel_capacity']) || (float)$pl['fuel_capacity'] < 0 || (float)$pl['fuel_capacity'] > 1000)) {
                    $errors[] = 'player.fuel_capacity: 0–1000.';
                }
                if (array_key_exists('fuel_consumption_per_sec', $pl) && (!is_numeric($pl['fuel_consumption_per_sec']) || (float)$pl['fuel_consumption_per_sec'] < 0 || (float)$pl['fuel_consumption_per_sec'] > 20)) {
                    $errors[] = 'player.fuel_consumption_per_sec: 0.0–20.0.';
                }
            }
        }

        // Согласованность movement_type × view_mode (§7.3)
        $mt = $overrides['movement_type'] ?? null;
        $vm = $overrides['view_mode'] ?? null;
        if (is_string($mt) && is_string($vm)) {
            if (str_starts_with($mt, 'top_down') && $vm !== 'top') {
                $errors[] = 'Согласованность: movement_type «' . $mt . '» требует view_mode «top».';
            }
            if ($mt === 'gravity' && $vm !== 'side') {
                $errors[] = 'Согласованность: movement_type «gravity» требует view_mode «side».';
            }
        }

        return $errors;
    }
}
