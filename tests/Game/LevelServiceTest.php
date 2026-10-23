<?php

declare(strict_types=1);

namespace Tests\Game;

use App\Audit\Logger;
use App\CategoryService;
use App\GameService;
use App\GenreService;
use App\LevelService;
use App\Storage\FileStore;
use App\Validation\GameValidator;
use App\Validation\LegendValidator;
use App\Library\LibraryService;
use TestCase;

final class LevelServiceTest extends TestCase
{
    private string $dir;
    private FileStore $store;
    private Logger $audit;
    private GenreService $genres;
    private GameService $games;
    private LevelService $levels;

    protected function setUp(): void
    {
        $this->dir = $this->tmpDir('level');
        $this->store = new FileStore($this->dir);
        $this->audit = new Logger($this->store, 'audit.log', 14);
        $cfgDir = dirname(__DIR__, 2) . '/config';
        $this->genres = new GenreService($this->store, $this->audit, $cfgDir);
        $this->genres->seed();
        $cats = new CategoryService($this->store, $this->audit);
        $ai = new LibraryService($this->store, $this->audit, LibraryService::KIND_AI, dirname(__DIR__, 2));
        $pat = new LibraryService($this->store, $this->audit, LibraryService::KIND_PATTERN, dirname(__DIR__, 2));
        $ai->seed();
        $pat->seed();
        $lv = new LegendValidator($ai, $pat);
        $gv = new GameValidator($this->genres, $cats, $lv, $this->store, $this->dir . '/media');
        $this->games = new GameService($this->store, $this->audit, $this->genres, $gv, $this->dir . '/media');
        $this->levels = new LevelService($this->store, $this->audit, $this->games, $this->genres, $lv);
    }

    private function makeGame(string $slug): array
    {
        $res = $this->games->create([
            'title' => 'Тест ' . $slug,
            'description' => str_repeat('д', 30),
            'genre_id' => 'genre-loderunner',
            'category_ids' => [],
            'slug' => $slug,
        ]);
        assert($res['ok'], implode('; ', $res['errors']));
        return $res['game'];
    }

    /** Сетка 32×24: пустота (⬜ бетон) + игрок и выход в заданных клетках. */
    private function buildGrid(int $startRow = 23, int $playerCol = 2, int $exitCol = 29): array
    {
        $grid = [];
        for ($r = 0; $r < LevelService::ROWS; $r++) {
            $row = array_fill(0, LevelService::COLS, '⬜');
            if ($r === $startRow) {
                $row[$playerCol] = '🏃';
                $row[$exitCol] = '🚪';
            }
            $grid[] = implode(' ', $row);
        }
        return $grid;
    }

    public function testCreateFillsBackgroundAndName(): void
    {
        $game = $this->makeGame('lvl-cr');
        $res = $this->levels->create($game['id']);
        $this->assertTrue($res['ok']);
        $lv = $res['level'];
        $this->assertSame('lvl-001', $lv['id']);
        $this->assertSame('Уровень 1', $lv['name']);
        $this->assertSame(32, $lv['width']);
        $this->assertSame(24, $lv['height']);
        $this->assertCount(24, $lv['grid']);
        // фон = первый background жанра или '.' — строки из одинаковых кластеров
        $cells = \App\Support\EmojiUtil::splitCells($lv['grid'][0]);
        $this->assertNotNull($cells, 'строка фонового уровня парсится как 32 кластера');
        $this->assertCount(32, $cells);
        // уровни видны в карточке игры
        $fresh = $this->games->find($game['id']);
        $this->assertCount(1, $fresh['levels']);
        $this->assertContains('level.create', $this->auditLines());
    }

    public function testSaveValidGridHappyPath(): void
    {
        $game = $this->makeGame('lvl-ok');
        $this->levels->create($game['id']);
        $level = [
            'id' => 'lvl-001',
            'name' => 'Первый',
            'width' => 32,
            'height' => 24,
            'grid' => $this->buildGrid(),
            'spawn' => null,
            'exit_emoji' => null,
            'time_limit_s' => 120,
        ];
        $errors = $this->levels->save($game['id'], $level);
        $this->assertSame([], $errors);
        $fresh = $this->games->find($game['id']);
        $this->assertSame('Первый', $fresh['levels'][0]['name']);
        $this->assertContains('level.save', $this->auditLines());
    }

