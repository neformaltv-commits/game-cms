<?php

declare(strict_types=1);

namespace App\Validation;

/**
 * Валидация категории и иерархии (ТЗ блок 4 §4.3): parent существует, не self,
 * без циклов, глубина ≤ 5.
 */
final class CategoryValidator
{
    public const MAX_DEPTH = 5;

    /**
     * @param array<string,mixed>                $c          валидируемая категория (может не иметь id при создании)
     * @param list<array<string,mixed>>          $all        все категории (для проверки иерархии)
     * @param list<string>                       $existingSlugs занятые slug (кроме самой категории при редактировании)
     * @return array<string,string>
     */
    public static function validate(array $c, array $all, array $existingSlugs = []): array
    {
        $errors = [];

        $slug = (string)($c['slug'] ?? '');
        if (!preg_match('#^[a-z0-9-]{2,40}$#', $slug)) {
            $errors['slug'] = 'Slug: 2–40 символов, только [a-z0-9-].';
        } elseif (in_array($slug, $existingSlugs, true)) {
            $errors['slug'] = 'Такой slug уже используется.';
        }

        $name = trim((string)($c['name'] ?? ''));
        $len = mb_strlen($name);
        if ($len < 2 || $len > 80) {
            $errors['name'] = 'Название: от 2 до 80 символов.';
        }

        if (mb_strlen((string)($c['description'] ?? '')) > 500) {
            $errors['description'] = 'Описание: не более 500 символов.';
        }

        $sort = $c['sort_order'] ?? null;
        if (!is_numeric($sort) || (int)$sort < -10000 || (int)$sort > 10000) {
            $errors['sort_order'] = 'Sort order: целое от −10000 до 10000.';
        }

        $id = (string)($c['id'] ?? '');
        $parentId = $c['parent_id'] ?? null;
        if ($parentId !== null && $parentId !== '') {
            $byId = [];
            foreach ($all as $row) {
                $byId[(string)($row['id'] ?? '')] = $row;
            }
            if ($id !== '' && $parentId === $id) {
                $errors['parent_id'] = 'Нельзя назначить категорию потомком самой себя.';
            } elseif (!isset($byId[(string)$parentId])) {
                $errors['parent_id'] = 'Родительская категория не найдена.';
            } else {
                // Цикл: подъём от родителя вверх; если встретили self — цикл.
                $cur = (string)$parentId;
                $hops = 0;
                while ($cur !== '' && isset($byId[$cur])) {
                    if ($id !== '' && $cur === $id) {
                        $errors['parent_id'] = 'Нельзя назначить категорию потомком самой себя.';
                        break;
                    }
                    $cur = (string)($byId[$cur]['parent_id'] ?? '');
                    if (++$hops > count($byId)) {
                        $errors['parent_id'] = 'Обнаружен цикл в иерархии.';
                        break;
                    }
                }
                // Глубина: 1 + глубина родителя ≤ 5.
                if (!isset($errors['parent_id'])) {
                    $depth = self::depthOf((string)$parentId, $byId);
                    if ($depth + 1 > self::MAX_DEPTH) {
                        $errors['parent_id'] = 'Превышена максимальная глубина (' . self::MAX_DEPTH . ').';
                    }
                }
            }
        }

        return $errors;
    }

    /** @param array<string,array<string,mixed>> $byId */
    public static function depthOf(string $id, array $byId): int
    {
        $depth = 1;
        $cur = $id;
        $guard = count($byId) + 1;
        while ($guard-- > 0 && isset($byId[$cur]) && ($byId[$cur]['parent_id'] ?? null)) {
            $cur = (string)$byId[$cur]['parent_id'];
            $depth++;
        }
        return $depth;
    }
}
