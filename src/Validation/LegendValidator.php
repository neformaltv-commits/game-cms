<?php

declare(strict_types=1);

namespace App\Validation;

use App\Library\LibraryService;
use App\Support\EmojiUtil;

/**
 * Валидация легенды игры (ТЗ блок 7 §3, §5.10).
 * Проверяет эффективную легенду = {...genre.default_legend, ...game.legend}.
 * Возвращает список ошибок; пустой массив — легенда валидна.
 */
final class LegendValidator
{
    public const ROLES = ['background', 'obstacle', 'collider', 'mob', 'pickup', 'exit', 'start', 'projectile', 'decor'];

    private const COMPOSITE_SIZES = [[1, 1], [1, 2], [2, 1], [2, 2], [3, 2], [2, 3]];

    public function __construct(
        private readonly LibraryService $aiModels,
        private readonly LibraryService $patterns,
    ) {
    }

    /**
     * @param array<string,mixed> $effectiveLegend emoji => element
     * @param string $engine движок жанра игры (для фильтрации моделей/паттернов и формаций)
     * @return list<string>
     */
    public function validate(array $effectiveLegend, string $engine): array
    {
        $errors = [];

        // Ключи — один графемный кластер-эмодзи (защита от HTML/XSS, ТЗ §9)
        foreach ($effectiveLegend as $emoji => $el) {
            if (!is_string($emoji) || !EmojiUtil::isSingleEmoji($emoji)) {
                $errors[] = sprintf('Элемент «%s»: ключ должен быть одним emoji-кластером.', is_string($emoji) ? $emoji : gettype($emoji));
            }
            if (!is_array($el)) {
                $errors[] = sprintf('Элемент «%s»: должен быть объектом.', (string)$emoji);
                unset($effectiveLegend[$emoji]);
            }
        }

        // Ровно один start
        $starts = array_keys(array_filter($effectiveLegend, static fn ($el) => ($el['role'] ?? '') === 'start'));
        if (count($starts) === 0) {
            $errors[] = 'В эффективной легенде должен быть ровно один элемент с ролью «start».';
        } elseif (count($starts) > 1) {
            $errors[] = 'В эффективной легенде только один «start», найдено: ' . implode(', ', $starts) . '.';
        }

        // Уникальные pickup_id
        $pickupIds = [];
        foreach ($effectiveLegend as $emoji => $el) {
            if (($el['role'] ?? '') === 'pickup') {
                $pid = (string)($el['pickup_id'] ?? '');
                if ($pid === '' || preg_match('/^[a-z0-9_]+$/', $pid) !== 1) {
                    $errors[] = sprintf(' Pickup «%s»: pickup_id должен соответствовать ^[a-z0-9_]+$.', (string)$emoji);
                } elseif (isset($pickupIds[$pid])) {
                    $errors[] = sprintf('pickup_id «%s» дублируется (%s и %s).', $pid, $pickupIds[$pid], (string)$emoji);
                } else {
                    $pickupIds[$pid] = (string)$emoji;
                }
            }
        }

        // Индексы для проверки ссылок actions
        $projectiles = array_keys(array_filter($effectiveLegend, static fn ($el) => ($el['role'] ?? '') === 'projectile'));
        $mobs = array_keys(array_filter($effectiveLegend, static fn ($el) => ($el['role'] ?? '') === 'mob'));

        // Якоря композитов уникальны среди якорей и 1×1-элементов; body не совпадает с чужим якорем
        $anchors = [];   // sprite => emoji элемента-владельца
        foreach ($effectiveLegend as $emoji => $el) {
            $comp = $el['composite'] ?? null;
            if (is_array($comp)) {
                $anchors[(string)$emoji] = (string)$emoji; // якорь = emoji элемента
            }
        }

        foreach ($effectiveLegend as $emoji => $el) {
            $role = (string)($el['role'] ?? '');
            if (!in_array($role, self::ROLES, true)) {
                $errors[] = sprintf('«%s»: неизвестная роль «%s».', (string)$emoji, $role);
                continue;
            }
            $name = (string)($el['name'] ?? '');
            if (mb_strlen($name) < 2 || mb_strlen($name) > 80) {
                $errors[] = sprintf('«%s»: name должен быть 2–80 символов.', (string)$emoji);
            }

            // Правила по ролям
            if ($role === 'background' && isset($el['walkable']) && $el['walkable'] !== true) {
                $errors[] = sprintf('«%s» (background): walkable обязательно true.', (string)$emoji);
            }
            if (($role === 'obstacle' || $role === 'collider') && isset($el['solid']) && $el['solid'] !== true) {
                $errors[] = sprintf('«%s» (%s): solid обязательно true.', (string)$emoji, $role);
            }
            $dot = $el['damage_on_touch'] ?? null;
            if ($dot !== null && (!is_numeric($dot) || $dot < 0 || $dot > 10)) {
                $errors[] = sprintf('«%s»: damage_on_touch ∈ [0, 10].', (string)$emoji);
            }

            if ($role === 'exit') {
                $mode = (string)($el['mode'] ?? 'touch');
                if (!in_array($mode, ['touch', 'after_all'], true)) {
                    $errors[] = sprintf('«%s» (exit): mode ∈ {touch, after_all}.', (string)$emoji);
                }
                foreach ((array)($el['requires'] ?? []) as $req) {
                    if (!isset($pickupIds[(string)$req])) {
                        $errors[] = sprintf('«%s» (exit): requires ссылается на несуществующий pickup_id «%s».', (string)$emoji, (string)$req);
                    }
                }
            }

            if ($role === 'mob') {
                $this->validateMob((string)$emoji, $el, $engine, $projectiles, $mobs, $pickupIds, $errors);
            }

            if ($role === 'projectile') {
                $this->numRange($el, 'speed', 0.5, 20, (string)$emoji, $errors);
                $this->numRange($el, 'damage', 1, 99, (string)$emoji, $errors);
                $this->numRange($el, 'lifetime_ms', 100, 10000, (string)$emoji, $errors);
                $this->numRange($el, 'bounces_max', 0, 10, (string)$emoji, $errors);
            }

            if (in_array($role, ['mob', 'collider'], true)) {
                $this->validateActions((string)$emoji, (array)($el['actions'] ?? []), $projectiles, $mobs, $pickupIds, $errors);
            }

            $this->validateComposite((string)$emoji, $el, $anchors, $errors);
        }

        return $errors;
    }

