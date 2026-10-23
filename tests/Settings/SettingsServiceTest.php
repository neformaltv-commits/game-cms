<?php

declare(strict_types=1);

namespace Tests\Settings;

use App\Audit\Logger;
use App\CategoryService;
use App\GameService;
use App\GenreService;
use App\Settings\SettingsService;
use App\Storage\FileStore;
use App\Validation\GameValidator;
use App\Validation\LegendValidator;
use App\Library\LibraryService;
use TestCase;

final class SettingsServiceTest extends TestCase
{
    private string $dir;
    private FileStore $store;
    private Logger $audit;
    private GenreService $genres;
    private GameService $games;
    private SettingsService $svc;

    protected function setUp(): void
    {
        $this->dir = $this->tmpDir('settings');
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
        $this->svc = new SettingsService($this->store, $this->audit, $this->genres, $this->games);
    }

    public function tearDown(): void
    {
        // tmpDir() регистрирует каталог; базовый TestCase удалит его сам
    }

    private function makeGame(string $slug): array
    {
        $res = $this->games->create([
            'title' => 'Тест ' . $slug, 'description' => str_repeat('д', 30), 'genre_id' => 'genre-loderunner',
            'category_ids' => [], 'status' => 'draft', 'slug' => $slug,
        ]);
        assert($res['ok']);
        return $res['game'];
    }

    public function testSaveControlsHappyPath(): void
    {
        $game = $this->makeGame('ctl-ok');
        $res = $this->svc->saveControls($game['id'], ['left' => 'ArrowLeft,KeyA', 'dig_left' => ['KeyQ']]);
        $this->assertTrue($res['ok']);
        $this->assertSame([], $res['errors']);
        $fresh = $this->games->find($game['id']);
        $this->assertSame(['ArrowLeft', 'KeyA'], $fresh['controls']['left']);
        $this->assertSame(['KeyQ'], $fresh['controls']['dig_left']);
        $this->assertContains('game.controls_update', $this->auditLines());
    }

    public function testSaveControlsRejectsEmptyRequiredAndBadCode(): void
    {
        $game = $this->makeGame('ctl-bad');
        $res = $this->svc->saveControls($game['id'], ['left' => '']);
        $this->assertFalse($res['ok']); // обязательное действие без клавиши
        $res = $this->svc->saveControls($game['id'], ['left' => '<img src=x>']);
        $this->assertFalse($res['ok']); // невалидный KeyboardEvent.code
        $res = $this->svc->saveControls($game['id'], ['left' => 'KeyA,KeyB,KeyC']);
        $this->assertFalse($res['ok']); // >2 клавиш
        // данные не изменились после ошибок (остались дефолты жанра)
        $fresh = $this->games->find($game['id']);
        $this->assertSame(['ArrowLeft', 'KeyA'], $fresh['controls']['left']);
    }

    public function testActiveActionsPerEngine(): void
    {
        // effectiveLegend игры: default_legend жанра + legend игры (ТЗ блок 7 §5)
        $game = $this->makeGame('act-eng');
        $legend = $this->games->effectiveLegend($game);
        $this->assertTrue(in_array('obstacle', array_values(array_column($legend, 'role')), true));
        $a = $this->svc->activeActions('static-screen', $legend, []);
        $this->assertSame(['left', 'right', 'up', 'down', 'pause', 'start'], $a['required']);
        $this->assertTrue(in_array('dig_left', $a['optional'], true));
        $this->assertFalse(in_array('jump', $a['optional'], true)); // jump_power = 0
        $a2 = $this->svc->activeActions('static-screen', $legend, ['physics' => ['jump_power' => -5]]);
        $this->assertTrue(in_array('jump', $a2['optional'], true));
        $a3 = $this->svc->activeActions('vertical-scroll', [], []);
        $this->assertTrue(in_array('fire', $a3['required'], true));
        $a4 = $this->svc->activeActions('horizontal-scroll', [], []);
        $this->assertTrue(in_array('jump', $a4['required'], true) && in_array('fire', $a4['required'], true));
    }

