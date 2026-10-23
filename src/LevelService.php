<?php

declare(strict_types=1);

namespace App;

use App\Audit\Logger;
use App\Storage\FileStore;
use App\Support\EmojiUtil;
use App\Validation\LegendValidator;
use App\Validation\TriggerValidator;

/**
 * Уровни игры: сетка 32×24 (ТЗ блок 8).
 * Хранение: data/games/{game-id}.json => levels[] (вложенно в карточку игры,
 * запись через FileStore::mutate под flock — атомарно).
 */
final class LevelService
{
    public const COLS = 32;
    public const ROWS = 24;

    public function __construct(
        private readonly FileStore $store,
        private readonly Logger $audit,
        private readonly GameService $games,
        private readonly GenreService $genres,
        private readonly LegendValidator $legendValidator,
    ) {
    }

    /** @return list<array<string,mixed>>|null уровни игры или null если игры нет */
    public function levelsOf(string $gameId): ?array
    {
        $game = $this->games->find($gameId);
        if ($game === null) {
            return null;
        }
        return array_values((array)$game['levels']);
    }

    /**
     * Создание пустого уровня (заполнен background-эмодзи жанра либо '.'),
     * имя «Уровень N». id уровня: lvl-{NNN} внутри игры.
     * @return array{ok:bool,errors:list<string>,level:?array<string,mixed>}
     */
    public function create(string $gameId): array
    {
        $result = ['ok' => false, 'errors' => [], 'level' => null];
        $this->mutateLevels($gameId, function (array &$levels, array $game) use (&$result): array {
            $genre = $this->genres->find((string)$game['genre_id']);
            $fill = $this->backgroundFill($genre, $game);
            $n = count($levels) + 1;
            $level = [
                'id' => $this->nextLevelId($levels),
                'name' => 'Уровень ' . $n,
                'width' => self::COLS,
                'height' => self::ROWS,
                'grid' => array_fill(0, self::ROWS, str_repeat($fill . ' ', self::COLS - 1) . $fill),
                'spawn' => null,
                'exit_emoji' => null,
                'time_limit_s' => null,
                'created_at' => gmdate('Y-m-d\TH:i:s\Z'),
            ];
            // Пустые клетки («.») не входят в легенду — добавляем их как background-элемент
            // самой игры (ТЗ §3.1: legend игры переопределяет/дополняет легенду жанра).
            $legendPatch = [];
            if ($fill === '.' && !array_key_exists('.', (array)$game['legend'])) {
                $legendPatch['.'] = ['role' => 'background', 'name' => 'Пусто'];
            }
            $levels[] = $level;
            $result = ['ok' => true, 'errors' => [], 'level' => $level];
            return ['ok' => true, 'legend_patch' => $legendPatch];
        });
        if ($result['ok']) {
            $this->audit->log('level.create', $gameId, ['level_id' => $result['level']['id']]);
        }
        return $result;
    }

    /**
     * Клон уровня (копия grid и параметров, новый id, суффикс « (копия)»).
     * @return array{ok:bool,errors:list<string>,level:?array<string,mixed>}
     */
    public function duplicate(string $gameId, string $levelId): array
    {
        $result = ['ok' => false, 'errors' => ['Уровень не найден.'], 'level' => null];
        $this->mutateLevels($gameId, function (array &$levels) use ($levelId, &$result): bool {
            foreach ($levels as $i => $lv) {
                if (($lv['id'] ?? '') === $levelId) {
                    $copy = $lv;
                    $copy['id'] = $this->nextLevelId($levels);
                    $copy['name'] = ((string)($lv['name'] ?? 'Уровень')) . ' (копия)';
                    $copy['created_at'] = gmdate('Y-m-d\TH:i:s\Z');
                    $levels[] = $copy;
                    $result = ['ok' => true, 'errors' => [], 'level' => $copy];
                    return true;
                }
            }
            return false;
        });
        if ($result['ok']) {
            $this->audit->log('level.duplicate', $gameId, ['source' => $levelId, 'new' => $result['level']['id']]);
        }
        return $result;
    }

