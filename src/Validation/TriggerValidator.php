<?php

declare(strict_types=1);

namespace App\Validation;

use App\LevelService;
use App\Support\EmojiUtil;

/**
 * Валидация триггеров и условий победы/поражения уровня (ТЗ блок 10 §2.7–§2.11, §4.4).
 * Чистая статическая функция: вход — params уровня + эффективная легенда, выход — список ошибок.
 */
final class TriggerValidator
{
    public const WHEN_TYPES = [
        'kill_count', 'kill_all', 'pickup_count', 'pickup_all', 'player_hp', 'player_lives',
        'score', 'time_elapsed', 'player_in_zone', 'player_touch_emoji', 'flag_set',
        'all_of', 'any_of',
    ]; // 13 типов (§2.8)

    public const THEN_TYPES = [
        'open_exit', 'close_exit', 'spawn', 'despawn', 'set_tile', 'show_message', 'play_sound',
        'set_flag', 'clear_flag', 'change_player_stat', 'teleport_player', 'damage_player',
        'damage_all_mobs', 'victory', 'defeat',
    ]; // 15 типов (§2.9)

    public const COMPARES = ['gte', 'gt', 'eq', 'lt', 'lte'];
    public const VICTORY_TYPES = ['reach_exit', 'kill_all', 'collect_all', 'score_reached', 'time_under', 'flag_set'];
    public const DEFEAT_TYPES = ['hp_zero', 'lives_zero', 'time_limit_exceeded', 'player_fell', 'flag_set'];

    private const FLAG_RE = '/^[a-z][a-z0-9_]{1,30}$/';
    private const TRIG_ID_RE = '/^trig-\d{3,}$/';

    /**
     * @param array<string,mixed> $params level.params (triggers, victory_conditions, defeat_conditions)
     * @param array<string,mixed> $legend эффективная легенда игры
     * @return list<string>
     */
    public static function validate(array $params, array $legend): array
    {
        $errors = [];

        $mobEmojis = self::emojisByRole($legend, 'mob');
        $pickupIds = [];
        foreach ($legend as $emoji => $el) {
            if (is_array($el) && ($el['role'] ?? '') === 'pickup') {
                $pid = (string)($el['pickup_id'] ?? '');
                if ($pid !== '') {
                    $pickupIds[$pid] = true;
                }
            }
        }
        $allEmojis = [];
        foreach (array_keys($legend) as $k) {
            $allEmojis[(string)$k] = true;
        }

        // --- triggers[] ---
        $triggers = $params['triggers'] ?? [];
        if ($triggers !== [] && !is_array($triggers)) {
            return ['triggers: ожидается массив.'];
        }
        $triggers = (array)$triggers;
        if (count($triggers) > 50) {
            $errors[] = 'triggers: максимум 50 триггеров.';
        }
        $seenIds = [];
        foreach ($triggers as $i => $t) {
            $ctx = sprintf('triggers[%d]', (int)$i);
            if (!is_array($t)) {
                $errors[] = $ctx . ': должен быть объектом.';
                continue;
            }
            $id = (string)($t['id'] ?? '');
            if (preg_match(self::TRIG_ID_RE, $id) !== 1) {
                $errors[] = sprintf('%s: id должен соответствовать ^trig-\\d{3,}$.', $ctx);
            } elseif (isset($seenIds[$id])) {
                $errors[] = sprintf('trigger id «%s» дублируется.', $id);
            } else {
                $seenIds[$id] = true;
            }
            $name = (string)($t['name'] ?? '');
            if (mb_strlen($name) < 2 || mb_strlen($name) > 80) {
                $errors[] = sprintf('%s (%s): name 2–80 символов.', $ctx, $id);
            }
            if (array_key_exists('enabled', $t) && !is_bool($t['enabled'])) {
                $errors[] = sprintf('%s: enabled должен быть булевым.', $ctx);
            }
            if (array_key_exists('once', $t) && !is_bool($t['once'])) {
                $errors[] = sprintf('%s: once должен быть булевым.', $ctx);
            }

            $when = $t['when'] ?? null;
            if (!is_array($when)) {
                $errors[] = sprintf('%s: when должен быть объектом.', $ctx);
            } else {
                $errors = array_merge($errors, self::validateWhen($when, $ctx . '.when', 1, $mobEmojis, $pickupIds, $allEmojis));
            }

            $then = $t['then'] ?? null;
            if (!is_array($then) || count($then) < 1 || count($then) > 10) {
                $errors[] = sprintf('%s: then — массив из 1–10 действий.', $ctx);
            } else {
                foreach ($then as $j => $act) {
                    $errors = array_merge($errors, self::validateThenAction((array)$act, sprintf('%s.then[%d]', $ctx, (int)$j), $allEmojis, $mobEmojis, $pickupIds));
                }
            }
        }

        // --- victory_conditions[] ---
        $vc = $params['victory_conditions'] ?? [];
        if ($vc !== [] && !is_array($vc)) {
            $errors[] = 'victory_conditions: ожидается массив.';
            $vc = [];
        }
        foreach ((array)$vc as $i => $c) {
            $ctx = sprintf('victory_conditions[%d]', (int)$i);
            if (!is_array($c)) {
                $errors[] = $ctx . ': должен быть объектом.';
                continue;
            }
            $type = (string)($c['type'] ?? '');
            if (!in_array($type, self::VICTORY_TYPES, true)) {
                $errors[] = sprintf('%s: type ∈ {%s}.', $ctx, implode(', ', self::VICTORY_TYPES));
                continue;
            }
            if ($type === 'kill_all' && array_key_exists('target_emoji', $c)) {
                $errors = array_merge($errors, self::checkMobEmoji((string)$c['target_emoji'], $ctx, $mobEmojis));
            }
            if ($type === 'collect_all' && !isset($pickupIds[(string)($c['pickup_id'] ?? '')])) {
                $errors[] = sprintf('%s: pickup_id «%s» не найден в легенде.', $ctx, (string)($c['pickup_id'] ?? ''));
            }
            if ($type === 'score_reached') {
                $v = $c['value'] ?? null;
                if (!is_numeric($v) || (float)$v < 0 || (float)$v > 100000) {
                    $errors[] = sprintf('%s: value 0–100000.', $ctx);
                }
            }
            if ($type === 'time_under') {
                $v = $c['seconds'] ?? null;
                if (!is_numeric($v) || (float)$v < 1 || (float)$v > 3600) {
                    $errors[] = sprintf('%s: seconds 1–3600.', $ctx);
                }
            }
            if ($type === 'flag_set') {
                $errors = array_merge($errors, self::checkFlag((string)($c['flag_name'] ?? ''), $ctx));
            }
        }

        // --- defeat_conditions[] ---
        $dc = $params['defeat_conditions'] ?? [];
        if ($dc !== [] && !is_array($dc)) {
            $errors[] = 'defeat_conditions: ожидается массив.';
            $dc = [];
        }
        foreach ((array)$dc as $i => $c) {
            $ctx = sprintf('defeat_conditions[%d]', (int)$i);
            if (!is_array($c)) {
                $errors[] = $ctx . ': должен быть объектом.';
                continue;
            }
            $type = (string)($c['type'] ?? '');
            if (!in_array($type, self::DEFEAT_TYPES, true)) {
                $errors[] = sprintf('%s: type ∈ {%s}.', $ctx, implode(', ', self::DEFEAT_TYPES));
                continue;
            }
            if ($type === 'flag_set') {
                $errors = array_merge($errors, self::checkFlag((string)($c['flag_name'] ?? ''), $ctx));
            }
        }

        return $errors;
    }

