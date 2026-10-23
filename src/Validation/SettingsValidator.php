<?php

declare(strict_types=1);

namespace App\Validation;

use App\Settings\SettingsService;

/**
 * Валидация controls / sounds / engine_overrides (ТЗ блок 9 §7 + блок 10 §6, §7.3).
 * Только допустимые поля из белого списка §2.7 (+ player_attacks/scoring блока 10);
 * неизвестные отбрасываются (см. SettingsService).
 */
final class SettingsValidator
{
    public const ACTIONS = ['left', 'right', 'up', 'down', 'jump', 'fire', 'dig_left', 'dig_right', 'action', 'pause', 'start'];

    /** Поля engine_overrides: блок 9 (§2.7) + блок 10 (player_attacks, scoring, player-расширение). */
    public const OVERRIDE_FIELDS = [
        // общие 9 (§2.7)
        'speed_scale', 'sprite_flicker', 'sprite_flicker_intensity', 'input_responsiveness',
        'sound_volume', 'sound_enabled', 'fps', 'show_hud', 'animation_speed_scale',
        // группы/спецполя блока 9
        'physics', 'combat', 'camera_mode', 'camera_lerp', 'scroll_direction', 'scroll_speed',
        'world_generation', 'hole_duration_ms', 'trap_duration_ms', 'movement_type', 'view_mode', 'player',
        // блок 10
        'player_attacks', 'scoring',
    ];

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

        // player_attacks (блок 10 §2.7): валидация по схеме CombatValidator
        if (array_key_exists('player_attacks', $overrides)) {
            if (!is_array($overrides['player_attacks'])) {
                $errors[] = 'player_attacks: ожидается объект.';
            } else {
                $errors = array_merge($errors, CombatValidator::validatePlayerAttacks($overrides['player_attacks']));
            }
        }
        // scoring (блок 10 §2.6)
        if (array_key_exists('scoring', $overrides)) {
            if (!is_array($overrides['scoring'])) {
                $errors[] = 'scoring: ожидается объект.';
            } else {
                $errors = array_merge($errors, CombatValidator::validateScoring($overrides['scoring']));
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

        // player (блок 9: топливо; блок 10 §2.3: lives/health_max/dodge/parry)
        if (array_key_exists('player', $overrides)) {
            if (!is_array($overrides['player'])) {
                $errors[] = 'player: ожидается объект.';
            } else {
                $errors = array_merge($errors, CombatValidator::validatePlayer($overrides['player']));
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

        // Блок 10 §7.3: stomp несовместим с top_down_* (гравитация обязательна)
        if (($overrides['player_attacks']['stomp']['enabled'] ?? false) === true
            && is_string($mt) && str_starts_with($mt, 'top_down')) {
            $errors[] = 'Согласованность: player_attacks.stomp несовместим с movement_type «' . $mt . '» (нужна гравитация).';
        }

        return $errors;
    }

    /**
     * Кросс-проверки engine_overrides × controls/legend (ТЗ блок 10 §7.3).
     * Вызывается из SettingsService::saveOverrides(); ошибки блокируют сохранение.
     * @param array<string,mixed> $overrides нормализованные поля движка
     * @param array<string,mixed> $controls merged-назначения игры
     * @param array<string,mixed> $legend эффективная легенда игры
     * @param string $engine движок жанра
     * @return list<string>
     */
    public static function validateOverridesConsistency(array $overrides, array $controls, array $legend, string $engine): array
    {
        $errors = [];
        $pa = $overrides['player_attacks'] ?? null;
        if (!is_array($pa)) {
            return $errors;
        }

        $hasKey = static fn (string $action): bool => (array)($controls[$action] ?? []) !== [];
        $pickupIds = [];
        foreach ($legend as $el) {
            if (is_array($el) && ($el['role'] ?? '') === 'pickup' && (string)($el['pickup_id'] ?? '') !== '') {
                $pickupIds[(string)$el['pickup_id']] = true;
            }
        }

        $melee = (array)($pa['melee'] ?? []);
        if (($melee['enabled'] ?? false) === true && !$hasKey('fire')) {
            $errors[] = 'player_attacks.melee включён, но действию «fire» не назначена клавиша.';
        }

        $ranged = (array)($pa['ranged'] ?? []);
        if (($ranged['enabled'] ?? false) === true) {
            if (!$hasKey('fire')) {
                $errors[] = 'player_attacks.ranged включён, но действию «fire» не назначена клавиша.';
            }
            $pe = (string)($ranged['projectile_emoji'] ?? '');
            $el = is_string($pe) ? ($legend[$pe] ?? null) : null;
            if (!is_array($el) || ($el['role'] ?? '') !== 'projectile') {
                $errors[] = sprintf('player_attacks.ranged: снаряд «%s» должен существовать в легенде с ролью projectile.', $pe);
            } elseif ((float)($el['damage'] ?? 0) < 1) {
                $errors[] = sprintf('player_attacks.ranged: снаряд «%s» должен иметь damage ≥ 1.', $pe);
            }
        }

        $stomp = (array)($pa['stomp'] ?? []);
        if (($stomp['enabled'] ?? false) === true) {
            $mt = (string)($overrides['movement_type'] ?? 'gravity');
            if ($engine === 'vertical-scroll' || str_starts_with($mt, 'top_down')) {
                $errors[] = 'player_attacks.stomp доступен только движкам с гравитацией (static-screen/gravity, horizontal-scroll).';
            }
            $morph = $stomp['morph_to'] ?? null;
            if ($morph !== null && $morph !== '') {
                $mel = $legend[(string)$morph] ?? null;
                if (!is_array($mel) || ($mel['role'] ?? '') !== 'mob') {
                    $errors[] = sprintf('player_attacks.stomp.morph_to: «%s» не является mob легенды.', (string)$morph);
                }
            }
        }

        $special = (array)($pa['special'] ?? []);
        if (($special['enabled'] ?? false) === true) {
            $act = (string)($special['action'] ?? 'action');
            if (!$hasKey($act)) {
                $errors[] = sprintf('player_attacks.special включён, но действию «%s» не назначена клавиша.', $act);
            }
            $recharge = $special['recharge_by_pickup'] ?? null;
            if ($recharge !== null && $recharge !== '' && !isset($pickupIds[(string)$recharge])) {
                $errors[] = sprintf('player_attacks.special.recharge_by_pickup: pickup_id «%s» не найден в легенде.', (string)$recharge);
            }
            if ((string)($special['type'] ?? '') === 'time_stop' && !array_key_exists('freeze_duration_ms', $special)) {
                $errors[] = 'player_attacks.special: type=time_stop требует freeze_duration_ms.';
            }
            if ((string)($special['type'] ?? '') === 'shield' && !array_key_exists('shield_duration_ms', $special)) {
                $errors[] = 'player_attacks.special: type=shield требует shield_duration_ms.';
            }
        }

        // dodge / parry (§2.3) — клавиша действия обязательна при enabled
        $player = (array)($overrides['player'] ?? []);
        foreach (['dodge' => 'fire', 'parry' => 'action'] as $sub => $defaultAction) {
            $sec = (array)($player[$sub] ?? []);
            if (($sec['enabled'] ?? false) === true) {
                $act = (string)($sec['action'] ?? $defaultAction);
                if (!$hasKey($act)) {
                    $errors[] = sprintf('player.%s включено, но действию «%s» не назначена клавиша.', $sub, $act);
                }
            }
        }

        return $errors;
    }
}