    /**
     * Сохранение уровня с полной валидацией сетки по легенде (ТЗ §8.2).
     * @param array<string,mixed> $level уровень из формы/API
     * @return list<string> ошибки; пусто — сохранено
     */
    public function save(string $gameId, array $level): array
    {
        $game = $this->games->find($gameId);
        if ($game === null) {
            return ['Игра не найдена.'];
        }
        $genre = $this->genres->find((string)$game['genre_id']);
        $engine = (string)($genre['engine'] ?? '');
        $legend = $this->games->effectiveLegend($game);
        $errors = $this->validateGrid($level, $legend, $engine);
        if ($errors !== []) {
            return $errors;
        }

        $saved = null;
        $found = false;
        $this->mutateLevels($gameId, function (array &$levels) use ($level, &$saved, &$found): bool {
            for ($i = 0; $i < count($levels); $i++) {
                if (($levels[$i]['id'] ?? '') === ($level['id'] ?? '')) {
                    $level['updated_at'] = gmdate('Y-m-d\TH:i:s\Z');
                    $levels[$i] = $level;
                    $saved = $level;
                    $found = true;
                    return true;
                }
            }
            return false;
        });
        if (!$found) {
            return ['Уровень не найден.'];
        }
        $this->audit->log('level.save', $gameId, ['level_id' => (string)($level['id'] ?? '')]);
        return [];
    }

    /**
     * Удаление уровня; нельзя удалить последний.
     * @return list<string> ошибки
     */
    public function delete(string $gameId, string $levelId): array
    {
        $error = null;
        $this->mutateLevels($gameId, function (array &$levels) use ($levelId, &$error): bool {
            $idx = null;
            foreach ($levels as $i => $lv) {
                if (($lv['id'] ?? '') === $levelId) {
                    $idx = $i;
                    break;
                }
            }
            if ($idx === null) {
                $error = 'Уровень не найден.';
                return false;
            }
            if (count($levels) <= 1) {
                $error = 'Нельзя удалить последний уровень игры.';
                return false;
            }
            array_splice($levels, $idx, 1);
            return true;
        });
        if ($error !== null) {
            return [$error];
        }
        $this->audit->log('level.delete', $gameId, ['level_id' => $levelId]);
        return [];
    }

    /**
     * Полная валидация уровня: размеры, emoji-кластеры, ссылки на легенду, spawn/exit.
     * @param array<string,mixed> $level @param array<string,mixed> $legend
     * @return list<string>
     */
    public function validateGrid(array $level, array $legend, string $engine): array
    {
        $errors = [];

        if (($level['width'] ?? null) !== self::COLS || ($level['height'] ?? null) !== self::ROWS) {
            $errors[] = sprintf('Размер уровня должен быть %d×%d.', self::COLS, self::ROWS);
        }
        $name = trim((string)($level['name'] ?? ''));
        if (mb_strlen($name) < 1 || mb_strlen($name) > 60) {
            $errors[] = 'name уровня: 1–60 символов.';
        }
        $tl = $level['time_limit_s'] ?? null;
        if ($tl !== null && (!is_numeric($tl) || (int)$tl < 0 || (int)$tl > 9999)) {
            $errors[] = 'time_limit_s: целое 0–9999 или null.';
        }
        // level.params (блок 10 §2.7–§2.11): триггеры и условия победы/поражения.
        // Отсутствие params = старые игры работают как раньше (§10.9-62).
        $params = $level['params'] ?? [];
        if (!is_array($params)) {
            $errors[] = 'params: ожидается объект.';
            $params = [];
        }
        $errors = array_merge($errors, TriggerValidator::validate($params, $legend));

        $grid = $level['grid'] ?? null;
        if (!is_array($grid) || count($grid) !== self::ROWS) {
            $errors[] = sprintf('grid должен содержать ровно %d строк.', self::ROWS);
            return $errors;
        }

        // Легенда должна быть валидна до проверки сетки (роли элементов)
        foreach ($this->legendValidator->validate($legend, $engine) as $e) {
            $errors[] = 'Легенда: ' . $e;
        }

        $roles = [];
        foreach ($legend as $emoji => $el) {
            $roles[(string)$emoji] = (string)($el['role'] ?? '');
        }

        $spawnFound = false;
        $usedUnknown = [];
        foreach ($grid as $r => $row) {
            if (!is_string($row)) {
                $errors[] = sprintf('grid[%d]: строка должна быть текстом.', (int)$r);
                continue;
            }
            $cells = EmojiUtil::splitCells($row);
            if ($cells === null) {
                $errors[] = sprintf('grid[%d]: все ячейки должны быть одиночными emoji-кластерами.', (int)$r);
                continue;
            }
            if (count($cells) !== self::COLS) {
                $errors[] = sprintf('grid[%d]: ожидалось %d ячеек, найдено %d.', (int)$r, self::COLS, count($cells));
                continue;
            }
            foreach ($cells as $c => $cell) {
                if (!isset($roles[$cell])) {
                    $usedUnknown[$cell] = true;
                } elseif ($roles[$cell] === 'start') {
                    $spawnFound = true;
                }
            }
        }
        if ($usedUnknown !== []) {
            $errors[] = 'В сетке есть эмодзи вне легенды: ' . implode(' ', array_slice(array_keys($usedUnknown), 0, 10)) . '.';
        }

        // Проверка композитов: каждая непустая ячейка-компонент должна быть покрыта
        // соответствующей матрицей композита (якорь сверху-слева).
        $matrix = $this->gridMatrix($grid);
        if ($matrix !== null) {
            $errors = array_merge($errors, $this->validateComposites($matrix, $legend));
            if (!$spawnFound) {
                $spawn = $level['spawn'] ?? null;
                if (is_array($spawn) && isset($spawn['col'], $spawn['row'])) {
                    $cell = $matrix[(int)$spawn['row']][(int)$spawn['col']] ?? null;
                    if ($cell !== null && ($roles[$cell] ?? '') === 'start') {
                        $spawnFound = true;
                    }
                }
                if (!$spawnFound) {
                    $errors[] = 'На уровне нет стартовой позиции (эмодзи роли «start» или spawn-указателя на неё).';
                }
            }
        }

        return $errors;
    }

