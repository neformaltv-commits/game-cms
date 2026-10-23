<?php

declare(strict_types=1);

namespace Tests\Validation;

use App\Audit\Logger;
use App\Library\LibraryService;
use App\Storage\FileStore;
use App\Validation\LegendValidator;
use TestCase;

final class LegendValidatorTest extends TestCase
{
    private LegendValidator $v;

    protected function setUp(): void
    {
        $dir = $this->tmpDir('legend');
        $store = new FileStore($dir);
        $audit = new Logger($store, 'audit.log', 14);
        $root = dirname(__DIR__, 2);
        $ai = new LibraryService($store, $audit, LibraryService::KIND_AI, $root);
        $pat = new LibraryService($store, $audit, LibraryService::KIND_PATTERN, $root);
        $ai->seed();
        $pat->seed();
        $this->v = new LegendValidator($ai, $pat);
    }

    /** Базовая корректная легенда (роль start ровно одна). */
    private function baseLegend(): array
    {
        return [
            '🏃' => ['role' => 'start', 'name' => 'Игрок'],
            '🧱' => ['role' => 'obstacle', 'name' => 'Кирпич', 'solid' => true],
            '💰' => ['role' => 'pickup', 'name' => 'Золото', 'pickup_id' => 'gold'],
            '🚪' => ['role' => 'exit', 'name' => 'Выход', 'mode' => 'touch'],
        ];
    }

    public function testValidLegendPasses(): void
    {
        $this->assertSame([], $this->v->validate($this->baseLegend(), 'static-screen'));
    }

    public function testNonEmojiKeyRejected(): void
    {
        $legend = $this->baseLegend();
        $legend['<img src=x>'] = ['role' => 'decor', 'name' => 'Мусор'];
        $errors = $this->v->validate($legend, 'static-screen');
        $this->assertTrue($errors !== []);
        $this->assertContains('ключ должен быть одним emoji-кластером', $errors[0]);
    }

    public function testExactlyOneStartRequired(): void
    {
        $legend = $this->baseLegend();
        unset($legend['🏃']);
        $errors = $this->v->validate($legend, 'static-screen');
        $this->assertContains('В эффективной легенде должен быть ровно один элемент с ролью «start».', $errors);

        $legend2 = $this->baseLegend();
        $legend2['👤'] = ['role' => 'start', 'name' => 'Второй игрок'];
        $errors2 = $this->v->validate($legend2, 'static-screen');
        $this->assertTrue(implode('|', $errors2) !== '' && str_contains(implode('|', $errors2), 'только один «start»'));
    }

    public function testUnknownRoleRejected(): void
    {
        $legend = $this->baseLegend();
        $legend['⭐'] = ['role' => 'magic', 'name' => 'Что-то'];
        $errors = $this->v->validate($legend, 'static-screen');
        $this->assertContains('«⭐»: неизвестная роль «magic».', $errors);
    }

    public function testDuplicatePickupIdRejected(): void
    {
        $legend = $this->baseLegend();
        $legend['🔑'] = ['role' => 'pickup', 'name' => 'Ключ', 'pickup_id' => 'gold'];
        $errors = $this->v->validate($legend, 'static-screen');
        $this->assertTrue(str_contains(implode('|', $errors), 'pickup_id «gold» дублируется'));
    }

    public function testBadPickupIdFormatRejected(): void
    {
        $legend = $this->baseLegend();
        $legend['💎'] = ['role' => 'pickup', 'name' => 'Алмаз', 'pickup_id' => 'Bad Id!'];
        $errors = $this->v->validate($legend, 'static-screen');
        $this->assertTrue(str_contains(implode('|', $errors), 'pickup_id должен соответствовать'));
    }

    public function testBackgroundMustBeWalkableAndObstacleSolid(): void
    {
        $legend = $this->baseLegend();
        $legend['·'] = ['role' => 'background', 'name' => 'Пусто', 'walkable' => false];
        $legend['⬜'] = ['role' => 'obstacle', 'name' => 'Бетон', 'solid' => false];
        $errors = $this->v->validate($legend, 'static-screen');
        $joined = implode('|', $errors);
        $this->assertContains('walkable обязательно true', $joined);
        $this->assertContains('solid обязательно true', $joined);
    }

    public function testNameLengthBounds(): void
    {
        $legend = $this->baseLegend();
        $legend['🍒'] = ['role' => 'decor', 'name' => 'Я']; // < 2 символов
        $errors = $this->v->validate($legend, 'static-screen');
        $this->assertContains('name должен быть 2–80 символов', implode('|', $errors));
    }

    public function testDamageOnTouchRange(): void
    {
        $legend = $this->baseLegend();
        $legend['👹'] = ['role' => 'mob', 'name' => 'Монстр', 'damage_on_touch' => 99];
        $errors = $this->v->validate($legend, 'static-screen');
        $this->assertContains('damage_on_touch ∈ [0, 10]', implode('|', $errors));
    }

    public function testExitModeEnum(): void
    {
        $legend = $this->baseLegend();
        $legend['🚪']['mode'] = 'whenever';
        $errors = $this->v->validate($legend, 'static-screen');
        $this->assertContains('mode ∈ {touch, after_all}', implode('|', $errors));
    }
}
