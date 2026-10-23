<?php

declare(strict_types=1);

namespace App\Validation;

/**
 * Валидация боевых схем блока 10 (player_attacks, player, scoring) — ТЗ §2.1–§2.6, §7.
 * Чистые статические проверки диапазонов и enum; кросс-проверки с легендой/controls
 * выполняет SettingsService (ему нужны контекст игры и жанра).
 */
final class CombatValidator
{
    public const MELEE_SHAPES = ['single', 'arc', 'line'];
    public const MELEE_HITS = ['mob', 'collider', 'obstacle'];
    public const RANGED_DIRECTIONS = ['facing', 'up', 'down'];
    public const AMMO_TYPES = ['infinite', 'count'];
    public const SPECIAL_TYPES = ['screen_clear', 'time_stop', 'shield'];
    public const STATS = ['hp', 'score', 'lives'];

    /** @return list<string> */
    private static function num(mixed $v, float $min, float $max, string $ctx): array
    {
        if (!is_numeric($v) || !is_finite((float)$v) || (float)$v < $min || (float)$v > $max) {
            return [sprintf('%s: значение вне диапазона %s–%s.', $ctx, self::fmt($min), self::fmt($max))];
        }
        return [];
    }

    private static function fmt(float $v): string
    {
        return rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
    }

    /**
     * player_attacks (§2.1–§2.2). Кросс-проверки (fire назначен, projectile в легенде и т.п.) — вне этого метода.
     * @param array<string,mixed> $pa
     * @return list<string>
     */
    public static function validatePlayerAttacks(array $pa): array
    {
        $errors = [];

        // melee
        $melee = $pa['melee'] ?? null;
        if ($melee !== null && !is_array($melee)) {
            $errors[] = 'player_attacks.melee: ожидается объект.';
        } elseif (is_array($melee)) {
            $errors = array_merge($errors, self::num($melee['damage'] ?? 1, 1, 99, 'melee.damage'));
            $errors = array_merge($errors, self::num($melee['range_tiles'] ?? 1, 1, 5, 'melee.range_tiles'));
            if (!in_array((string)($melee['shape'] ?? 'single'), self::MELEE_SHAPES, true)) {
                $errors[] = 'melee.shape ∈ {single, arc, line}.';
            }
            $errors = array_merge($errors, self::num($melee['cooldown_ms'] ?? 300, 50, 2000, 'melee.cooldown_ms'));
            $hits = $melee['hits'] ?? ['mob'];
            if (!is_array($hits) || $hits === []) {
                $errors[] = 'melee.hits: непустой массив из {mob, collider, obstacle}.';
            } else {
                foreach ($hits as $h) {
                    if (!in_array((string)$h, self::MELEE_HITS, true)) {
                        $errors[] = sprintf('melee.hits: недопустимая цель «%s».', (string)$h);
                    }
                }
            }
            $errors = array_merge($errors, self::num($melee['knockback_tiles'] ?? 0, 0, 5, 'melee.knockback_tiles'));
            $errors = array_merge($errors, self::num($melee['stun_ms'] ?? 0, 0, 2000, 'melee.stun_ms'));
        }

        // ranged
        $ranged = $pa['ranged'] ?? null;
        if ($ranged !== null && !is_array($ranged)) {
            $errors[] = 'player_attacks.ranged: ожидается объект.';
        } elseif (is_array($ranged)) {
            $errors = array_merge($errors, self::num($ranged['cooldown_ms'] ?? 200, 50, 2000, 'ranged.cooldown_ms'));
            if (!in_array((string)($ranged['direction'] ?? 'facing'), self::RANGED_DIRECTIONS, true)) {
                $errors[] = 'ranged.direction ∈ {facing, up, down}.';
            }
            $ammo = $ranged['ammo'] ?? ['type' => 'infinite'];
            if (!is_array($ammo)) {
                $errors[] = 'ranged.ammo: ожидается объект.';
            } else {
                $type = (string)($ammo['type'] ?? 'infinite');
                if (!in_array($type, self::AMMO_TYPES, true)) {
                    $errors[] = 'ranged.ammo.type ∈ {infinite, count}.';
                }
                $errors = array_merge($errors, self::num($ammo['start'] ?? 10, 0, 999, 'ranged.ammo.start'));
                $errors = array_merge($errors, self::num($ammo['max'] ?? 30, 0, 999, 'ranged.ammo.max'));
                if ($type === 'count') {
                    $start = (float)($ammo['start'] ?? 10);
                    $max = (float)($ammo['max'] ?? 30);
                    if ($start > $max) {
                        $errors[] = 'ranged.ammo: start должен быть ≤ max.';
                    }
                }
            }
            $charge = $ranged['charge'] ?? null;
            if ($charge !== null && is_array($charge)) {
                $errors = array_merge($errors, self::num($charge['charge_ms'] ?? 800, 100, 3000, 'ranged.charge.charge_ms'));
                $errors = array_merge($errors, self::num($charge['damage_multiplier'] ?? 2.0, 1.0, 10.0, 'ranged.charge.damage_multiplier'));
            }
        }

        // stomp
        $stomp = $pa['stomp'] ?? null;
        if ($stomp !== null && !is_array($stomp)) {
            $errors[] = 'player_attacks.stomp: ожидается объект.';
        } elseif (is_array($stomp)) {
            $errors = array_merge($errors, self::num($stomp['damage'] ?? 1, 1, 99, 'stomp.damage'));
            $errors = array_merge($errors, self::num($stomp['stun_ms'] ?? 0, 0, 5000, 'stomp.stun_ms'));
            $errors = array_merge($errors, self::num($stomp['bounce_power'] ?? -3.5, -10.0, -1.0, 'stomp.bounce_power'));
        }

        // special
        $special = $pa['special'] ?? null;
        if ($special !== null && !is_array($special)) {
            $errors[] = 'player_attacks.special: ожидается объект.';
        } elseif (is_array($special)) {
            if (!in_array((string)($special['type'] ?? ''), self::SPECIAL_TYPES, true)) {
                $errors[] = 'special.type ∈ {screen_clear, time_stop, shield}.';
            }
            $errors = array_merge($errors, self::num($special['charges_max'] ?? 3, 1, 9, 'special.charges_max'));
            if (array_key_exists('freeze_duration_ms', $special)) {
                $errors = array_merge($errors, self::num($special['freeze_duration_ms'], 100, 10000, 'special.freeze_duration_ms'));
            }
            if (array_key_exists('shield_duration_ms', $special)) {
                $errors = array_merge($errors, self::num($special['shield_duration_ms'], 100, 10000, 'special.shield_duration_ms'));
            }
        }

        return $errors;
    }

