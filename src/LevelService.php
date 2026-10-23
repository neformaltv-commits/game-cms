<?php

declare(strict_types=1);

namespace App;

use App\Audit\Logger;
use App\Storage\FileStore;
use App\Support\EmojiUtil;
use App\Validation\LegendValidator;

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
        $this->mutateLevels($gameId, function (array &$levels, array $game) use (&$result): bool {
            $genre = $this->genres->find((string)$game['genre_id']);
            $fill = $this->backgroundFill($genre);
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
            if ($fill === '.' && !array_key_exists('.', (array)$game['legend'])) {
                $legend = (array)$game['legend'];
                $legend['.'] = ['role' => 'background', 'name' => 'Пусто'];
                $game['legend'] = $legend;
            }
            $levels[] = $level;
            $result = ['ok' => true, 'errors' => [], 'level' => $level];
            return true;
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

    /** Фоновый emoji для заполнения: первый background из default_legend жанра, иначе '.' */
    private function backgroundFill(?array $genre): string
    {
        foreach ((array)($genre['default_legend'] ?? []) as $emoji => $el) {
            if (is_array($el) && ($el['role'] ?? '') === 'background') {
                return (string)$emoji;
            }
        }
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
        $this->store->mutate('games/' . $gameId . '.json', static function (mixed &$game) use ($fn): mixed {
            if (!is_array($game)) {
                return false;
            }
            $levels = array_values((array)($game['levels'] ?? []));
            if ($fn($levels, $game) === false) {
                return false;
            }
            $game['levels'] = $levels;
            $game['updated_at'] = gmdate('Y-m-d\TH:i:s\Z');
            return true;
        });
    }
}
