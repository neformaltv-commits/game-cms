<?php

declare(strict_types=1);

namespace Tests\Game;

use App\Audit\Logger;
use App\CategoryService;
use App\GameService;
use App\GenreService;
use App\Storage\FileStore;
use App\Validation\GameValidator;
use App\Validation\LegendValidator;
use App\Library\LibraryService;
use TestCase;

final class GameServiceTest extends TestCase
{
    private string $dir;
    private FileStore $store;
    private Logger $audit;
    private GenreService $genres;
    private GameService $games;

    protected function setUp(): void
    {
        $this->dir = $this->tmpDir('game');
        $this->store = new FileStore($this->dir);
        $this->audit = new Logger($this->store, 'audit.log', 14);
        $cfgDir = dirname(__DIR__, 2) . '/config';
        $this->genres = new GenreService($this->store, $this->audit, $cfgDir);
        $this->genres->seed(); // фикстуры жанров из config/genre-defaults (идемпотентно)
        $cats = new CategoryService($this->store, $this->audit);
        $ai = new LibraryService($this->store, $this->audit, LibraryService::KIND_AI, dirname(__DIR__, 2));
        $pat = new LibraryService($this->store, $this->audit, LibraryService::KIND_PATTERN, dirname(__DIR__, 2));
        $ai->seed();
        $pat->seed();
        $lv = new LegendValidator($ai, $pat);
        $gv = new GameValidator($this->genres, $cats, $lv, $this->store, $this->dir . '/media');
        $this->games = new GameService($this->store, $this->audit, $this->genres, $gv, $this->dir . '/media');
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

    public function testCreateInheritsGenreDefaults(): void
    {
        $game = $this->makeGame('inh-1');
        $this->assertSame('draft', $game['status']);
        $this->assertSame([], $game['levels']);
        $this->assertSame([], \App\Settings\SettingsService::toArray($game['engine_overrides']));
        $this->assertSame([], (array)$game['legend']);
        // controls/sounds наследуются из жанра (ТЗ блок 7 §4.2)
        $genre = $this->genres->find('genre-loderunner');
        $this->assertSame(
            (array)($genre['engine_config']['default_controls'] ?? []),
            (array)$game['controls']
        );
        $this->assertSame(
            (array)($genre['engine_config']['default_sounds'] ?? []),
            (array)$game['sounds']
        );
        $this->assertContains('game.create', $this->auditLines());
    }

    public function testNextIdSequentialAndUnique(): void
    {
        $g1 = $this->makeGame('seq-1');
        $g2 = $this->makeGame('seq-2');
        $this->assertSame('game-001', $g1['id']);
        $this->assertSame('game-002', $g2['id']);
    }

    public function testCreateRejectsUnknownGenreAndBadSlug(): void
    {
        $res = $this->games->create([
            'title' => 'X', 'description' => str_repeat('д', 30),
            'genre_id' => 'genre-nonexistent', 'slug' => 'ok-slug',
        ]);
        $this->assertFalse($res['ok']);
        $this->assertContains('Жанр не выбран или не существует.', $res['errors']);

        $res = $this->games->create([
            'title' => 'X', 'description' => str_repeat('д', 30),
            'genre_id' => 'genre-loderunner', 'slug' => 'A', // слишком короткий
        ]);
        $this->assertFalse($res['ok']);
    }

    public function testSlugUniquenessEnforced(): void
    {
        $this->makeGame('dup-slug');
        $res = $this->games->create([
            'title' => 'Вторая', 'description' => str_repeat('д', 30),
            'genre_id' => 'genre-loderunner', 'slug' => 'dup-slug',
        ]);
        $this->assertFalse($res['ok']);
        $this->assertTrue($res['errors'] !== [] && str_contains(implode(' ', $res['errors']), 'занят'));
    }

    public function testSetStatusPublishRequiresCoverAndLevels(): void
    {
        $game = $this->makeGame('pub-1');
        $errors = $this->games->setStatus($game['id'], 'published');
        $this->assertTrue($errors !== []);
        $this->assertContains('Публикация невозможна: обложка cover.webp не загружена.', $errors);
        $this->assertContains('Публикация невозможна: нужен хотя бы один уровень.', $errors);
        // Статус не изменился при ошибке
        $fresh = $this->games->find($game['id']);
        $this->assertSame('draft', $fresh['status']);
    }

    public function testSetStatusInvalidValue(): void
    {
        $game = $this->makeGame('pub-bad');
        $errors = $this->games->setStatus($game['id'], 'deleted');
        $this->assertSame(['Недопустимый статус.'], $errors);
    }

    public function testChangeGenreResetsControlsAndOverrides(): void
    {
        $game = $this->makeGame('chg-genre');
        // выставим кастомные overrides через save()
        $game['engine_overrides'] = ['physics' => ['gravity' => 5]];
        $game['legend'] = ['🧱' => ['role' => 'obstacle', 'name' => 'Кирпич', 'solid' => true]];
        $this->assertSame([], $this->games->save($game, false));

        $errors = $this->games->changeGenre($game['id'], 'genre-riverraid');
        $this->assertSame([], $errors);
        $fresh = $this->games->find($game['id']);
        $this->assertSame('genre-riverraid', $fresh['genre_id']);
        $newGenre = $this->genres->find('genre-riverraid');
        // controls/sounds перезаписаны дефолтами нового жанра, overrides очищен (ТЗ §4.4)
        $this->assertSame((array)($newGenre['engine_config']['default_controls'] ?? []), (array)$fresh['controls']);
        $this->assertSame([], \App\Settings\SettingsService::toArray($fresh['engine_overrides']));
        $this->assertContains('game.genre_change', $this->auditLines());
    }

    public function testEffectiveLegendMergesGenreAndGame(): void
    {
        $game = $this->makeGame('eff-leg');
        $game['legend'] = ['💰' => ['role' => 'pickup', 'name' => 'Алмаз', 'pickup_id' => 'gem', 'effect' => 'score', 'value' => 50]];
        $this->assertSame([], $this->games->save($game, false));
        $fresh = $this->games->find($game['id']);
        $eff = $this->games->effectiveLegend($fresh);
        // переопределение игры поверх жанра (ТЗ §3.1)
        $this->assertSame('Алмаз', $eff['💰']['name']);
        // остальные элементы жанра сохранены
        $this->assertSame('start', $eff['🏃']['role']);
        $this->assertSame('Кирпич', $eff['🧱']['name']);
    }

    public function testDeleteRemovesFileAndCountsByGenre(): void
    {
        $game = $this->makeGame('del-1');
        $this->assertSame(1, $this->games->countByGenre('genre-loderunner'));
        $this->assertSame([], $this->games->delete($game['id']));
        $this->assertNull($this->games->find($game['id']));
        $this->assertSame(0, $this->games->countByGenre('genre-loderunner'));
        $this->assertContains('game.delete', $this->auditLines());
        $this->assertSame(['Игра не найдена.'], $this->games->delete($game['id']));
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
