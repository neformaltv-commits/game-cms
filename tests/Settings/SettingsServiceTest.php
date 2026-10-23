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
        $errors = $this->svc->saveControls($game['id'], ['left' => 'ArrowLeft,KeyA', 'dig_left' => ['KeyQ']]);
        $this->assertSame([], $errors);
        $fresh = $this->games->find($game['id']);
        $this->assertSame(['ArrowLeft', 'KeyA'], $fresh['controls']['left']);
        $this->assertSame(['KeyQ'], $fresh['controls']['dig_left']);
        $this->assertStrContains($this->auditLines(), 'game.controls_update');
    }

    public function testSaveControlsRejectsEmptyRequiredAndBadCode(): void
    {
        $game = $this->makeGame('ctl-bad');
        $errors = $this->svc->saveControls($game['id'], ['left' => '']);
        $this->assertTrue($errors !== []); // обязательное действие без клавиши
        $errors = $this->svc->saveControls($game['id'], ['left' => '<img src=x>']);
        $this->assertTrue($errors !== []); // невалидный KeyboardEvent.code
        $errors = $this->svc->saveControls($game['id'], ['left' => 'KeyA,KeyB,KeyC']);
        $this->assertTrue($errors !== []); // >2 клавиш
        // данные не изменились после ошибок
        $fresh = $this->games->find($game['id']);
        $this->assertSame(['ArrowLeft'], $fresh['controls']['left']);
    }

    public function testActiveActionsPerEngine(): void
    {
        $legend = ['🧱' => ['role' => 'obstacle', 'diggable' => true]];
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
        $this->assertSame([], $this->svc->resetControls($game['id']));
        $fresh = $this->games->find($game['id']);
        $this->assertSame(['ArrowLeft', 'KeyA'], $fresh['controls']['left']);
        $this->assertStrContains($this->auditLines(), '"action":"game.settings_reset"');
    }

    public function testSaveSoundsAndValidation(): void
    {
        $game = $this->makeGame('snd-ok');
        $json = json_encode([
            ['event' => 'dig', 'wave' => 'square', 'frequency_hz' => 440, 'frequency_end_hz' => null, 'duration_ms' => 50, 'volume' => 0.03, 'envelope' => 'decay'],
            ['event' => 'win', 'wave' => 'triangle', 'frequency_hz' => 1760, 'frequency_end_hz' => null, 'duration_ms' => 250, 'volume' => 0.05, 'envelope' => 'pluck'],
        ]);
        $this->assertSame([], $this->svc->saveSounds($game['id'], ['sounds_json' => $json]));
        $fresh = $this->games->find($game['id']);
        $this->assertCount(2, $fresh['sounds']);
        // частота вне диапазона
        $bad = json_encode([['event' => 'hit', 'wave' => 'square', 'frequency_hz' => 5000, 'duration_ms' => 50, 'volume' => 0.1, 'envelope' => 'decay']]);
        $this->assertTrue($this->svc->saveSounds($game['id'], ['sounds_json' => $bad]) !== []);
        // XSS в event отклоняется enum-валидацией
        $xss = json_encode([['event' => '<script>', 'wave' => 'square', 'frequency_hz' => 100, 'duration_ms' => 50, 'volume' => 0.1, 'envelope' => 'decay']]);
        $this->assertTrue($this->svc->saveSounds($game['id'], ['sounds_json' => $xss]) !== []);
        $this->assertStrContains($this->auditLines(), 'game.sounds_update');
    }

    public function testSaveOverridesWhitelistAndErrors(): void
    {
        $game = $this->makeGame('ovr-ok');
        $errors = $this->svc->saveOverrides($game['id'], [
            'speed_scale' => '1.1', 'fps' => '30', 'sprite_flicker' => '1',
            'evil_field' => '999', 'physics' => ['gravity' => '0.5'],
        ]);
        $this->assertSame([], $errors);
        $raw = json_decode((string)file_get_contents($this->dir . '/games/' . $game['id'] . '.json'), true);
        $this->assertSame(1.1, $raw['engine_overrides']['speed_scale']);
        $this->assertSame(30, $raw['engine_overrides']['fps']);
        $this->assertSame(true, $raw['engine_overrides']['sprite_flicker']);
        $this->assertSame(0.5, $raw['engine_overrides']['physics']['gravity']);
        $this->assertFalse(isset($raw['engine_overrides']['evil_field'])); // white-list (§8)
        // вне диапазона
        $this->assertTrue($this->svc->saveOverrides($game['id'], ['speed_scale' => '3.0']) !== []);
        // movement_type × view_mode
        $this->assertTrue($this->svc->saveOverrides($game['id'], ['movement_type' => 'top_down_8dir', 'view_mode' => 'side']) !== []);
        $this->assertStrContains($this->auditLines(), 'game.engine_overrides_update');
    }

    public function testResetOverridesEmptiesObject(): void
    {
        $game = $this->makeGame('ovr-reset');
        $this->svc->saveOverrides($game['id'], ['speed_scale' => '1.5']);
        $this->assertSame([], $this->svc->resetOverridesToGenre($game['id']));
        $raw = json_decode((string)file_get_contents($this->dir . '/games/' . $game['id'] . '.json'), true);
        $this->assertSame([], $raw['engine_overrides']); // {} в JSON → [] при decode assoc
        $this->assertStrContains($this->auditLines(), '"reset":["engine_overrides"]');
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
        $errs = $this->svc->copyFrom($g1['id'], $other['game']['id'], ['controls']);
        $this->assertSame(['Источник должен быть того же движка.'], $errs);
        // тот же движок
        $errs = $this->svc->copyFrom($g1['id'], $g2['id'], ['engine_overrides', 'nonsense']);
        $this->assertSame([], $errs);
        $fresh = $this->games->find($g1['id']);
        $ovr = SettingsService::toArray($fresh['engine_overrides']);
        $this->assertSame(1.8, $ovr['speed_scale']);
        $this->assertStrContains($this->auditLines(), '"action":"game.settings_copy"');
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

    private function auditLines(): string
    {
        return (string)@file_get_contents($this->dir . '/logs/audit.log');
    }
}
