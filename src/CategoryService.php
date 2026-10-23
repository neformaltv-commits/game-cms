<?php

declare(strict_types=1);

namespace App;

use App\Audit\Logger;
use App\Storage\FileStore;
use App\Validation\CategoryValidator;

/**
 * Репозиторий+сервис категорий (ТЗ блок 2 §5, блок 4 §4).
 */
final class CategoryService
{
    public const FILE = 'categories.json';

    public function __construct(
        private readonly FileStore $store,
        private readonly Logger $audit,
    ) {
    }

    /** @return list<array<string,mixed>> */
    public function all(): array
    {
        $list = $this->store->read(self::FILE, []);
        return is_array($list) ? array_values($list) : [];
    }

    /** @return array<string,mixed>|null */
    public function find(string $id): ?array
    {
        foreach ($this->all() as $c) {
            if (($c['id'] ?? null) === $id) {
                return $c;
            }
        }
        return null;
    }

    /**
     * Дерево: список корней с вложенными children, сортировка по sort_order.
     * @return list<array<string,mixed>>
     */
    public function tree(): array
    {
        $all = $this->all();
        $byParent = [];
        foreach ($all as $c) {
            $p = $c['parent_id'] ?? null;
            $byParent[$p === null ? '' : (string)$p][] = $c;
        }
        $sortRec = function (string $parentKey) use (&$sortRec, &$byParent): array {
            $rows = $byParent[$parentKey] ?? [];
            usort($rows, static fn ($a, $b) => ((int)($a['sort_order'] ?? 0)) <=> ((int)($b['sort_order'] ?? 0)));
            foreach ($rows as &$r) {
                $r['children'] = $sortRec((string)$r['id']);
            }
            return $rows;
        };
        return $sortRec('');
    }

