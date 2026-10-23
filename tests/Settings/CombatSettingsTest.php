<?php

declare(strict_types=1);

namespace Tests\Settings;

use App\Audit\Logger;
use App\CategoryService;
use App\GameService;
use App\GenreService;
use App\LevelService;
use App\Library\LibraryService;
use App\Settings\SettingsService;
use App\Storage\FileStore;
use App\Validation\GameValidator;
use App\Validation\LegendValidator;
use TestCase;

/**
 * Блок 10 §6: сохранение player_attacks / player / scoring через SettingsService
 * с кросс-проверками (fire-клавиша, снаряд в легенде, stomp только для gravity-движков).
 */
final class CombatSettingsTest extends TestCase
{
    private string $dir;
    private FileStore $store;
    private Logger $audit;
    private GenreService $genres;
    private GameService $games;
    private SettingsService $svc;

    protected function setUp(): void
    {
        $this->dir = $this->tmpDir('combat');
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
        $this->svc = new SettingsService($this->store, $this->audit, $this->genres, $this->games);
    }

    /** Игра на движке horizontal-scroll (gravity) с мобом/снарядом в легенде. @return array<string,mixed> */
    private function makeCombatGame(string $slug): array
    {
        $res = $this->games->create([
            'title' => 'Бой ' . $slug, 'description' => str_repeat('д', 30), 'genre_id' => 'genre-platformer',
            'category_ids' => [], 'status' => 'draft', 'slug' => $slug,
        ]);
        assert($res['ok'], implode('; ', $res['errors']));
        $game = $res['game'];
        // легенда: снаряд ➡️ с damage ≥ 1 + моб 🤖 (легенда игры — поле 'legend', ТЗ §6)
        $legend = (array)$game['legend'];
        $legend['➡️'] = ['role' => 'projectile', 'name' => 'Пуля', 'damage' => 1, 'speed_tiles_per_s' => 8, 'range_tiles' => 10];
        $legend['🤖'] = ['role' => 'mob', 'name' => 'Робот', 'hp' => 2, 'damage_on_touch' => 1,
            'ai_model' => 'idle', 'movement_pattern' => 'linear', 'score_value' => 50];
        $game['legend'] = $legend;
        $errs = $this->games->save($game, false);
        assert($errs === [], implode('; ', $errs));
        return $this->games->find((string)$game['id']);
    }

    public function testSavePlayerAttacksHappyPath(): void
    {
        $game = $this->makeCombatGame('atk-ok');
        // назначим fire-клавишу (нужна для melee/ranged по §6.1)
        $r = $this->svc->saveControls($game['id'], [
            'left' => 'ArrowLeft', 'right' => 'ArrowRight', 'up' => 'ArrowUp', 'down' => 'ArrowDown',
            'action' => 'Space', 'fire' => 'KeyX',
        ]);
        $this->assertTrue($r['ok'], implode('; ', $r['errors']));
        $res = $this->svc->savePlayerAttacks($game['id'], [
            'melee' => ['enabled' => true, 'damage' => 2, 'range_tiles' => 1, 'shape' => 'arc',
                'cooldown_ms' => 300, 'hits' => ['mob'], 'knockback_tiles' => 1, 'stun_ms' => 200],
            'ranged' => ['enabled' => true, 'projectile_emoji' => '➡️', 'cooldown_ms' => 250,
                'direction' => 'facing', 'ammo' => ['type' => 'count', 'start' => 10, 'max' => 30]],
            'stomp' => ['enabled' => true, 'damage' => 1, 'bounce_power' => -3.5],
            'special' => ['enabled' => false, 'type' => 'screen_clear'],
        ]);
        $this->assertTrue($res['ok'], implode('; ', $res['errors']));
        $fresh = $this->games->find($game['id']);
        $ov = (array)$fresh['engine_overrides'];
        $this->assertSame(2, $ov['player_attacks']['melee']['damage']);
        $this->assertContains('game.player_attacks_update', $this->auditLines());
    }

    public function testMeleeRequiresFireKey(): void
    {
        $game = $this->makeCombatGame('atk-nofire');
        $res = $this->svc->savePlayerAttacks($game['id'], [
            'melee' => ['enabled' => true, 'damage' => 1, 'shape' => 'single', 'cooldown_ms' => 300, 'hits' => ['mob']],
        ]);
        $this->assertFalse($res['ok']);
        $this->assertContains('fire', $res['errors'][0]);
    }