    /**
     * @param list<mixed> $grid
     * @return list<list<string>>|null матрица кластеров или null при битых строках
     */
    private function gridMatrix(array $grid): ?array
    {
        $m = [];
        foreach ($grid as $row) {
            if (!is_string($row)) {
                return null;
            }
            $cells = EmojiUtil::splitCells($row);
            if ($cells === null || count($cells) !== self::COLS) {
                return null;
            }
            $m[] = $cells;
        }
        return count($m) === self::ROWS ? $m : null;
    }

    /**
     * Композиты: для каждого элемента legend.composite проверяем, что все его
     * body-спрайты стоят ровно вокруг якоря (offset row/col от якоря).
     * @param list<list<string>> $matrix @param array<string,mixed> $legend
     * @return list<string>
     */
    private function validateComposites(array $matrix, array $legend): array
    {
        $errors = [];
        $covered = []; // "r,c" => emoji якоря, уже объяснённый композитом
        foreach ($legend as $emoji => $el) {
            $comp = is_array($el) ? ($el['composite'] ?? null) : null;
            if (!is_array($comp)) {
                continue;
            }
            $sprites = (array)($comp['sprites'] ?? []);
            $h = (int)($comp['height'] ?? count($sprites));
            $w = (int)($comp['width'] ?? 0);
            // Ищем якорь в сетке и проверяем каждое вхождение
            for ($r = 0; $r < self::ROWS; $r++) {
                for ($c = 0; $c < self::COLS; $c++) {
                    if ($matrix[$r][$c] !== (string)$emoji) {
                        continue;
                    }
                    $key = $r . ',' . $c;
                    if (isset($covered[$key])) {
                        continue; // этот экземпляр уже часть другого композита
                    }
                    $ok = true;
                    for ($dr = 0; $dr < $h && $ok; $dr++) {
                        for ($dc = 0; $dc < $w; $dc++) {
                            $want = (string)($sprites[$dr][$dc] ?? '');
                            $rr = $r + $dr;
                            $cc = $c + $dc;
                            $got = ($rr < self::ROWS && $cc < self::COLS) ? $matrix[$rr][$cc] : null;
                            if ($want === '' || $got !== $want) {
                                $ok = false;
                                break;
                            }
                        }
                    }
                    if (!$ok) {
                        $errors[] = sprintf('«%s»: композит %d×%d в позиции (%d,%d) не полностью размещён на сетке.', (string)$emoji, $w, $h, $c, $r);
                    } else {
                        for ($dr = 0; $dr < $h; $dr++) {
                            for ($dc = 0; $dc < $w; $dc++) {
                                $covered[($r + $dr) . ',' . ($c + $dc)] = (string)$emoji;
                            }
                        }
                    }
                }
            }
        }
        return $errors;
    }