    /**
     * @param array<string,mixed> $el
     * @param list<string> $projectiles @param list<string> $mobs @param array<string,string> $pickupIds
     * @param list<string> $errors (по ссылке через возврат — накапливаем в массиве вызывающего)
     */
    private function validateMob(string $emoji, array $el, string $engine, array $projectiles, array $mobs, array $pickupIds, array &$errors): void
    {
        $this->numRange($el, 'hp', 1, 99, $emoji, $errors);
        $this->numRange($el, 'armor', 0, 10, $emoji, $errors);
        $this->numRange($el, 'knockback_resistance', 0.0, 1.0, $emoji, $errors);
        $this->numRange($el, 'stun_resistance', 0.0, 1.0, $emoji, $errors);

        $mode = (string)($el['behavior_mode'] ?? 'ai');
        if (!in_array($mode, ['ai', 'trajectory', 'hybrid'], true)) {
            $errors[] = sprintf('«%s» (mob): behavior_mode ∈ {ai, trajectory, hybrid}.', $emoji);
            return;
        }

        if ($mode === 'ai' || $mode === 'hybrid') {
            $modelId = $el['ai_model'] ?? null;
            if (!is_string($modelId) || $modelId === '') {
                $errors[] = sprintf('«%s»: при behavior_mode=%s обязана быть выбрана ai_model.', $emoji, $mode);
            } else {
                $model = $this->aiModels->find($modelId);
                if ($model === null) {
                    $errors[] = sprintf('«%s»: AI-модель «%s» не найдена в библиотеке.', $emoji, $modelId);
                } elseif (!in_array($engine, (array)($model['supported_engines'] ?? []), true)) {
                    $errors[] = sprintf('«%s»: AI-модель «%s» не поддерживает движок «%s».', $emoji, $modelId, $engine);
                } else {
                    $this->validateLibParams($emoji, 'ai_params', (array)($el['ai_params'] ?? []), (array)($model['params'] ?? []), $errors);
                }
            }
        }

        if ($mode === 'trajectory' || $mode === 'hybrid') {
            $patId = $el['movement_pattern'] ?? null;
            if (!is_string($patId) || $patId === '') {
                $errors[] = sprintf('«%s»: при behavior_mode=%s обязателен movement_pattern.', $emoji, $mode);
            } else {
                $pat = $this->patterns->find($patId);
                if ($pat === null) {
                    $errors[] = sprintf('«%s»: паттерн «%s» не найден в библиотеке.', $emoji, $patId);
                } elseif (!in_array($engine, (array)($pat['supported_engines'] ?? []), true)) {
                    $errors[] = sprintf('«%s»: паттерн «%s» не поддерживает движок «%s».', $emoji, $patId, $engine);
                } else {
                    $this->validateLibParams($emoji, 'movement_params', (array)($el['movement_params'] ?? []), (array)($pat['params'] ?? []), $errors);
                }
            }
        }

        if (isset($el['formation']) && is_array($el['formation'])) {
            if ($engine !== 'vertical-scroll') {
                $errors[] = sprintf('«%s»: formation допустима только для движка vertical-scroll.', $emoji);
            } else {
                $fType = (string)($el['formation']['type'] ?? '');
                $fRole = (string)($el['formation']['role'] ?? '');
                if (!in_array($fType, ['v', 'line', 'echelon'], true)) {
                    $errors[] = sprintf('«%s»: formation.type ∈ {v, line, echelon}.', $emoji);
                }
                if (!in_array($fRole, ['leader', 'follower'], true)) {
                    $errors[] = sprintf('«%s»: formation.role ∈ {leader, follower}.', $emoji);
                }
                $this->numRange($el['formation'], 'spacing', 1, 5, $emoji . ' formation', $errors);
            }
        }

        if (isset($el['weak_point']) && is_array($el['weak_point'])) {
            $wp = $el['weak_point'];
            foreach (['x', 'y'] as $axis) {
                $v = $wp['offset'][$axis] ?? 0;
                if (!is_numeric($v) || $v < -3 || $v > 3) {
                    $errors[] = sprintf('«%s»: weak_point.offset.%s ∈ [-3, 3].', $emoji, $axis);
                }
            }
            $this->numRange($wp, 'radius_tiles', 1, 3, $emoji . ' weak_point', $errors);
            $this->numRange($wp, 'damage_multiplier', 1.0, 10.0, $emoji . ' weak_point', $errors);
        }

        if (isset($el['shield_front']) && is_array($el['shield_front'])) {
            $sf = $el['shield_front'];
            $this->numRange($sf, 'direction_cone_deg', 30, 180, $emoji . ' shield_front', $errors);
            $this->numRange($sf, 'damage_multiplier', 0.0, 1.0, $emoji . ' shield_front', $errors);
        }

        if (isset($el['explosion_on_death']) && is_array($el['explosion_on_death'])) {
            $ex = $el['explosion_on_death'];
            $this->numRange($ex, 'radius', 1, 10, $emoji . ' explosion_on_death', $errors);
            $this->numRange($ex, 'damage', 1, 99, $emoji . ' explosion_on_death', $errors);
        }

        if (isset($el['drop_emoji']) && $el['drop_emoji'] !== null && !EmojiUtil::isSingleEmoji((string)$el['drop_emoji'])) {
            $errors[] = sprintf('«%s»: drop_emoji должен быть одним emoji-кластером.', $emoji);
        }
    }