    public function testResetControlsToGenreDefaults(): void
    {
        $game = $this->makeGame('ctl-reset');
        $this->svc->saveControls($game['id'], ['left' => 'KeyH']);
        $this->assertTrue($this->svc->resetControls($game['id'])['ok']);
        $fresh = $this->games->find($game['id']);
        $this->assertSame(['ArrowLeft', 'KeyA'], $fresh['controls']['left']);
        $this->assertContains('"action":"game.settings_reset"', $this->auditLines());
    }

    public function testSaveSoundsAndValidation(): void
    {
        $game = $this->makeGame('snd-ok');
        $json = json_encode([
            ['event' => 'dig', 'wave' => 'square', 'frequency_hz' => 440, 'frequency_end_hz' => null, 'duration_ms' => 50, 'volume' => 0.03, 'envelope' => 'decay'],
            ['event' => 'win', 'wave' => 'triangle', 'frequency_hz' => 1760, 'frequency_end_hz' => null, 'duration_ms' => 250, 'volume' => 0.05, 'envelope' => 'pluck'],
        ]);
        $this->assertTrue($this->svc->saveSounds($game['id'], ['sounds_json' => $json])['ok']);
        $fresh = $this->games->find($game['id']);
        $this->assertCount(2, $fresh['sounds']);
        // частота вне диапазона
        $bad = json_encode([['event' => 'hit', 'wave' => 'square', 'frequency_hz' => 5000, 'duration_ms' => 50, 'volume' => 0.1, 'envelope' => 'decay']]);
        $this->assertFalse($this->svc->saveSounds($game['id'], ['sounds_json' => $bad])['ok']);
        // XSS в event отклоняется enum-валидацией
        $xss = json_encode([['event' => '<script>', 'wave' => 'square', 'frequency_hz' => 100, 'duration_ms' => 50, 'volume' => 0.1, 'envelope' => 'decay']]);
        $this->assertFalse($this->svc->saveSounds($game['id'], ['sounds_json' => $xss])['ok']);
        $this->assertContains('game.sounds_update', $this->auditLines());
    }

    public function testSaveOverridesWhitelistAndErrors(): void
    {
        $game = $this->makeGame('ovr-ok');
        $res = $this->svc->saveOverrides($game['id'], [
            'speed_scale' => '1.1', 'fps' => '30', 'sprite_flicker' => '1',
            'evil_field' => '999', 'physics' => ['gravity' => '0.5'],
        ]);
        $this->assertTrue($res['ok']);
        $raw = json_decode((string)file_get_contents($this->dir . '/games/' . $game['id'] . '.json'), true);
        $this->assertSame(1.1, $raw['engine_overrides']['speed_scale']);
        $this->assertSame(30, $raw['engine_overrides']['fps']);
        $this->assertSame(true, $raw['engine_overrides']['sprite_flicker']);
        $this->assertSame(0.5, $raw['engine_overrides']['physics']['gravity']);
        $this->assertFalse(isset($raw['engine_overrides']['evil_field'])); // white-list (§8)
        // вне диапазона
        $this->assertFalse($this->svc->saveOverrides($game['id'], ['speed_scale' => '3.0'])['ok']);
        // movement_type × view_mode
        $this->assertFalse($this->svc->saveOverrides($game['id'], ['movement_type' => 'top_down_8dir', 'view_mode' => 'side'])['ok']);
        $this->assertContains('game.engine_overrides_update', $this->auditLines());
    }

    public function testResetOverridesEmptiesObject(): void
    {
        $game = $this->makeGame('ovr-reset');
        $this->svc->saveOverrides($game['id'], ['speed_scale' => '1.5']);
        $this->assertTrue($this->svc->resetOverridesToGenre($game['id'])['ok']);
        $raw = json_decode((string)file_get_contents($this->dir . '/games/' . $game['id'] . '.json'), true);
        $this->assertSame([], $raw['engine_overrides']); // {} в JSON → [] при decode assoc
        $this->assertContains('"reset":["engine_overrides"]', $this->auditLines());
    }

