<?php

declare(strict_types=1);

namespace Tests\Game;

use App\Validation\TriggerValidator;
use TestCase;

/**
 * Блок 10 §2.7–§2.11: валидация триггеров и условий победы/поражения (чистая, по легенде).
 */
final class TriggerValidatorTest extends TestCase
{
    /** Легенда с mob 🤖, pickup gold 💰, выходом 🚪 и т.д. */
    private function legend(): array
    {
        return [
            '🧱' => ['role' => 'obstacle', 'name' => 'Кирпич', 'solid' => true],
            '🏃' => ['role' => 'start', 'name' => 'Игрок'],
            '💰' => ['role' => 'pickup', 'name' => 'Золото', 'pickup_id' => 'gold', 'effect' => 'score', 'value' => 10],
            '🤖' => ['role' => 'mob', 'name' => 'Робот', 'hp' => 1, 'damage_on_touch' => 1],
            '🚪' => ['role' => 'exit', 'name' => 'Выход', 'mode' => 'after_all'],
        ];
    }

    public function testValidKillCountTrigger(): void
    {
        $params = ['triggers' => [[
            'id' => 'trig-001', 'name' => 'После убийств', 'enabled' => true, 'once' => true,
            'when' => ['type' => 'kill_count', 'target_emoji' => '🤖', 'compare' => 'gte', 'count' => 3],
            'then' => [
                ['type' => 'open_exit'],
                ['type' => 'show_message', 'text' => 'Выход открыт!', 'duration_ms' => 2000],
                ['type' => 'set_flag', 'flag_name' => 'exit_opened'],
            ],
        ]]];
        $this->assertSame([], TriggerValidator::validate($params, $this->legend()));
    }

    public function testUnknownWhenTypeRejected(): void
    {
        $errors = TriggerValidator::validate(['triggers' => [[
            'id' => 'trig-001', 'name' => 'X test',
            'when' => ['type' => 'moon_landed'],
            'then' => [['type' => 'victory']],
        ]]], $this->legend());
        $this->assertCount(1, $errors);
        $this->assertContains('moon_landed', $errors[0]);
    }

    public function testKillCountRequiresMobEmojiFromLegend(): void
    {
        $errors = TriggerValidator::validate(['triggers' => [[
            'id' => 'trig-001', 'name' => 'Тест',
            'when' => ['type' => 'kill_count', 'target_emoji' => '🐉', 'compare' => 'gte', 'count' => 1],
            'then' => [['type' => 'play_sound', 'event' => 'death']],
        ]]], $this->legend());
        $this->assertCount(1, $errors);
        $this->assertContains('target_emoji', $errors[0]);
    }

    public function testPickupAllRequiresKnownPickupId(): void
    {
        $ok = TriggerValidator::validate(['triggers' => [[
            'id' => 'trig-001', 'name' => 'Собрано всё',
            'when' => ['type' => 'pickup_all', 'pickup_id' => 'gold'],
            'then' => [['type' => 'spawn', 'emoji' => '🤖', 'col' => 5, 'row' => 5]],
        ]]], $this->legend());
        $this->assertSame([], $ok);
        $bad = TriggerValidator::validate(['triggers' => [[
            'id' => 'trig-001', 'name' => 'Собрано всё',
            'when' => ['type' => 'pickup_all', 'pickup_id' => 'gems'],
            'then' => [['type' => 'victory']],
        ]]], $this->legend());
        $this->assertCount(1, $bad);
        $this->assertContains('gems', $bad[0]);
    }

    public function testPlayerInZoneBounds(): void
    {
        $ok = TriggerValidator::validate(['triggers' => [[
            'id' => 'trig-001', 'name' => 'В зоне',
            'when' => ['type' => 'player_in_zone', 'col' => 0, 'row' => 0, 'width' => 4, 'height' => 3],
            'then' => [['type' => 'teleport_player', 'col' => 10, 'row' => 10]],
        ]]], $this->legend());
        $this->assertSame([], $ok);
        $bad = TriggerValidator::validate(['triggers' => [[
            'id' => 'trig-001', 'name' => 'В зоне',
            'when' => ['type' => 'player_in_zone', 'col' => 30, 'row' => 20, 'width' => 5, 'height' => 5],
            'then' => [['type' => 'victory']],
        ]]], $this->legend());
        $this->assertCount(1, $bad); // зона за правым/нижним краем сетки 32×24
    }

    public function testTimeElapsedAndScoreConditions(): void
    {
        $errors = TriggerValidator::validate(['triggers' => [[
            'id' => 'trig-001', 'name' => 'Тайминг ок',
            'when' => ['type' => 'all_of', 'triggers' => [
                ['type' => 'time_elapsed', 'seconds' => 60],
                ['type' => 'score', 'compare' => 'lt', 'value' => 500],
            ]],
            'then' => [['type' => 'change_player_stat', 'stat' => 'lives', 'delta' => -1]],
        ]]], $this->legend());
        $this->assertSame([], $errors);
        $bad = TriggerValidator::validate(['triggers' => [[
            'id' => 'trig-001', 'name' => 'Плохие числа',
            'when' => ['type' => 'time_elapsed', 'seconds' => 0],
            'then' => [['type' => 'change_player_stat', 'stat' => 'mana', 'delta' => 100]],
        ]]], $this->legend());
        // seconds=0 (вне 1–9999) + stat не из {hp,score,lives} + delta ≤ 99
        $this->assertCount(3, $bad);
    }