    /**
     * @param array<string,mixed> $when
     * @param list<string> $mobEmojis @param array<string,true> $pickupIds @param array<string,true> $allEmojis
     * @return list<string>
     */
    private static function validateWhen(array $when, string $ctx, int $depth, array $mobEmojis, array $pickupIds, array $allEmojis): array
    {
        $errors = [];
        $type = (string)($when['type'] ?? '');
        if (!in_array($type, self::WHEN_TYPES, true)) {
            return [sprintf('%s: неизвестный тип условия «%s».', $ctx, $type)];
        }

        switch ($type) {
            case 'kill_count':
                $errors = array_merge($errors, self::checkMobEmoji((string)($when['target_emoji'] ?? ''), $ctx, $mobEmojis));
                $errors = array_merge($errors, self::checkCompare($when['compare'] ?? 'gte', $ctx));
                $errors = array_merge($errors, self::checkInt($when['count'] ?? null, 0, 9999, $ctx . '.count'));
                break;
            case 'kill_all':
                if (array_key_exists('target_emoji', $when) && $when['target_emoji'] !== null && $when['target_emoji'] !== '') {
                    $errors = array_merge($errors, self::checkMobEmoji((string)$when['target_emoji'], $ctx, $mobEmojis));
                }
                break;
            case 'pickup_count':
                if (!isset($pickupIds[(string)($when['pickup_id'] ?? '')])) {
                    $errors[] = sprintf('%s: pickup_id «%s» не найден в легенде.', $ctx, (string)($when['pickup_id'] ?? ''));
                }
                $errors = array_merge($errors, self::checkCompare($when['compare'] ?? 'gte', $ctx));
                $errors = array_merge($errors, self::checkInt($when['count'] ?? null, 0, 9999, $ctx . '.count'));
                break;
            case 'pickup_all':
                if (!isset($pickupIds[(string)($when['pickup_id'] ?? '')])) {
                    $errors[] = sprintf('%s: pickup_id «%s» не найден в легенде.', $ctx, (string)($when['pickup_id'] ?? ''));
                }
                break;
            case 'player_hp':
            case 'player_lives':
            case 'score':
                $errors = array_merge($errors, self::checkCompare($when['compare'] ?? 'gte', $ctx));
                $errors = array_merge($errors, self::checkInt($when['value'] ?? null, 0, 100000, $ctx . '.value'));
                break;
            case 'time_elapsed':
                $errors = array_merge($errors, self::checkInt($when['seconds'] ?? null, 1, 9999, $ctx . '.seconds'));
                break;
            case 'player_in_zone':
                $col = (int)($when['col'] ?? -1);
                $row = (int)($when['row'] ?? -1);
                $w = (int)($when['width'] ?? 0);
                $h = (int)($when['height'] ?? 0);
                if ($col < 0 || $col >= LevelService::COLS || $row < 0 || $row >= LevelService::ROWS) {
                    $errors[] = sprintf('%s: col/row вне сетки %d×%d.', $ctx, LevelService::COLS, LevelService::ROWS);
                }
                if ($w < 1 || $h < 1 || $col + $w > LevelService::COLS || $row + $h > LevelService::ROWS) {
                    $errors[] = sprintf('%s: зона выходит за границы сетки.', $ctx);
                }
                break;
            case 'player_touch_emoji':
                $e = (string)($when['emoji'] ?? '');
                if (!isset($allEmojis[$e])) {
                    $errors[] = sprintf('%s: emoji «%s» не найден в легенде.', $ctx, $e);
                }
                break;
            case 'flag_set':
                $errors = array_merge($errors, self::checkFlag((string)($when['flag_name'] ?? ''), $ctx));
                break;
            case 'all_of':
            case 'any_of':
                $subs = $when['triggers'] ?? [];
                if (!is_array($subs) || count($subs) < 1 || count($subs) > 5) {
                    $errors[] = sprintf('%s: %s.triggers — массив из 1–5 вложенных условий.', $ctx, $type);
                } elseif ($depth >= 2) {
                    $errors[] = sprintf('%s: вложенность all_of/any_of ограничена глубиной 2.', $ctx);
                } else {
                    foreach ((array)$subs as $k => $sub) {
                        if (!is_array($sub)) {
                            $errors[] = sprintf('%s.triggers[%d]: должен быть объектом.', $ctx, (int)$k);
                            continue;
                        }
                        $errors = array_merge($errors, self::validateWhen($sub, sprintf('%s.triggers[%d]', $ctx, (int)$k), $depth + 1, $mobEmojis, $pickupIds, $allEmojis));
                    }
                }
                break;
        }
        return $errors;
    }