    /**
     * @param list<string> $projectiles @param list<string> $mobs @param array<string,string> $pickupIds
     */
    private function validateActions(string $emoji, array $actions, array $projectiles, array $mobs, array $pickupIds, array &$errors): void
    {
        foreach ($actions as $i => $act) {
            if (!is_array($act)) {
                $errors[] = sprintf('«%s»: action[%d] должен быть объектом.', $emoji, $i);
                continue;
            }
            $type = (string)($act['type'] ?? '');
            switch ($type) {
                case 'shoot':
                    $p = (string)($act['projectile'] ?? '');
                    if (!in_array($p, $projectiles, true)) {
                        $errors[] = sprintf('«%s» action[%d] shoot: снаряд «%s» не найден среди projectile легенды.', $emoji, $i, $p);
                    }
                    $this->numRange($act, 'rate_ms', 50, 10000, sprintf('«%s» shoot', $emoji), $errors);
                    $dir = (string)($act['direction'] ?? 'toward_player');
                    if (!in_array($dir, ['toward_player', 'fixed'], true)) {
                        $errors[] = sprintf('«%s» shoot: direction ∈ {toward_player, fixed}.', $emoji);
                    } elseif ($dir === 'fixed') {
                        $dx = (int)($act['vector']['dx'] ?? 0);
                        $dy = (int)($act['vector']['dy'] ?? 0);
                        if (max(abs($dx), abs($dy)) > 1 || ($dx === 0 && $dy === 0)) {
                            $errors[] = sprintf('«%s» shoot(fixed): vector.dx/dy ∈ {-1,0,1} и не оба нуль.', $emoji);
                        }
                    }
                    break;
                case 'melee':
                    $this->numRange($act, 'damage', 1, 99, sprintf('«%s» melee', $emoji), $errors);
                    $this->numRange($act, 'cooldown_ms', 50, 2000, sprintf('«%s» melee', $emoji), $errors);
                    break;
                case 'spawn':
                    $se = (string)($act['spawn_emoji'] ?? '');
                    if (!in_array($se, $mobs, true)) {
                        $errors[] = sprintf('«%s» action[%d] spawn: «%s» не является mob легенды.', $emoji, $i, $se);
                    }
                    $this->numRange($act, 'rate_ms', 100, 10000, sprintf('«%s» spawn', $emoji), $errors);
                    $this->numRange($act, 'max_alive', 1, 20, sprintf('«%s» spawn', $emoji), $errors);
                    if (array_key_exists('spawn_radius', $act)) {
                        $this->numRange($act, 'spawn_radius', 0, 10, sprintf('«%s» spawn', $emoji), $errors);
                    }
                    break;
                case 'open':
                    $rid = (string)($act['requires_id'] ?? '');
                    if (!isset($pickupIds[$rid])) {
                        $errors[] = sprintf('«%s» open: requires_id «%s» не найден среди pickup_id.', $emoji, $rid);
                    }
                    break;
                case 'heal':
                    $this->numRange($act, 'amount', 1, 99, sprintf('«%s» heal', $emoji), $errors);
                    $this->numRange($act, 'cooldown_ms', 50, 2000, sprintf('«%s» heal', $emoji), $errors);
                    break;
                case 'damage_area':
                    $this->numRange($act, 'damage', 1, 99, sprintf('«%s» damage_area', $emoji), $errors);
                    $this->numRange($act, 'radius', 1, 10, sprintf('«%s» damage_area', $emoji), $errors);
                    $this->numRange($act, 'cooldown_ms', 50, 2000, sprintf('«%s» damage_area', $emoji), $errors);
                    break;
                case 'teleport':
                    if (!isset($act['to']['col']) || !isset($act['to']['row'])) {
                        $errors[] = sprintf('«%s» teleport: нужны to.col и to.row.', $emoji);
                    }
                    break;
                case 'cast':
                    $eff = (string)($act['effect'] ?? '');
                    if (!in_array($eff, ['freeze', 'shield', 'heal', 'teleport'], true)) {
                        $errors[] = sprintf('«%s» cast: effect ∈ {freeze, shield, heal, teleport}.', $emoji);
                    }
                    break;
                case 'throw':
                    $p = (string)($act['projectile'] ?? '');
                    if (!in_array($p, $projectiles, true)) {
                        $errors[] = sprintf('«%s» throw: снаряд «%s» не найден среди projectile легенды.', $emoji, $p);
                    }
                    $this->numRange($act, 'power', 0.5, 10.0, sprintf('«%s» throw', $emoji), $errors);
                    break;
                case 'freeze':
                    $this->numRange($act, 'duration_ms', 100, 10000, sprintf('«%s» freeze', $emoji), $errors);
                    $this->numRange($act, 'radius', 1, 10, sprintf('«%s» freeze', $emoji), $errors);
                    break;
                case 'push':
                    $this->numRange($act, 'radius', 1, 5, sprintf('«%s» push', $emoji), $errors);
                    if (!in_array((string)($act['direction'] ?? ''), ['away', 'toward'], true)) {
                        $errors[] = sprintf('«%s» push: direction ∈ {away, toward}.', $emoji);
                    }
                    break;
                case 'use':
                case 'on_touch':
                    if (trim((string)($act['effect'] ?? '')) === '') {
                        $errors[] = sprintf('«%s» %s: effect обязателен.', $emoji, $type);
                    }
                    break;
                case 'on_stomp':
                    $this->numRange($act, 'damage', 1, 99, sprintf('«%s» on_stomp', $emoji), $errors);
                    $this->numRange($act, 'stun_ms', 0, 5000, sprintf('«%s» on_stomp', $emoji), $errors);
                    $morph = $act['morph_to'] ?? null;
                    if ($morph !== null && !in_array((string)$morph, $mobs, true)) {
                        $errors[] = sprintf('«%s» on_stomp: morph_to «%s» не является mob легенды.', $emoji, (string)$morph);
                    }
                    break;
                default:
                    $errors[] = sprintf('«%s» action[%d]: неизвестный тип «%s».', $emoji, $i, $type);
            }
        }
    }

