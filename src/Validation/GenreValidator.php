<?php

declare(strict_types=1);

namespace App\Validation;

/**
 * Валидация жанра (ТЗ блок 2 §4 «Правила», блок 4 §3.6).
 * @return array<string,string> карта field => message
 */
final class GenreValidator
{
    public const ENGINES = ['static-screen', 'vertical-scroll', 'horizontal-scroll'];

    /**
     * @param array<string,mixed> $g
     * @param list<string> $existingSlugs другие занятые slug
     */
    public static function validate(array $g, array $existingSlugs = []): array
    {
        $errors = [];

        $slug = (string)($g['slug'] ?? '');
        if (!preg_match('#^[a-z0-9-]{2,40}$#', $slug)) {
            $errors['slug'] = 'Slug: 2–40 символов, только [a-z0-9-].';
        } elseif (in_array($slug, $existingSlugs, true)) {
            $errors['slug'] = 'Такой slug уже используется.';
        }

        $name = trim((string)($g['name'] ?? ''));
        $len = mb_strlen($name);
        if ($len < 2 || $len > 80) {
            $errors['name'] = 'Название: от 2 до 80 символов.';
        }

        $desc = (string)($g['description'] ?? '');
        if (mb_strlen($desc) > 500) {
            $errors['description'] = 'Описание: не более 500 символов.';
        }

        $engine = (string)($g['engine'] ?? '');
        if (!in_array($engine, self::ENGINES, true)) {
            $errors['engine'] = 'Движок должен быть одним из: ' . implode(', ', self::ENGINES) . '.';
        }

        $sort = $g['sort_order'] ?? null;
        if (!is_numeric($sort) || (int)$sort < -10000 || (int)$sort > 10000) {
            $errors['sort_order'] = 'Sort order: целое от −10000 до 10000.';
        }

        return $errors;
    }
}