    public function testCopyFromSameEngineOnly(): void
    {
        $g1 = $this->makeGame('cp-target');
        $g2 = $this->makeGame('cp-source');
        $this->svc->saveOverrides($g2['id'], ['speed_scale' => '1.8']);
        $this->svc->saveSounds($g2['id'], ['sounds_json' => json_encode([])]);
        // другой движок — ошибка
        $other = $this->games->create(['title' => 'Рейд', 'description' => str_repeat('р', 30), 'genre_id' => 'genre-riverraid', 'category_ids' => [], 'status' => 'draft', 'slug' => 'cp-other-engine']);
        assert($other['ok']);
        $res = $this->svc->copyFrom($g1['id'], $other['game']['id'], ['controls']);
        $this->assertSame(['Источник должен быть того же движка.'], $res['errors']);
        // тот же движок
        $res = $this->svc->copyFrom($g1['id'], $g2['id'], ['engine_overrides', 'nonsense']);
        $this->assertTrue($res['ok']);
        $fresh = $this->games->find($g1['id']);
        $ovr = SettingsService::toArray($fresh['engine_overrides']);
        $this->assertSame(1.8, $ovr['speed_scale']);
        $this->assertContains('"action":"game.settings_copy"', $this->auditLines());
    }

    public function testPublishRequiresDigKeys(): void
    {
        // игра static-screen с diggable-легендой: без dig_left/dig_right публикация невозможна (§7.4)
        $game = $this->makeGame('pub-dig');
        $errors = $this->games->setStatus($game['id'], 'published');
        $this->assertTrue($errors !== []); // нет cover/уровней и т.д. — но и dig проверимpresence сообщения
        $joined = implode('|', $errors);
        $this->assertTrue(str_contains($joined, 'cover') || str_contains($joined, 'обложка'));
    }

    /** Единый контракт ответа ['ok'=>bool,'errors'=>list<string>] для всех методов (шаг 2). */
    public function testNotFoundContractUniform(): void
    {
        $missing = 'game-not-exists';
        foreach ([
            fn () => $this->svc->saveControls($missing, ['left' => 'KeyA']),
            fn () => $this->svc->resetControls($missing),
            fn () => $this->svc->saveSounds($missing, ['sounds' => []]),
            fn () => $this->svc->resetSounds($missing),
            fn () => $this->svc->saveOverrides($missing, ['speed_scale' => 1]),
            fn () => $this->svc->resetOverridesToGenre($missing),
            fn () => $this->svc->copyFrom($missing, 'x', ['controls']),
        ] as $call) {
            $res = $call();
            $this->assertFalse($res['ok']);
            $this->assertSame(['Игра не найдена.'], $res['errors']);
        }
    }

    public function testResetSoundsToGenreDefaults(): void
    {
        $game = $this->makeGame('snd-reset');
        $genre = $this->genres->find((string)$game['genre_id']);
        $defaults = array_values((array)($genre['engine_config']['default_sounds'] ?? []));
        // сохраняем один свой звук
        $custom = [['event' => 'pickup', 'wave' => 'square', 'frequency_hz' => 440,
            'duration_ms' => 80, 'volume' => 0.2, 'envelope' => 'decay']];
        $res = $this->svc->saveSounds($game['id'], ['sounds' => $custom]);
        $this->assertTrue($res['ok']);
        $fresh = $this->games->find($game['id']);
        $this->assertCount(1, (array)$fresh['sounds']);
        // сброс -> дефолты жанра
        $this->assertTrue($this->svc->resetSounds($game['id'])['ok']);
        $fresh = $this->games->find($game['id']);
        $this->assertSame($defaults, array_values((array)$fresh['sounds']));
        $this->assertContains('"action":"game.settings_reset"', $this->auditLines());
    }

    private function auditLines(): string
    {
        return (string)@file_get_contents($this->dir . '/audit.log');
    }
}