    /**
     * player (§2.3): lives, health_max, invulnerability_ms, dodge, parry + существующие fuel-поля.
     * @param array<string,mixed> $p
     * @return list<string>
     */
    public static function validatePlayer(array $p): array
    {
        $errors = [];
        if (array_key_exists('lives', $p)) {
            $errors = array_merge($errors, self::num($p['lives'], 1, 9, 'player.lives'));
        }
        if (array_key_exists('health_max', $p)) {
            $errors = array_merge($errors, self::num($p['health_max'], 1, 20, 'player.health_max'));
        }
        if (array_key_exists('invulnerability_ms', $p)) {
            $errors = array_merge($errors, self::num($p['invulnerability_ms'], 0, 5000, 'player.invulnerability_ms'));
        }
        $dodge = $p['dodge'] ?? null;
        if ($dodge !== null && is_array($dodge)) {
            if (array_key_exists('duration_ms', $dodge)) {
                $errors = array_merge($errors, self::num($dodge['duration_ms'], 100, 1000, 'player.dodge.duration_ms'));
            }
            if (array_key_exists('invulnerability_ms', $dodge)) {
                $errors = array_merge($errors, self::num($dodge['invulnerability_ms'], 0, 1000, 'player.dodge.invulnerability_ms'));
            }
            if (array_key_exists('cooldown_ms', $dodge)) {
                $errors = array_merge($errors, self::num($dodge['cooldown_ms'], 100, 2000, 'player.dodge.cooldown_ms'));
            }
        }
        $parry = $p['parry'] ?? null;
        if ($parry !== null && is_array($parry)) {
            if (array_key_exists('window_ms', $parry)) {
                $errors = array_merge($errors, self::num($parry['window_ms'], 50, 500, 'player.parry.window_ms'));
            }
            if (array_key_exists('cooldown_ms', $parry)) {
                $errors = array_merge($errors, self::num($parry['cooldown_ms'], 100, 2000, 'player.parry.cooldown_ms'));
            }
        }
        // существующие поля блока 9 не трогаем, но диапазон проверим, если прислали
        if (array_key_exists('fuel_capacity', $p)) {
            $errors = array_merge($errors, self::num($p['fuel_capacity'], 0, 1000, 'player.fuel_capacity'));
        }
        if (array_key_exists('fuel_consumption_per_sec', $p)) {
            $errors = array_merge($errors, self::num($p['fuel_consumption_per_sec'], 0, 20, 'player.fuel_consumption_per_sec'));
        }
        return $errors;
    }

    /**
     * scoring (§2.6).
     * @param array<string,mixed> $s
     * @return list<string>
     */
    public static function validateScoring(array $s): array
    {
        $errors = [];
        if (array_key_exists('on_enemy_kill', $s)) {
            $errors = array_merge($errors, self::num($s['on_enemy_kill'], 0, 100000, 'scoring.on_enemy_kill'));
        }
        if (array_key_exists('on_pickup', $s)) {
            $errors = array_merge($errors, self::num($s['on_pickup'], 0, 1000, 'scoring.on_pickup'));
        }
        $combo = $s['combo'] ?? null;
        if ($combo !== null && is_array($combo)) {
            if (array_key_exists('window_ms', $combo)) {
                $errors = array_merge($errors, self::num($combo['window_ms'], 500, 10000, 'scoring.combo.window_ms'));
            }
            if (array_key_exists('multiplier_per_hit', $combo)) {
                $errors = array_merge($errors, self::num($combo['multiplier_per_hit'], 0.1, 2.0, 'scoring.combo.multiplier_per_hit'));
            }
            if (array_key_exists('max_multiplier', $combo)) {
                $errors = array_merge($errors, self::num($combo['max_multiplier'], 1.0, 20.0, 'scoring.combo.max_multiplier'));
            }
            $mph = $combo['multiplier_per_hit'] ?? null;
            $maxm = $combo['max_multiplier'] ?? null;
            if (is_numeric($mph) && is_numeric($maxm) && (float)$mph > (float)$maxm) {
                $errors[] = 'scoring.combo: multiplier_per_hit должен быть ≤ max_multiplier.';
            }
        }
        $tb = $s['time_bonus'] ?? null;
        if ($tb !== null && is_array($tb)) {
            if (array_key_exists('base', $tb)) {
                $errors = array_merge($errors, self::num($tb['base'], 0, 10000, 'scoring.time_bonus.base'));
            }
            if (array_key_exists('per_second_remaining', $tb)) {
                $errors = array_merge($errors, self::num($tb['per_second_remaining'], 0, 1000, 'scoring.time_bonus.per_second_remaining'));
            }
        }
        return $errors;
    }
}