    public function testRangedRequiresProjectileInLegendWithDamage(): void
    {
        $game = $this->makeCombatGame('atk-proj');
        $this->svc->saveControls($game['id'], [
            'left' => 'ArrowLeft', 'right' => 'ArrowRight', 'up' => 'ArrowUp', 'down' => 'ArrowDown',
            'action' => 'Space', 'fire' => 'KeyX',
        ]);
        // снаряда 👾 нет в легенде
        $res = $this->svc->savePlayerAttacks($game['id'], [
            'ranged' => ['enabled' => true, 'projectile_emoji' => '👾', 'cooldown_ms' => 200,
                'direction' => 'facing', 'ammo' => ['type' => 'infinite']],
        ]);
        $this->assertFalse($res['ok']);
        $this->assertContains('projectile', implode(' ', $res['errors']));
    }

    public function testStompRejectedForVerticalScroll(): void
    {
        $res = $this->games->create([
            'title' => 'Вертик бой', 'description' => str_repeat('д', 30), 'genre_id' => 'genre-riverraid',
            'category_ids' => [], 'status' => 'draft', 'slug' => 'atk-stomp-vscroll',
        ]);
        assert($res['ok'], implode('; ', $res['errors']));
        $game = $res['game'];
        $out = $this->svc->savePlayerAttacks($game['id'], [
            'stomp' => ['enabled' => true, 'damage' => 1, 'bounce_power' => -3.5],
        ]);
        $this->assertFalse($out['ok']);
        $this->assertContains('гравитацией', $out['errors'][0]);
    }

    public function testSpecialRequiresKeyAndPickupRechargeExists(): void
    {
        $game = $this->makeCombatGame('atk-sp');
        $res = $this->svc->savePlayerAttacks($game['id'], [
            'special' => ['enabled' => true, 'type' => 'screen_clear', 'charges_max' => 3,
                'recharge_by_pickup' => 'bomb', 'action' => 'action'],
        ]);
        $this->assertFalse($res['ok']); // нет клавиши action + pickup_id bomb отсутствует в легенде
        $joined = implode(' ', $res['errors']);
        $this->assertContains('action', $joined);
        $this->assertContains('bomb', $joined);
    }

    public function testSavePlayerValidatesAndPersists(): void
    {
        $game = $this->makeCombatGame('pl-ok');
        $bad = $this->svc->savePlayer($game['id'], ['lives' => 99, 'health_max' => 5]);
        $this->assertFalse($bad['ok']);
        $ok = $this->svc->savePlayer($game['id'], [
            'lives' => 5, 'health_max' => 3, 'invulnerability_ms' => 1200,
            'dodge' => ['enabled' => false], 'parry' => ['enabled' => false],
        ]);
        $this->assertTrue($ok['ok'], implode('; ', $ok['errors']));
        $fresh = $this->games->find($game['id']);
        $this->assertSame(5, $fresh['engine_overrides']['player']['lives']);
        $this->assertContains('game.player_update', $this->auditLines());
    }

    public function testSaveScoringMigratesLegacyKeys(): void
    {
        $game = $this->makeCombatGame('sc-mig');
        // старые ключи из блока 9 — положим напрямую в overrides
        $fresh = $game;
        $ov = (array)$fresh['engine_overrides'];
        $ov['player'] = ['score_on_enemy_kill' => 75, 'score_on_pickup' => 15];
        $fresh['engine_overrides'] = $ov;
        $errs = $this->games->save($fresh, false);
        assert($errs === [], implode('; ', $errs));

        $res = $this->svc->saveScoring($game['id'], ['on_pickup' => 20]);
        $this->assertTrue($res['ok'], implode('; ', $res['errors']));
        $after = $this->games->find($game['id']);
        $sc = (array)$after['engine_overrides']['scoring'];
        $this->assertSame(20, $sc['on_pickup']);
        // миграция §2.6: score_on_enemy_kill перенесён в on_enemy_kill, score_on_pickup НЕ перезаписал явное 20
        $this->assertSame(75, $sc['on_enemy_kill']);
        $this->assertFalse(array_key_exists('score_on_enemy_kill', (array)($after['engine_overrides']['player'] ?? [])));
    }

    public function testScoringRejectsOutOfRange(): void
    {
        $game = $this->makeCombatGame('sc-bad');
        $res = $this->svc->saveScoring($game['id'], [
            'on_enemy_kill' => 999999,
            'combo' => ['multiplier_per_hit' => 3.0, 'max_multiplier' => 2.0],
        ]);
        $this->assertFalse($res['ok']);
        $this->assertTrue(count($res['errors']) >= 2);
    }

    /** @return list<string> */
    private function auditLines(): array
    {
        $raw = (string)@file_get_contents($this->dir . '/audit.log');
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