    /**
     * @param array<string,mixed> $act
     * @param list<string> $mobEmojis @param array<string,true> $allEmojis @param array<string,true> $pickupIds
     * @return list<string>
     */
    private static function validateThenAction(array $act, string $ctx, array $allEmojis, array $mobEmojis, array $pickupIds): array
    {
        $errors = [];
        $type = (string)($act['type'] ?? '');
        if (!in_array($type, self::THEN_TYPES, true)) {
            return [sprintf('%s: неизвестный тип действия «%s».', $ctx, $type)];
        }
        switch ($type) {
            case 'open_exit':
            case 'close_exit':
                break; // level_id опционален
            case 'spawn':
                $e = (string)($act['emoji'] ?? '');
                if (!isset($allEmojis[$e])) {
                    $errors[] = sprintf('%s: emoji «%s» не найден в легенде.', $ctx, $e);
                }
                $errors = array_merge($errors, self::checkCell($act['col'] ?? null, $act['row'] ?? null, $ctx));
                if (array_key_exists('count', $act)) {
                    $errors = array_merge($errors, self::checkInt($act['count'], 1, 99, $ctx . '.count'));
                }
                break;
            case 'despawn':
                $e = (string)($act['emoji'] ?? '');
                if (!isset($allEmojis[$e])) {
                    $errors[] = sprintf('%s: emoji «%s» не найден в легенде.', $ctx, $e);
                }
                break;
            case 'set_tile':
                $e = (string)($act['emoji'] ?? '');
                if (!isset($allEmojis[$e])) {
                    $errors[] = sprintf('%s: emoji «%s» не найден в легенде.', $ctx, $e);
                }
                $errors = array_merge($errors, self::checkCell($act['col'] ?? null, $act['row'] ?? null, $ctx));
                break;
            case 'show_message':
                $text = (string)($act['text'] ?? '');
                if (mb_strlen($text) < 1 || mb_strlen($text) > 200) {
                    $errors[] = sprintf('%s: text 1–200 символов.', $ctx);
                }
                $d = $act['duration_ms'] ?? 2000;
                if (!is_numeric($d) || (float)$d < 500 || (float)$d > 10000) {
                    $errors[] = sprintf('%s: duration_ms 500–10000.', $ctx);
                }
                break;
            case 'play_sound':
                if (!in_array((string)($act['event'] ?? ''), SettingsValidator::SOUND_EVENTS, true)) {
                    $errors[] = sprintf('%s: event должен быть одним из 10 звуковых событий.', $ctx);
                }
                break;
            case 'set_flag':
            case 'clear_flag':
                $errors = array_merge($errors, self::checkFlag((string)($act['flag_name'] ?? ''), $ctx));
                break;
            case 'change_player_stat':
                if (!in_array((string)($act['stat'] ?? ''), \App\Validation\CombatValidator::STATS, true)) {
                    $errors[] = sprintf('%s: stat ∈ {hp, score, lives}.', $ctx);
                }
                $delta = $act['delta'] ?? null;
                if (!is_int($delta) && !(is_numeric($delta) && (float)$delta === floor((float)$delta))) {
                    $errors[] = sprintf('%s: delta — целое −99…99.', $ctx);
                } elseif ((float)$delta < -99 || (float)$delta > 99) {
                    $errors[] = sprintf('%s: delta — целое −99…99.', $ctx);
                }
                break;
            case 'teleport_player':
                $errors = array_merge($errors, self::checkCell($act['col'] ?? null, $act['row'] ?? null, $ctx));
                break;
            case 'damage_player':
                $errors = array_merge($errors, self::checkInt($act['amount'] ?? null, 1, 99, $ctx . '.amount'));
                break;
            case 'damage_all_mobs':
                if (array_key_exists('emoji', $act) && $act['emoji'] !== null && $act['emoji'] !== '') {
                    $errors = array_merge($errors, self::checkMobEmoji((string)$act['emoji'], $ctx, $mobEmojis));
                }
                $errors = array_merge($errors, self::checkInt($act['amount'] ?? null, 1, 99, $ctx . '.amount'));
                break;
            case 'victory':
            case 'defeat':
                break;
        }
        return $errors;
    }

