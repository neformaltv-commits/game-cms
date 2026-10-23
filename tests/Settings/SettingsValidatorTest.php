<?php

declare(strict_types=1);

namespace Tests\Settings;

use App\Validation\SettingsValidator;
use TestCase;

final class SettingsValidatorTest extends TestCase
{
    public function testControlsValid(): void
    {
        $controls = ['left' => ['ArrowLeft', 'KeyA'], 'right' => ['KeyD'], 'up' => ['KeyW'], 'down' => ['KeyS'], 'pause' => ['Escape'], 'start' => ['Enter']];
        $this->assertSame([], SettingsValidator::validateControls($controls, ['left', 'right', 'up', 'down', 'pause', 'start']));
    }

    public function testControlsRequiredMissing(): void
    {
        $errors = SettingsValidator::validateControls(['left' => ['KeyA']], ['left', 'right']);
        $this->assertTrue(count($errors) === 1 && str_contains($errors[0], 'right'));
    }

    public function testControlsMaxTwoKeys(): void
    {
        $errors = SettingsValidator::validateControls(['left' => ['A', 'B', 'C']], []);
        $this->assertTrue($errors !== []);
    }

    public function testControlsModifierAloneRejected(): void
    {
        $errors = SettingsValidator::validateControls(['jump' => ['ShiftLeft']], []);
        $this->assertTrue($errors !== []);
        // второй клавишей модификатор быть может
        $this->assertSame([], SettingsValidator::validateControls(['jump' => ['Space', 'ShiftLeft']], []));
    }

    public function testControlsBadCodeRejected(): void
    {
        $this->assertTrue(SettingsValidator::validateControls(['left' => ['<script>']], []) !== []);
        $this->assertTrue(SettingsValidator::validateControls(['left' => ['KeyboardFoo']], []) !== []);
        $this->assertTrue(SettingsValidator::validateControls(['unknown_action' => ['KeyQ']], []) !== []);
    }

    public function testSoundsValid(): void
    {
        $sounds = [['event' => 'dig', 'wave' => 'square', 'frequency_hz' => 440, 'frequency_end_hz' => null, 'duration_ms' => 50, 'volume' => 0.03, 'envelope' => 'decay']];
        $this->assertSame([], SettingsValidator::validateSounds($sounds));
    }

    public function testSoundsLimits(): void
    {
        // >10 записей
        $many = [];
        foreach (SettingsValidator::SOUND_EVENTS as $i => $ev) {
            $many[] = ['event' => $ev, 'wave' => 'square', 'frequency_hz' => 100 + $i, 'duration_ms' => 50, 'volume' => 0.1, 'envelope' => 'decay'];
        }
        $many[] = $many[0]; // 11-я запись + дубликат события
        $errors = SettingsValidator::validateSounds($many);
        $this->assertTrue(in_array('sounds: максимум 10 записей.', $errors, true));

        // диапазоны
        $bad = [['event' => 'hit', 'wave' => 'sine', 'frequency_hz' => 5000, 'frequency_end_hz' => null, 'duration_ms' => 0, 'volume' => 0.5, 'envelope' => 'wobble']];
        $errors = SettingsValidator::validateSounds($bad);
        $this->assertTrue(count($errors) >= 5);
    }

    public function testOverridesRangesAndEnums(): void
    {
        $this->assertSame([], SettingsValidator::validateOverrides(['speed_scale' => 1.1, 'fps' => 60, 'sprite_flicker' => true], 'static-screen'));
        $this->assertTrue(SettingsValidator::validateOverrides(['speed_scale' => 3.0], 'static-screen') !== []);   // 0.5–2.0
        $this->assertTrue(SettingsValidator::validateOverrides(['fps' => 45], 'static-screen') !== []);           // enum 30|60
        $this->assertTrue(SettingsValidator::validateOverrides(['physics' => ['gravity' => -1]], 'static-screen') !== []);
        $this->assertTrue(SettingsValidator::validateOverrides(['physics' => ['jump_power' => 5]], 'static-screen') !== []); // <= 0
        $this->assertTrue(SettingsValidator::validateOverrides(['sound_volume' => 0.5], 'static-screen') !== []); // 0..0.3
        $this->assertTrue(SettingsValidator::validateOverrides(['camera_lerp' => 'NAN'], 'horizontal-scroll') !== []);
    }

    public function testMovementViewConsistency(): void
    {
        $this->assertTrue(SettingsValidator::validateOverrides(['movement_type' => 'top_down_8dir', 'view_mode' => 'side'], 'static-screen') !== []);
        $this->assertTrue(SettingsValidator::validateOverrides(['movement_type' => 'gravity', 'view_mode' => 'top'], 'static-screen') !== []);
        $this->assertSame([], SettingsValidator::validateOverrides(['movement_type' => 'top_down_4dir', 'view_mode' => 'top'], 'static-screen'));
        $this->assertSame([], SettingsValidator::validateOverrides(['movement_type' => 'gravity', 'view_mode' => 'side'], 'static-screen'));
    }
}
