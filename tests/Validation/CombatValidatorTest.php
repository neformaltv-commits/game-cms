<?php

declare(strict_types=1);

namespace Tests\Validation;

use App\Validation\CombatValidator;
use TestCase;

/**
 * Блок 10 §2.1–§2.6: чистые проверки CombatValidator (диапазоны, enum, кросс-поля).
 */
final class CombatValidatorTest extends TestCase
{
    public function testValidPlayerAttacksFromSpecExample(): void
    {
        // пример из ТЗ §2.1
        $pa = [
            'melee' => ['enabled' => true, 'damage' => 1, 'range_tiles' => 1, 'shape' => 'single',
                'cooldown_ms' => 300, 'hits' => ['mob', 'collider'], 'knockback_tiles' => 0, 'stun_ms' => 0],
            'ranged' => ['enabled' => true, 'projectile_emoji' => '➡️', 'cooldown_ms' => 200, 'direction' => 'facing',
                'ammo' => ['type' => 'infinite', 'start' => 10, 'max' => 30],
                'charge' => ['enabled' => false, 'charge_ms' => 800, 'damage_multiplier' => 2.0]],
            'stomp' => ['enabled' => true, 'damage' => 1, 'stun_ms' => 0, 'morph_to' => null, 'bounce_power' => -3.5],
            'special' => ['enabled' => false, 'type' => 'screen_clear', 'charges_max' => 3,
                'recharge_by_pickup' => 'bomb', 'action' => 'action'],
        ];
        $this->assertSame([], CombatValidator::validatePlayerAttacks($pa));
    }

    public function testMeleeRangesAndEnums(): void
    {
        $errors = CombatValidator::validatePlayerAttacks(['melee' => [
            'enabled' => true, 'damage' => 100, 'range_tiles' => 0, 'shape' => 'cone',
            'cooldown_ms' => 10, 'hits' => ['ghost'], 'knockback_tiles' => 9, 'stun_ms' => 5000,
        ]]);
        $this->assertTrue(count($errors) >= 7, 'каждое поле вне схемы даёт ошибку: ' . implode('; ', $errors));
        $this->assertContains('melee.shape', $errors[0] . json_encode($errors));
    }

    public function testRangedAmmoStartMustNotExceedMax(): void
    {
        $ok = CombatValidator::validatePlayerAttacks(['ranged' => [
            'enabled' => true, 'cooldown_ms' => 200, 'direction' => 'facing',
            'ammo' => ['type' => 'count', 'start' => 10, 'max' => 30]]]);
        $this->assertSame([], $ok);
        $bad = CombatValidator::validatePlayerAttacks(['ranged' => [
            'enabled' => true, 'cooldown_ms' => 200, 'direction' => 'facing',
            'ammo' => ['type' => 'count', 'start' => 40, 'max' => 30]]]);
        $this->assertCount(1, $bad);
        $this->assertContains('start', $bad[0]);
    }

    public function testStompBouncePowerNegativeRange(): void
    {
        $this->assertSame([], CombatValidator::validatePlayerAttacks(['stomp' => ['enabled' => true, 'bounce_power' => -3.5]]));
        $errors = CombatValidator::validatePlayerAttacks(['stomp' => ['enabled' => true, 'bounce_power' => 2]]);
        $this->assertCount(1, $errors);
    }

    public function testSpecialTypeEnumAndCharges(): void
    {
        $errors = CombatValidator::validatePlayerAttacks(['special' => ['enabled' => true, 'type' => 'nuke', 'charges_max' => 0]]);
        $this->assertCount(2, $errors);
    }

    public function testPlayerFields(): void
    {
        $this->assertSame([], CombatValidator::validatePlayer([
            'lives' => 3, 'health_max' => 5, 'invulnerability_ms' => 1500,
            'dodge' => ['enabled' => true, 'duration_ms' => 300, 'invulnerability_ms' => 200, 'cooldown_ms' => 800],
            'parry' => ['enabled' => true, 'window_ms' => 150, 'cooldown_ms' => 500],
        ]));
        $errors = CombatValidator::validatePlayer([
            'lives' => 10, 'health_max' => 0, 'invulnerability_ms' => 6000,
            'dodge' => ['duration_ms' => 50], 'parry' => ['window_ms' => 1000],
        ]);
        $this->assertCount(5, $errors);
    }

    public function testScoringFieldsAndComboCrossCheck(): void
    {
        $this->assertSame([], CombatValidator::validateScoring([
            'on_enemy_kill' => 100, 'on_pickup' => 10,
            'combo' => ['enabled' => true, 'window_ms' => 2000, 'multiplier_per_hit' => 0.5, 'max_multiplier' => 4.0],
            'time_bonus' => ['enabled' => true, 'base' => 500, 'per_second_remaining' => 5],
        ]));
        // multiplier_per_hit > max_multiplier
        $errors = CombatValidator::validateScoring(['combo' => ['multiplier_per_hit' => 5.0, 'max_multiplier' => 2.0]]);
        $this->assertTrue(count($errors) >= 2, implode('; ', $errors));
        $bad = CombatValidator::validateScoring(['on_enemy_kill' => -1, 'on_pickup' => 5000]);
        $this->assertCount(2, $bad);
    }
}