    /** @param array<string,mixed> $el */
    private function validateComposite(string $emoji, array $el, array $anchors, array &$errors): void
    {
        $comp = $el['composite'] ?? null;
        if ($comp === null) {
            return;
        }
        if (!is_array($comp)) {
            $errors[] = sprintf('«%s»: composite должен быть объектом.', $emoji);
            return;
        }
        $w = (int)($comp['width'] ?? 0);
        $h = (int)($comp['height'] ?? 0);
        if (!in_array([$w, $h], self::COMPOSITE_SIZES, true)) {
            $errors[] = sprintf('«%s»: размер композита %d×%d недопустим (разрешены 1×1, 1×2, 2×1, 2×2, 3×2, 2×3).', $emoji, $w, $h);
            return;
        }
        $sprites = $comp['sprites'] ?? null;
        if (!is_array($sprites) || count($sprites) !== $h) {
            $errors[] = sprintf('«%s»: composite.sprites должен быть матрицей %d×%d.', $emoji, $h, $w);
            return;
        }
        foreach ($sprites as $r => $row) {
            if (!is_array($row) || count($row) !== $w) {
                $errors[] = sprintf('«%s»: composite.sprites[%d] должен содержать %d спрайтов.', $emoji, $r, $w);
                return;
            }
            foreach ($row as $c => $sprite) {
                if (!is_string($sprite) || !EmojiUtil::isSingleEmoji($sprite)) {
                    $errors[] = sprintf('«%s»: composite.sprites[%d][%d] должен быть одним emoji-кластером.', $emoji, $r, $c);
                    return;
                }
            }
        }
        // Якорь = emoji элемента
        if ((string)($sprites[0][0]) !== $emoji) {
            $errors[] = sprintf('«%s»: якорь composite.sprites[0][0] должен совпадать с emoji элемента.', $emoji);
        }
        // Body-спрайты не должны совпадать с якорем ДРУГОГО элемента
        for ($r = 0; $r < $h; $r++) {
            for ($c = 0; $c < $w; $c++) {
                if ($r === 0 && $c === 0) {
                    continue;
                }
                $sp = (string)$sprites[$r][$c];
                if (isset($anchors[$sp]) && $anchors[$sp] !== $emoji) {
                    $errors[] = sprintf('«%s»: body-спрайт «%s» совпадает с якорем элемента «%s».', $emoji, $sp, $anchors[$sp]);
                }
            }
        }
    }