    /**
     * CRUD триггеров уровня (ТЗ блок 10 §4.4, §6.6). Все мутации — через save-путь:
     * полная валидация уровня (TriggerValidator) выполняется до записи; при ошибке
     * данные не изменяются (§7.2 «при ошибке — блокировка сохранения»).
     * @return array{ok:bool,errors:list<string>,trigger:?array<string,mixed>}
     */
    public function createTrigger(string $gameId, string $levelId, array $input): array
    {
        $result = ['ok' => false, 'errors' => ['Уровень не найден.'], 'trigger' => null];
        $this->mutateLevels($gameId, function (array &$levels, array $game) use ($levelId, $input, &$result): bool {
            $idx = $this->indexOfLevel($levels, $levelId);
            if ($idx === null) {
                return false;
            }
            $level = $levels[$idx];
            $params = (array)($level['params'] ?? []);
            $triggers = (array)($params['triggers'] ?? []);
            $trigger = self::normalizeTrigger($input, $this->nextTriggerId($triggers));
            $errors = TriggerValidator::validate(
                array_merge($params, ['triggers' => array_merge($triggers, [$trigger])]),
                $this->games->effectiveLegend($game),
            );
            if ($errors !== []) {
                $result = ['ok' => false, 'errors' => $errors, 'trigger' => null];
                return false;
            }
            $triggers[] = $trigger;
            $params['triggers'] = $triggers;
            $level['params'] = $params;
            $levels[$idx] = $level;
            $result = ['ok' => true, 'errors' => [], 'trigger' => $trigger];
            return true;
        });
        if ($result['ok']) {
            $t = (array)$result['trigger'];
            $this->audit->log('level.trigger_create', $gameId, [
                'level_id' => $levelId,
                'trigger_id' => (string)($t['id'] ?? ''),
                'when_type' => (string)(is_array($t['when'] ?? null) ? ($t['when']['type'] ?? '') : ''),
                'then_types' => array_column(array_map(static fn ($a) => (array)$a, (array)($t['then'] ?? [])), 'type'),
            ]);
        }
        return $result;
    }

    /**
     * Обновление триггера целиком (по id). @return array{ok:bool,errors:list<string>,trigger:?array}
     */
    public function updateTrigger(string $gameId, string $levelId, string $triggerId, array $input): array
    {
        $result = ['ok' => false, 'errors' => ['Триггер не найден.'], 'trigger' => null];
        $changed = [];
        $this->mutateLevels($gameId, function (array &$levels, array $game) use ($levelId, $triggerId, $input, &$result, &$changed): bool {
            $idx = $this->indexOfLevel($levels, $levelId);
            if ($idx === null) {
                $result = ['ok' => false, 'errors' => ['Уровень не найден.'], 'trigger' => null];
                return false;
            }
            $level = $levels[$idx];
            $params = (array)($level['params'] ?? []);
            $triggers = (array)($params['triggers'] ?? []);
            $pos = null;
            foreach ($triggers as $i => $t) {
                if (is_array($t) && (string)($t['id'] ?? '') === $triggerId) {
                    $pos = $i;
                    break;
                }
            }
            if ($pos === null) {
                return false;
            }
            $old = (array)$triggers[$pos];
            $trigger = self::normalizeTrigger($input, $triggerId);
            $trial = $triggers;
            $trial[$pos] = $trigger;
            $errors = TriggerValidator::validate(
                array_merge($params, ['triggers' => $trial]),
                $this->games->effectiveLegend($game),
            );
            if ($errors !== []) {
                $result = ['ok' => false, 'errors' => $errors, 'trigger' => null];
                return false;
            }
            foreach (array_unique(array_merge(array_keys($old), array_keys($trigger))) as $k) {
                if (json_encode($old[$k] ?? null) !== json_encode($trigger[$k] ?? null)) {
                    $changed[] = (string)$k;
                }
            }
            $triggers[$pos] = $trigger;
            $params['triggers'] = $triggers;
            $level['params'] = $params;
            $levels[$idx] = $level;
            $result = ['ok' => true, 'errors' => [], 'trigger' => $trigger];
            return true;
        });
        if ($result['ok']) {
            $this->audit->log('level.trigger_update', $gameId, [
                'level_id' => $levelId, 'trigger_id' => $triggerId, 'changed' => $changed,
            ]);
        }
        return $result;
    }