    /** Id всех потомков категории (рекурсивно). */
    public function descendantIds(string $id): array
    {
        $out = [];
        $stack = [$id];
        $all = $this->all();
        while ($stack !== []) {
            $cur = array_pop($stack);
            foreach ($all as $c) {
                if ((string)($c['parent_id'] ?? '') === (string)$cur) {
                    $out[] = (string)$c['id'];
                    $stack[] = (string)$c['id'];
                }
            }
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,string> ошибки валидации
     */
    public function save(array $data, bool $isNew): array
    {
        $all = $this->all();
        $existingSlugs = [];
        foreach ($all as $c) {
            if (!$isNew && ($c['id'] ?? null) === ($data['id'] ?? null)) {
                continue;
            }
            $existingSlugs[] = (string)($c['slug'] ?? '');
        }
        $errors = CategoryValidator::validate($data, $all, $existingSlugs);
        if ($errors !== []) {
            return $errors;
        }

        $savedId = '';
        $this->store->mutate(self::FILE, function (&$list) use ($data, $isNew, &$savedId): bool {
            if (!is_array($list)) {
                $list = [];
            }
            $now = gmdate('Y-m-d\TH:i:s\Z');
            $parentId = ($data['parent_id'] ?? null);
            $parentId = ($parentId === null || $parentId === '') ? null : (string)$parentId;

            if ($isNew) {
                $newId = 'cat-' . (string)$data['slug'];
                foreach ($list as $c) {
                    if (($c['id'] ?? null) === $newId) {
                        throw new \DomainException("Категория с таким id уже существует: {$newId}");
                    }
                }
                $sort = (int)$data['sort_order'];
                $sort = self::freeSortOrder($list, $parentId, $sort);
                $list[] = [
                    'id'          => $newId,
                    'slug'        => $data['slug'],
                    'name'        => $data['name'],
                    'description' => (string)($data['description'] ?? ''),
                    'parent_id'   => $parentId,
                    'sort_order'  => $sort,
                    'created_at'  => $now,
                    'updated_at'  => $now,
                ];
                $savedId = $newId;
            } else {
                $changed = [];
                foreach ($list as &$c) {
                    if (($c['id'] ?? null) === ($data['id'] ?? null)) {
                        foreach (['name', 'description', 'parent_id', 'sort_order'] as $f) {
                            $oldV = $c[$f] ?? null;
                            $newV = $f === 'sort_order' ? (int)($data[$f] ?? 0) : ($f === 'parent_id' ? $parentId : ($data[$f] ?? null));
                            if ((string)$oldV !== (string)$newV) {
                                $changed[] = $f;
                            }
                        }
                        $c['name'] = $data['name'];
                        $c['description'] = (string)($data['description'] ?? '');
                        $c['parent_id'] = $parentId;
                        $c['sort_order'] = self::freeSortOrder($list, $parentId, (int)$data['sort_order'], (string)$c['id']);
                        $c['updated_at'] = $now;
                    }
                }
                $savedId = (string)$data['id'];
                $this->auditPending = ['changed' => $changed];
            }
            return true;
        });

        $this->audit->log(
            $isNew ? 'category.create' : 'category.update',
            $savedId,
            $isNew ? ['parent_id' => $data['parent_id'] ?? null] : ($this->auditPending ?? [])
        );
        return [];
    }

    /** @var array<string,mixed>|null служебное поле для деталей аудита из мутатора */
    private ?array $auditPending = null;

    /**
     * Свободный sort_order в рамках родителя: при коллизии — автоинкремент (ТЗ §4.3).
     * @param list<array<string,mixed>> $list
     */
    public static function freeSortOrder(array $list, ?string $parentId, int $wanted, string $excludeId = ''): int
    {
        $taken = [];
        foreach ($list as $c) {
            $p = $c['parent_id'] ?? null;
            if ((string)($p ?? '') === (string)($parentId ?? '') && (string)($c['id'] ?? '') !== $excludeId) {
                $taken[(int)($c['sort_order'] ?? 0)] = true;
            }
        }
        $sort = $wanted;
        while (isset($taken[$sort])) {
            $sort++;
        }
        return $sort;
    }

    /**
     * Изменение порядка внутри родителя: обмен sort_order с соседом (ТЗ §4.5).
     * @return bool false, если двигать некуда
     */
    public function reorder(string $id, string $direction): bool
    {
        if (!in_array($direction, ['up', 'down'], true)) {
            throw new \InvalidArgumentException('direction: up|down');
        }
        $moved = false;
        $newPosition = -1;
        $this->store->mutate(self::FILE, function (&$list) use ($id, $direction, &$moved, &$newPosition): bool {
            $target = null;
            foreach ($list as $c) {
                if (($c['id'] ?? null) === $id) {
                    $target = $c;
                    break;
                }
            }
            if ($target === null) {
                return false;
            }
            $siblings = [];
            foreach ($list as $c) {
                if ((string)($c['parent_id'] ?? '') === (string)($target['parent_id'] ?? '')) {
                    $siblings[] = $c;
                }
            }
            usort($siblings, static fn ($a, $b) => ((int)$a['sort_order']) <=> ((int)$b['sort_order']));
            $idx = array_search($id, array_column($siblings, 'id'), true);
            $swapWith = $direction === 'up' ? $idx - 1 : $idx + 1;
            if ($swapWith < 0 || $swapWith >= count($siblings)) {
                return false;
            }
            $a = $siblings[$idx]['id'];
            $b = $siblings[$swapWith]['id'];
            $soA = (int)$siblings[$idx]['sort_order'];
            $soB = (int)$siblings[$swapWith]['sort_order'];
            foreach ($list as &$c) {
                if (($c['id'] ?? null) === $a) {
                    $c['sort_order'] = $soB;
                }
                if (($c['id'] ?? null) === $b) {
                    $c['sort_order'] = $soA;
                }
            }
            $moved = true;
            $newPosition = $swapWith + 1;
            return true;
        });
        if ($moved) {
            $this->audit->log('category.reorder', $id, ['direction' => $direction, 'new_position' => $newPosition]);
        }
        return $moved;
    }

    /**
     * Удаление с проверкой дочерних и использования в играх (ТЗ §4.4, §6).
     * @return list<string> ошибки (пусто — удалено)
     */
    public function delete(string $id): array
    {
        $children = [];
        foreach ($this->all() as $c) {
            if ((string)($c['parent_id'] ?? '') === $id) {
                $children[] = (string)($c['slug'] ?? $c['id']);
            }
        }
        if ($children !== []) {
            return ['У категории есть дочерние: ' . implode(', ', $children) . '. Сначала удалите или переназначьте их.'];
        }
        $games = $this->gamesUsing($id);
        if ($games !== []) {
            return ['Категория используется играми: ' . implode(', ', $games) . '.'];
        }
        $this->store->mutate(self::FILE, function (&$list) use ($id): bool {
            $list = array_values(array_filter($list, static fn ($c) => ($c['id'] ?? null) !== $id));
            return true;
        });
        $this->audit->log('category.delete', $id);
        return [];
    }

    /** @return list<string> */
    public function gamesUsing(string $catId): array
    {
        $out = [];
        $dir = $this->store->getRootDir() . '/games';
        foreach (glob($dir . '/*.json') ?: [] as $file) {
            $game = json_decode((string)file_get_contents((string)$file), true);
            if (is_array($game) && in_array($catId, (array)($game['category_ids'] ?? []), true)) {
                $out[] = (string)($game['id'] ?? basename((string)$file, '.json'));
            }
        }
        return $out;
    }
}