    public function testAllOfNestingDepthLimited(): void
    {
        $deep = ['triggers' => [[
            'id' => 'trig-001', 'name' => 'Вложенность',
            'when' => ['type' => 'all_of', 'triggers' => [[
                'type' => 'any_of', 'triggers' => [[
                    'type' => 'all_of', 'triggers' => [['type' => 'time_elapsed', 'seconds' => 5]],
                ]],
            ]]],
            'then' => [['type' => 'victory']],
        ]]];
        $errors = TriggerValidator::validate($deep, $this->legend());
        $this->assertCount(1, $errors);
        $this->assertContains('глубин', $errors[0]);
    }

    public function testThenActionValidation(): void
    {
        $errors = TriggerValidator::validate(['triggers' => [[
            'id' => 'trig-001', 'name' => 'Действия',
            'when' => ['type' => 'flag_set', 'flag_name' => 'boss_dead'],
            'then' => [
                ['type' => 'explode_tile'],                                  // неизвестный тип
                ['type' => 'spawn', 'emoji' => '🐉', 'col' => 99, 'row' => 99], // emoji вне легенды + клетка вне сетки
                ['type' => 'show_message', 'text' => '', 'duration_ms' => 100], // пустой текст + duration вне диапазона
                ['type' => 'damage_player', 'amount' => 0],
                ['type' => 'play_sound', 'event' => 'unknown_event'],
            ],
        ]]], $this->legend());
        $this->assertTrue(count($errors) >= 7, implode(' | ', $errors));
    }

    public function testFlagNamePatternAndDuplicateIds(): void
    {
        $errors = TriggerValidator::validate(['triggers' => [
            ['id' => 'trig-001', 'name' => 'Первый', 'when' => ['type' => 'flag_set', 'flag_name' => 'Bad Flag!'], 'then' => [['type' => 'victory']]],
            ['id' => 'trig-001', 'name' => 'Дубль id', 'when' => ['type' => 'flag_set', 'flag_name' => 'ok_flag'], 'then' => [['type' => 'victory']]],
            ['id' => 'trigger-x', 'name' => 'Кривой id', 'when' => ['type' => 'flag_set', 'flag_name' => 'ok_flag2'], 'then' => [['type' => 'victory']]],
        ]], $this->legend());
        $this->assertCount(3, $errors); // bad flag + дубль id + неверный формат id
    }

    public function testTriggersLimit50(): void
    {
        $many = [];
        for ($i = 1; $i <= 51; $i++) {
            $many[] = [
                'id' => sprintf('trig-%03d', $i), 'name' => 'Триггер ' . $i,
                'when' => ['type' => 'flag_set', 'flag_name' => 'f' . $i],
                'then' => [['type' => 'victory']],
            ];
        }
        $errors = TriggerValidator::validate(['triggers' => $many], $this->legend());
        $this->assertCount(1, $errors);
        $this->assertContains('50', $errors[0]);
    }

    public function testVictoryAndDefeatConditions(): void
    {
        $ok = TriggerValidator::validate([
            'victory_conditions' => [
                ['type' => 'reach_exit'],
                ['type' => 'kill_all', 'target_emoji' => '🤖'],
                ['type' => 'collect_all', 'pickup_id' => 'gold'],
                ['type' => 'score_reached', 'value' => 1000],
                ['type' => 'time_under', 'seconds' => 120],
                ['type' => 'flag_set', 'flag_name' => 'boss_dead'],
            ],
            'defeat_conditions' => [
                ['type' => 'hp_zero'], ['type' => 'lives_zero'],
                ['type' => 'time_limit_exceeded'], ['type' => 'player_fell'],
                ['type' => 'flag_set', 'flag_name' => 'cursed'],
            ],
        ], $this->legend());
        $this->assertSame([], $ok);

        $bad = TriggerValidator::validate([
            'victory_conditions' => [
                ['type' => 'surrender'],
                ['type' => 'kill_all', 'target_emoji' => '👾'],   // не mob легенды
                ['type' => 'collect_all', 'pickup_id' => 'silver'], // нет такого pickup
                ['type' => 'score_reached', 'value' => -5],
            ],
            'defeat_conditions' => [['type' => 'out_of_ammo']],
        ], $this->legend());
        $this->assertCount(5, $bad);
    }

    public function testEnabledFalseAndOnceAccepted(): void
    {
        $errors = TriggerValidator::validate(['triggers' => [[
            'id' => 'trig-001', 'name' => 'Отключённый', 'enabled' => false, 'once' => false,
            'when' => ['type' => 'player_touch_emoji', 'emoji' => '🚪'],
            'then' => [['type' => 'despawn', 'emoji' => '🤖']],
        ]]], $this->legend());
        $this->assertSame([], $errors);
        $bad = TriggerValidator::validate(['triggers' => [[
            'id' => 'trig-001', 'name' => 'Кривые флаги', 'enabled' => 'yes-please', 'once' => 1,
            'when' => ['type' => 'flag_set', 'flag_name' => 'ok_flag'],
            'then' => [['type' => 'victory']],
        ]]], $this->legend());
        $this->assertCount(2, $bad);
    }
}