    /** @return list<string> ошибки */
    public function deleteTrigger(string $gameId, string $levelId, string $triggerId): array
    {
        $error = null;
        $found = false;
        $this->mutateLevels($gameId, function (array &$levels) use ($levelId, $triggerId, &$error, &$found): bool {
            $idx = $this->indexOfLevel($levels, $levelId);
            if ($idx === null) {
                $error = 'Уровень не найден.';
                return false;
            }
            $level = $levels[$idx];
            $params = (array)($level['params'] ?? []);
            $triggers = (array)($params['triggers'] ?? []);
            $kept = array_values(array_filter($triggers, static fn ($t) => is_array($t) && (string)($t['id'] ?? '') !== $triggerId));
            if (count($kept) === count($triggers)) {
                $error = 'Триггер не найден.';
                return false;
            }
            $found = true;
            $params['triggers'] = $kept;
            $level['params'] = $params;
            $levels[$idx] = $level;
            return true;
        });
        if ($error !== null) {
            return [$error];
        }
        $this->audit->log('level.trigger_delete', $gameId, ['level_id' => $levelId, 'trigger_id' => $triggerId]);
        return [];
    }

    /**
     * Сохранение условий победы/поражения уровня (§2.10–§2.11). Пустые массивы = снятие всех условий.
     * @param array<string,mixed> $conditions ['victory_conditions' => list, 'defeat_conditions' => list]
     * @return list<string> ошибки
     */
    public function saveWinLoseConditions(string $gameId, string $levelId, array $conditions): array
    {
        $game = $this->games->find($gameId);
        if ($game === null) {
            return ['Игра не найдена.'];
        }
        $legend = $this->games->effectiveLegend($game);
        $errorsOut = [];
        $saved = false;
        $this->mutateLevels($gameId, function (array &$levels) use ($levelId, $conditions, $legend, &$errorsOut, &$saved): bool {
            $idx = $this->indexOfLevel($levels, $levelId);
            if ($idx === null) {
                $errorsOut = ['Уровень не найден.'];
                return false;
            }
            $level = $levels[$idx];
            $params = (array)($level['params'] ?? []);
            $trial = array_merge($params, [
                'victory_conditions' => (array)($conditions['victory_conditions'] ?? []),
                'defeat_conditions' => (array)($conditions['defeat_conditions'] ?? []),
            ]);
            $errors = TriggerValidator::validate($trial, $legend);
            if ($errors !== []) {
                $errorsOut = $errors;
                return false;
            }
            $level['params'] = $trial;
            $levels[$idx] = $level;
            $saved = true;
            return true;
        });
        if (!$saved) {
            return $errorsOut;
        }
        $this->audit->log('level.winlose_conditions_update', $gameId, [
            'level_id' => $levelId,
            'victory' => array_column((array)($conditions['victory_conditions'] ?? []), 'type'),
            'defeat' => array_column((array)($conditions['defeat_conditions'] ?? []), 'type'),
        ]);
        return [];
    }

    /** Следующий свободный trig-{NNN} в списке. @param list<mixed> $triggers */
    private function nextTriggerId(array $triggers): string
    {
        $max = 0;
        foreach ($triggers as $t) {
            if (is_array($t) && preg_match('/^trig-(\d+)$/', (string)($t['id'] ?? ''), $m)) {
                $max = max($max, (int)$m[1]);
            }
        }
        return sprintf('trig-%03d', $max + 1);
    }

    /** @param list<array<string,mixed>> $levels @return int|null */
    private function indexOfLevel(array $levels, string $levelId): ?int
    {
        foreach ($levels as $i => $lv) {
            if (($lv['id'] ?? '') === $levelId) {
                return $i;
            }
        }
        return null;
    }