    public function testSaveRejectsUnknownEmojiOutsideLegend(): void
    {
        $game = $this->makeGame('lvl-unk');
        $this->levels->create($game['id']);
        $grid = $this->buildGrid();
        // подставим эмодзи вне легенды в cell (5,5)
        $cells = \App\Support\EmojiUtil::splitCells($grid[5]);
        $cells[5] = '🦄';
        $grid[5] = implode(' ', $cells);
        $level = ['id' => 'lvl-001', 'name' => 'Плохой', 'width' => 32, 'height' => 24, 'grid' => $grid];
        $errors = $this->levels->save($game['id'], $level);
        $this->assertTrue($errors !== []);
        $this->assertTrue(str_contains(implode(' ', $errors), 'вне легенды'));
        // уровень не изменён
        $fresh = $this->games->find($game['id']);
        $this->assertSame('Уровень 1', $fresh['levels'][0]['name']);
    }

    public function testSaveRejectsMissingStartRole(): void
    {
        $game = $this->makeGame('lvl-nostart');
        $this->levels->create($game['id']);
        $grid = [];
        for ($r = 0; $r < 24; $r++) {
            $grid[] = implode(' ', array_fill(0, 32, '⬜'));
        }
        $level = ['id' => 'lvl-001', 'name' => 'Без старта', 'width' => 32, 'height' => 24, 'grid' => $grid];
        $errors = $this->levels->save($game['id'], $level);
        $this->assertTrue($errors !== []);
        $this->assertTrue(str_contains(implode(' ', $errors), 'стартов'));
    }

    public function testSaveRejectsWrongDimensions(): void
    {
        $game = $this->makeGame('lvl-dim');
        $this->levels->create($game['id']);
        $grid = $this->buildGrid();
        // строка с 31 ячейкой
        $grid[3] = implode(' ', array_slice(\App\Support\EmojiUtil::splitCells($grid[3]), 0, 31));
        $level = ['id' => 'lvl-001', 'name' => 'Кривой', 'width' => 32, 'height' => 24, 'grid' => $grid];
        $errors = $this->levels->save($game['id'], $level);
        $this->assertTrue($errors !== []);
        $this->assertTrue(str_contains(implode(' ', $errors), '31'));
        // слишком короткая сетка (23 строки)
        $level2 = ['id' => 'lvl-001', 'name' => 'Короткая', 'width' => 32, 'height' => 24, 'grid' => array_slice($this->buildGrid(), 0, 23)];
        $errors = $this->levels->save($game['id'], $level2);
        $this->assertTrue($errors !== []);
        $this->assertTrue(str_contains(implode(' ', $errors), '24 строк') || str_contains(implode(' ', $errors), '24'));
    }

    public function testDuplicateCreatesCopyWithNewId(): void
    {
        $game = $this->makeGame('lvl-dup');
        $this->levels->create($game['id']);
        $res = $this->levels->duplicate($game['id'], 'lvl-001');
        $this->assertTrue($res['ok']);
        $this->assertSame('lvl-002', $res['level']['id']);
        $this->assertSame('Уровень 1 (копия)', $res['level']['name']);
        $fresh = $this->games->find($game['id']);
        $this->assertCount(2, $fresh['levels']);
        $this->assertContains('level.duplicate', $this->auditLines());
        // несуществующий источник
        $bad = $this->levels->duplicate($game['id'], 'lvl-999');
        $this->assertFalse($bad['ok']);
    }

    public function testDeleteRules(): void
    {
        $game = $this->makeGame('lvl-del');
        $this->levels->create($game['id']);
        // последний удалить нельзя
        $errors = $this->levels->delete($game['id'], 'lvl-001');
        $this->assertSame(['Нельзя удалить последний уровень игры.'], $errors);
        // второй есть — первый удаляется
        $this->levels->duplicate($game['id'], 'lvl-001');
        $errors = $this->levels->delete($game['id'], 'lvl-001');
        $this->assertSame([], $errors);
        $fresh = $this->games->find($game['id']);
        $this->assertCount(1, $fresh['levels']);
        $this->assertSame('lvl-002', $fresh['levels'][0]['id']);
        $this->assertContains('level.delete', $this->auditLines());
    }

    public function testSaveRejectsUnknownLevelId(): void
    {
        $game = $this->makeGame('lvl-miss');
        $this->levels->create($game['id']);
        $level = ['id' => 'lvl-777', 'name' => 'Нет такого', 'width' => 32, 'height' => 24, 'grid' => $this->buildGrid()];
        $errors = $this->levels->save($game['id'], $level);
        $this->assertSame(['Уровень не найден.'], $errors);
    }

    /** @return list<string> */
    private function auditLines(): array
    {
        $raw = @file_get_contents($this->dir . '/audit.log') ?: '';
        $out = [];
        foreach (explode("\n", trim($raw)) as $line) {
            if ($line === '') {
                continue;
            }
            $j = json_decode($line, true);
            if (is_array($j)) {
                $out[] = (string)($j['action'] ?? '');
            }
        }
        return $out;
    }
}
