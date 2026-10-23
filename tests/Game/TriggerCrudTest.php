<?php

declare(strict_types=1);

namespace Tests\Game;

use App\Audit\Logger;
use App\CategoryService;
use App\GameService;
use App\GenreService;
use App\LevelService;
use App\Library\LibraryService;
use App\Storage\FileStore;
use App\Validation\GameValidator;
use App\Validation\LegendValidator;
use TestCase;

/**
 * Блок 10 §4.4/§6.6: CRUD триггеров и условий победы/поражения в LevelService —
 * нормализация, nextTriggerId, атомарность mutateLevels (при ошибке данные не
 * изменяются), аудит level.trigger_* / winlose_conditions_update.
 */
final class TriggerCrudTest extends TestCase
{
    private string $dir;
    private FileStore $store;
    private Logger $audit;
    private GenreService $genres;
    private GameService $games;
    private LevelService $levels;

    protected function setUp(): void
    {
        $this->dir = $this->tmpDir('trig');
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

    /** Игра genre-loderunner + уровень lvl-001 с мобом 👾 в легенде. @return array<string,mixed> */
    private function makeLevel(string $slug): array
    {
        $res = $this->games->create([
            'title' => 'Триг ' . $slug, 'description' => str_repeat('д', 30),
            'genre_id' => 'genre-loderunner', 'category_ids' => [], 'slug' => $slug,
        ]);
        assert($res['ok'], implode('; ', $res['errors']));
        $game = $res['game'];
        // моб 👾 в легенде игры (нужен для kill_count/spawn)
        $legend = (array)$game['legend'];
        $legend['👾'] = ['role' => 'mob', 'name' => 'Пришелец', 'hp' => 1, 'damage_on_touch' => 1,
            'ai_model' => 'stationary', 'movement_pattern' => 'linear', 'score_value' => 10];
        $game['legend'] = $legend;
        $errs = $this->games->save($game, false);
        if ($errs !== []) {
            throw new \RuntimeException('save legend: ' . implode('; ', $errs));
        }
        $lr = $this->levels->create((string)$game['id']);
        assert($lr['ok'], implode('; ', $lr['errors'] ?? []));
        return $this->games->find((string)$game['id']);
    }

    /** Корректный ввод триггера (форма). @return array<string,mixed> */
    private function validTriggerInput(string $name = 'Золото собрано'): array
    {
        return [
            'name' => $name,
            'enabled' => '1',
            'once' => '1',
            'when' => ['type' => 'pickup_count', 'pickup_id' => 'gold', 'compare' => 'gte', 'count' => 5],
            'then' => [
                ['type' => 'show_message', 'text' => 'Дверь открыта!', 'duration_ms' => 2000],
                ['type' => 'open_exit'],
            ],
        ];
    }

    /** Список действий из audit.log. @return list<string> */
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

    public function testCreateTriggerNormalizesAndPersists(): void
    {
        $game = $this->makeLevel('trg-cr');
        $res = $this->levels->createTrigger($game['id'], 'lvl-001', $this->validTriggerInput());
        $this->assertTrue($res['ok'], implode('; ', $res['errors']));
        $t = (array)$res['trigger'];
        // нормализация: id trig-001, bool enabled/once, int count/duration
        $this->assertSame('trig-001', $t['id']);
        $this->assertSame(true, $t['enabled']);
        $this->assertSame(true, $t['once']);
        $this->assertSame(5, $t['when']['count']);
        $this->assertSame(2000, $t['then'][0]['duration_ms']);
        // персистентность
        $fresh = $this->games->find($game['id']);
        $triggers = (array)($fresh['levels'][0]['params']['triggers'] ?? []);
        $this->assertCount(1, $triggers);
        $this->assertSame('trig-001', $triggers[0]['id']);
        $this->assertContains('level.trigger_create', $this->auditLines());
    }

    public function testNextTriggerIdMonotonicAfterDelete(): void
    {
        $game = $this->makeLevel('trg-id');
        $a = $this->levels->createTrigger($game['id'], 'lvl-001', $this->validTriggerInput('Первый'));
        $b = $this->levels->createTrigger($game['id'], 'lvl-001', $this->validTriggerInput('Второй'));
        $this->assertSame('trig-001', $a['trigger']['id']);
        $this->assertSame('trig-002', $b['trigger']['id']);
        // удаление первого, новый — trig-003 (не переиспользует trig-001)
        $this->assertSame([], $this->levels->deleteTrigger($game['id'], 'lvl-001', 'trig-001'));
        $c = $this->levels->createTrigger($game['id'], 'lvl-001', $this->validTriggerInput('Третий'));
        $this->assertTrue($c['ok'], implode('; ', $c['errors']));
        $this->assertSame('trig-003', $c['trigger']['id']);
        $this->assertContains('level.trigger_delete', $this->auditLines());
    }

    public function testCreateTriggerInvalidNotPersistedAtomicity(): void
    {
        $game = $this->makeLevel('trg-bad');
        $input = $this->validTriggerInput();
        $input['when'] = ['type' => 'pickup_count', 'pickup_id' => 'nope', 'compare' => 'gte', 'count' => 1];
        $res = $this->levels->createTrigger($game['id'], 'lvl-001', $input);
        $this->assertFalse($res['ok']);
        $this->assertContains('nope', implode(' ', $res['errors']));
        // данные не изменились (§7.2)
        $fresh = $this->games->find($game['id']);
        $this->assertCount(0, (array)($fresh['levels'][0]['params']['triggers'] ?? []));
    }

    public function testUpdateTriggerReplacesAndAudits(): void
    {
        $game = $this->makeLevel('trg-up');
        $this->levels->createTrigger($game['id'], 'lvl-001', $this->validTriggerInput());
        $input = $this->validTriggerInput('Изменённый триггер');
        $input['when']['count'] = 9;
        $res = $this->levels->updateTrigger($game['id'], 'lvl-001', 'trig-001', $input);
        $this->assertTrue($res['ok'], implode('; ', $res['errors']));
        $fresh = $this->games->find($game['id']);
        $t = $fresh['levels'][0]['params']['triggers'][0];
        $this->assertSame('Изменённый триггер', $t['name']);
        $this->assertSame(9, $t['when']['count']);
        $this->assertSame('trig-001', $t['id']); // id сохраняется
        $lines = $this->auditLines();
        $this->assertContains('level.trigger_update', $lines);
    }

    public function testUpdateTriggerNotFoundAndInvalid(): void
    {
        $game = $this->makeLevel('trg-upbad');
        $miss = $this->levels->updateTrigger($game['id'], 'lvl-001', 'trig-999', $this->validTriggerInput());
        $this->assertFalse($miss['ok']);
        $this->levels->createTrigger($game['id'], 'lvl-001', $this->validTriggerInput());
        $input = $this->validTriggerInput();
        $input['then'] = [['type' => 'spawn', 'emoji' => '🐉', 'col' => 1, 'row' => 1]]; // нет в легенде
        $bad = $this->levels->updateTrigger($game['id'], 'lvl-001', 'trig-001', $input);
        $this->assertFalse($bad['ok']);
        $this->assertContains('легенде', implode(' ', $bad['errors']));
        $fresh = $this->games->find($game['id']);
        $this->assertSame('Золото собрано', $fresh['levels'][0]['params']['triggers'][0]['name']); // не изменился
    }

    public function testDeleteTriggerUnknownIds(): void
    {
        $game = $this->makeLevel('trg-del');
        $this->assertSame(['Уровень не найден.'], $this->levels->deleteTrigger($game['id'], 'lvl-999', 'trig-001'));
        $this->assertSame(['Триггер не найден.'], $this->levels->deleteTrigger($game['id'], 'lvl-001', 'trig-001'));
    }

    public function testSaveWinLoseConditionsValidAndInvalid(): void
    {
        $game = $this->makeLevel('trg-wl');
        $errs = $this->levels->saveWinLoseConditions($game['id'], 'lvl-001', [
            'victory_conditions' => [['type' => 'reach_exit']],
            'defeat_conditions' => [['type' => 'time_limit_exceeded'], ['type' => 'lives_zero']],
        ]);
        $this->assertSame([], $errs);
        $fresh = $this->games->find($game['id']);
        $params = (array)$fresh['levels'][0]['params'];
        $this->assertCount(1, $params['victory_conditions']);
        $this->assertCount(2, $params['defeat_conditions']);
        $this->assertContains('level.winlose_conditions_update', $this->auditLines());
        // невалидный тип — ошибка, данные не изменились
        $errs2 = $this->levels->saveWinLoseConditions($game['id'], 'lvl-001', [
            'victory_conditions' => [['type' => 'bogus_win']],
            'defeat_conditions' => [],
        ]);
        $this->assertTrue($errs2 !== [], 'ожидались ошибки валидации');
        $fresh2 = $this->games->find($game['id']);
        $this->assertCount(1, (array)$fresh2['levels'][0]['params']['victory_conditions']);
    }

    public function testSaveWinLoseEmptyClearsConditions(): void
    {
        $game = $this->makeLevel('trg-wl0');
        $this->assertSame([], $this->levels->saveWinLoseConditions($game['id'], 'lvl-001', [
            'victory_conditions' => [['type' => 'kill_all']], 'defeat_conditions' => [],
        ]));
        $this->assertSame([], $this->levels->saveWinLoseConditions($game['id'], 'lvl-001', [
            'victory_conditions' => [], 'defeat_conditions' => [],
        ]));
        $fresh = $this->games->find($game['id']);
        $this->assertCount(0, (array)$fresh['levels'][0]['params']['victory_conditions']);
    }

    public function testTriggerLimit50(): void
    {
        $game = $this->makeLevel('trg-max');
        for ($i = 0; $i < 50; $i++) {
            $res = $this->levels->createTrigger($game['id'], 'lvl-001', $this->validTriggerInput('Триггер №' . ($i + 1)));
            $this->assertTrue($res['ok'], '#50 должен пройти: ' . implode('; ', $res['errors']));
        }
        $over = $this->levels->createTrigger($game['id'], 'lvl-001', $this->validTriggerInput('Пятьдесят один'));
        $this->assertFalse($over['ok']);
        $this->assertContains('максимум 50', implode(' ', $over['errors']));
        $fresh = $this->games->find($game['id']);
        $this->assertCount(50, (array)$fresh['levels'][0]['params']['triggers']);
    }
}