    /**
     * Нормализация формы триггера → схема §2.7 (enum-валидация типов на следующем шаге).
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public static function normalizeTrigger(array $input, string $id): array
    {
        $t = [
            'id' => $id,
            'name' => trim((string)($input['name'] ?? '')),
            'enabled' => array_key_exists('enabled', $input)
                ? (filter_var($input['enabled'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true)
                : true,
            'once' => array_key_exists('once', $input)
                ? (filter_var($input['once'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true)
                : true,
            'when' => self::normalizeWhen((array)($input['when'] ?? [])),
            'then' => self::normalizeThenList((array)($input['then'] ?? [])),
        ];
        return $t;
    }

    /** @param array<string,mixed> $when @return array<string,mixed> */
    private static function normalizeWhen(array $when): array
    {
        $type = (string)($when['type'] ?? '');
        $out = ['type' => $type];
        $intFields = ['count', 'value', 'seconds', 'col', 'row', 'width', 'height'];
        foreach ($intFields as $f) {
            if (array_key_exists($f, $when) && is_numeric($when[$f])) {
                $out[$f] = (int)$when[$f];
            }
        }
        foreach (['target_emoji', 'pickup_id', 'emoji', 'flag_name', 'compare'] as $f) {
            $v = trim((string)($when[$f] ?? ''));
            if ($v !== '') {
                $out[$f] = $v;
            }
        }
        if ($type === 'all_of' || $type === 'any_of') {
            $subs = [];
            foreach ((array)($when['triggers'] ?? []) as $sub) {
                if (is_array($sub)) {
                    $subs[] = self::normalizeWhen($sub);
                }
            }
            $out['triggers'] = $subs;
        }
        return $out;
    }

    /** @param list<mixed> $list @return list<array<string,mixed>> */
    private static function normalizeThenList(array $list): array
    {
        $out = [];
        foreach ($list as $act) {
            if (!is_array($act)) {
                continue;
            }
            $type = (string)($act['type'] ?? '');
            $a = ['type' => $type];
            foreach (['col', 'row', 'count', 'duration_ms', 'amount', 'delta', 'x', 'y'] as $f) {
                if (array_key_exists($f, $act) && is_numeric($act[$f])) {
                    $a[$f] = (int)$act[$f];
                }
            }
            foreach (['emoji', 'level_id', 'text', 'event', 'flag_name', 'stat', 'direction'] as $f) {
                if (array_key_exists($f, $act)) {
                    $v = (string)$act[$f];
                    if ($v !== '' || in_array($f, ['text'], true)) {
                        $a[$f] = $v;
                    }
                }
            }
            // координаты spawn/set_tile приходят как col/row; zone teleport — x/y (совместимо с col/row)
            $out[] = $a;
        }
        return $out;
    }

    /** Фоновый emoji для заполнения: первый background из default_legend жанра, иначе '.' */
    private function backgroundFill(?array $genre, array $game = []): string
    {
        // 1) фон уже задан в легенде самой игры — берём первый background-элемент;
        foreach ((array)($game['legend'] ?? []) as $emoji => $el) {
            if (is_array($el) && ($el['role'] ?? '') === 'background') {
                return (string)$emoji;
            }
        }
        // 2) иначе — первый background из легенды жанра;
        foreach ((array)($genre['default_legend'] ?? []) as $emoji => $el) {
            if (is_array($el) && ($el['role'] ?? '') === 'background') {
                return (string)$emoji;
            }
        }
        // 3) фона нет нигде — пустая клетка «.».
        return '.';
    }

    /** @param list<array<string,mixed>> $levels */
    private function nextLevelId(array $levels): string
    {
        $max = 0;
        foreach ($levels as $lv) {
            if (preg_match('/^lvl-(\d+)$/', (string)($lv['id'] ?? ''), $m)) {
                $max = max($max, (int)$m[1]);
            }
        }
        return sprintf('lvl-%03d', $max + 1);
    }

    /**
     * Атомарная мутация levels[] карточки игры под flock.
     * @param callable(list<array<string,mixed>>&, array<string,mixed>):bool $fn
     */
    private function mutateLevels(string $gameId, callable $fn): void
    {
        $this->store->mutate('games/' . $gameId . '.json', function (mixed &$game) use ($fn): mixed {
            if (!is_array($game)) {
                return false;
            }
            $levels = array_values((array)($game['levels'] ?? []));
            // Мутатор может вернуть bool или ['ok' => bool, 'legend_patch' => ?array] —
            // levels изменяются только при ok=true, legend_patch применяется к игре.
            $patchLegend = null;
            $res = $fn($levels, $game);
            if (is_array($res)) {
                if (($res['ok'] ?? false) !== true) {
                    return false;
                }
                $patchLegend = $res['legend_patch'] ?? null;
            } elseif ($res === false) {
                return false;
            }
            $game['levels'] = $levels;
            if (is_array($patchLegend) && $patchLegend !== []) {
                $game['legend'] = array_merge((array)($game['legend'] ?? []), $patchLegend);
            }
            $game['updated_at'] = gmdate('Y-m-d\TH:i:s\Z');
            return true;
        });
    }
}
