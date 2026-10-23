<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Валидация идентификаторов и slug из URL (ТЗ блок 3, §5.2).
 */
final class Id
{
    public const PATTERNS = [
        'game'    => '#^game-\d{3,}$#',
        'level'   => '#^level-\d{3,}$#',
        'emoji'   => '#^e\d{3,}$#',
        'genre'   => '#^genre-[a-z0-9-]+$#',
        'cat'     => '#^cat-[a-z0-9-]+$#',
        'user'    => '#^user-\d{3,}$#',
        'slug'    => '#^[a-z0-9][a-z0-9-]{0,78}[a-z0-9]$#',
        'aiModel' => '#^[a-z][a-z0-9_]{1,40}$#',
        'pattern' => '#^[a-z][a-z0-9_]{1,40}$#',
    ];

    public static function isValid(string $kind, string $value): bool
    {
        $pattern = self::PATTERNS[$kind] ?? null;
        if ($pattern === null) {
            throw new \InvalidArgumentException("Неизвестный kind: {$kind}");
        }
        return preg_match($pattern, $value) === 1;
    }

    /**
     * @throws \InvalidArgumentException при невалидном id (контроллер превращает в 404).
     */
    public static function require(string $kind, string $value): string
    {
        if (!self::isValid($kind, $value)) {
            throw new \InvalidArgumentException("Невалидный {$kind}: {$value}");
        }
        return $value;
    }

    /** Следующий числовой id вида prefix-NNN по списку существующих. */
    public static function next(string $prefix, array $existingIds): string
    {
        $max = 0;
        foreach ($existingIds as $id) {
            if (preg_match('#^' . preg_quote($prefix, '#') . '-(\d+)$#', (string)$id, $m)) {
                $max = max($max, (int)$m[1]);
            }
        }
        return sprintf('%s-%03d', $prefix, $max + 1);
    }
}