    /**
     * Проверка params по схеме params[] библиотеки.
     * @param array<string,mixed> $params @param list<array<string,mixed>> $schema
     */
    private function validateLibParams(string $emoji, string $field, array $params, array $schema, array &$errors): void
    {
        $keys = array_column($schema, 'key');
        foreach (array_keys($params) as $k) {
            if (!in_array((string)$k, $keys, true)) {
                $errors[] = sprintf('«%s»: %s содержит неизвестный параметр «%s».', $emoji, $field, (string)$k);
            }
        }
        foreach ($schema as $p) {
            $key = (string)($p['key'] ?? '');
            if (!array_key_exists($key, $params)) {
                continue; // недостающие заполняются дефолтами на этапе движка
            }
            $v = $params[$key];
            switch ((string)($p['type'] ?? '')) {
                case 'number':
                    if (!is_numeric($v)) {
                        $errors[] = sprintf('«%s»: %s.%s должен быть числом.', $emoji, $field, $key);
                    } elseif ((isset($p['min']) && $v < $p['min']) || (isset($p['max']) && $v > $p['max'])) {
                        $errors[] = sprintf('«%s»: %s.%s вне диапазона [%s, %s].', $emoji, $field, $key, (string)($p['min'] ?? '-∞'), (string)($p['max'] ?? '+∞'));
                    }
                    break;
                case 'boolean':
                    if (!is_bool($v)) {
                        $errors[] = sprintf('«%s»: %s.%s должен быть булевым.', $emoji, $field, $key);
                    }
                    break;
                case 'enum':
                    if (!in_array($v, (array)($p['options'] ?? []), true)) {
                        $errors[] = sprintf('«%s»: %s.%s должен быть одним из: %s.', $emoji, $field, $key, implode(', ', array_map('strval', (array)($p['options'] ?? []))));
                    }
                    break;
                case 'string':
                    if (!is_string($v)) {
                        $errors[] = sprintf('«%s»: %s.%s должен быть строкой.', $emoji, $field, $key);
                    }
                    break;
            }
        }
    }

    /** @param array<string,mixed> $arr */
    private function numRange(array $arr, string $key, float $min, float $max, string $ctx, array &$errors): void
    {
        if (!array_key_exists($key, $arr) || $arr[$key] === null) {
            return;
        }
        $v = $arr[$key];
        if (!is_numeric($v) || $v < $min || $v > $max) {
            $errors[] = sprintf('%s: %s ∈ [%s, %s].', $ctx, $key, rtrim(rtrim(number_format($min, 1, '.', ''), '0'), '.'), rtrim(rtrim(number_format($max, 1, '.', ''), '0'), '.'));
        }
    }
}