    /** @param list<string> $mobEmojis @return list<string> */
    private static function checkMobEmoji(string $emoji, string $ctx, array $mobEmojis): array
    {
        if ($emoji === '' || !in_array($emoji, $mobEmojis, true)) {
            return [sprintf('%s: target_emoji «%s» не является mob легенды.', $ctx, $emoji)];
        }
        return [];
    }

    /** @return list<string> */
    private static function checkFlag(string $flag, string $ctx): array
    {
        if (preg_match(self::FLAG_RE, $flag) !== 1) {
            return [sprintf('%s: flag_name должен соответствовать ^[a-z][a-z0-9_]{1,30}$.', $ctx)];
        }
        return [];
    }

    /** @return list<string> */
    private static function checkCompare(mixed $cmp, string $ctx): array
    {
        if (!in_array((string)$cmp, self::COMPARES, true)) {
            return [sprintf('%s: compare ∈ {gte, gt, eq, lt, lte}.', $ctx)];
        }
        return [];
    }

    /** @return list<string> */
    private static function checkInt(mixed $v, float $min, float $max, string $ctx): array
    {
        if (!is_numeric($v) || (float)$v < $min || (float)$v > $max) {
            return [sprintf('%s: значение вне диапазона %s–%s.', $ctx, rtrim(rtrim(number_format($min, 1, '.', ''), '0'), '.'), rtrim(rtrim(number_format($max, 1, '.', ''), '0'), '.'))];
        }
        return [];
    }

    /** @return list<string> */
    private static function checkCell(mixed $col, mixed $row, string $ctx): array
    {
        if (!is_numeric($col) || !is_numeric($row)
            || (int)$col < 0 || (int)$col >= LevelService::COLS
            || (int)$row < 0 || (int)$row >= LevelService::ROWS) {
            return [sprintf('%s: col/row вне сетки %d×%d.', $ctx, LevelService::COLS, LevelService::ROWS)];
        }
        return [];
    }

    /** @param array<string,mixed> $legend @return list<string> */
    private static function emojisByRole(array $legend, string $role): array
    {
        $out = [];
        foreach ($legend as $emoji => $el) {
            if (is_array($el) && ($el['role'] ?? '') === $role) {
                $out[] = (string)$emoji;
            }
        }
        return $out;
    }
}
