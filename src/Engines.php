<?php

declare(strict_types=1);

namespace App;

/**
 * Справочник движков (ТЗ блок 1 §2.2).
 */
final class Engines
{
    public const ALL = ['static-screen', 'vertical-scroll', 'horizontal-scroll'];

    /** Фикстура config/genre-defaults для движка. */
    public static function fixtureForEngine(string $engine): ?string
    {
        return match ($engine) {
            'static-screen'     => 'loderunner.json',
            'vertical-scroll'   => 'riverraid.json',
            'horizontal-scroll' => 'platformer.json',
            default             => null,
        };
    }

    public static function shortBadge(string $engine): string
    {
        return match ($engine) {
            'static-screen'     => 'static',
            'vertical-scroll'   => 'vertical',
            'horizontal-scroll' => 'horizontal',
            default             => $engine,
        };
    }
}
