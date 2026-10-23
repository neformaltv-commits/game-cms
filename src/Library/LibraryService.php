<?php

declare(strict_types=1);

namespace App\Library;

use App\Storage\FileStore;
use App\Validation\LibParamValidator;

/**
 * Общая библиотека записей: AI-модели (data/ai-models.json) и
 * паттерны движения (data/movement-patterns.json). ТЗ Блок 5.
 *
 * Встроенные (custom:false) read-only, создаются только seed-командами
 * из фикстур config/ai-defaults|pattern-defaults (по категориям).
 */
final class LibraryService
{
    public const KIND_AI = 'ai';
    public const KIND_PATTERN = 'pattern';

    private string $file;
    private string $fixtureDir;
    private string $auditCreate;
    private string $auditUpdate;
    private string $auditDelete;
    private string $auditClone;
    private string $auditSeed;
    /** @var list<string> */
    private array $categories;

    /** @var string Корень проекта (для доступа к config/ фикстурам). */
    private string $projectRoot;

    public function __construct(
        private readonly FileStore $store,
        private readonly \App\Audit\Logger $audit,
        private readonly string $kind,
        ?string $projectRoot = null,
    ) {
        // Корень проекта по умолчанию — родитель каталога данных;
        // в тестах передаётся явный путь (tmp-каталог лежит вне дерева проекта).
        $this->projectRoot = $projectRoot ?? dirname($store->getRootDir());
        if ($kind === self::KIND_AI) {
            $this->file = 'ai-models.json';
            $this->fixtureDir = 'config/ai-defaults';
            $this->categories = LibParamValidator::AI_CATEGORIES;
            $this->auditCreate = 'ai_model.create';
            $this->auditUpdate = 'ai_model.update';
            $this->auditDelete = 'ai_model.delete';
            $this->auditClone = 'ai_model.clone';
            $this->auditSeed = 'ai_models.seed';
        } elseif ($kind === self::KIND_PATTERN) {
            $this->file = 'movement-patterns.json';
            $this->fixtureDir = 'config/pattern-defaults';
            $this->categories = LibParamValidator::PATTERN_CATEGORIES;
            $this->auditCreate = 'pattern.create';
            $this->auditUpdate = 'pattern.update';
            $this->auditDelete = 'pattern.delete';
            $this->auditClone = 'pattern.clone';
            $this->auditSeed = 'patterns.seed';
        } else {
            throw new LibraryException('Неизвестный тип библиотеки: ' . $kind);
        }
    }

    /** @return list<array<string,mixed>> все записи (порядок хранения) */
    public function all(): array
    {
        $recs = $this->store->read($this->file, []);
        return is_array($recs) ? array_values($recs) : [];
    }

    /** @return array<string,mixed>|null */
    public function find(string $id): ?array
    {
        foreach ($this->all() as $rec) {
            if (($rec['id'] ?? '') === $id) {
                return $rec;
            }
        }
        return null;
    }

    /**
     * Создание кастомной записи. Поле custom из формы игнорируется (§11 ТЗ).
     * @param array<string,mixed> $input
     * @return array<string,string> ошибки; пустой массив — успех
     */
    public function create(array $input, string $user = ''): array
    {
        $rec = $this->normalize($input, isNew: true);
        $errors = LibParamValidator::validate($rec, $this->kind === self::KIND_PATTERN);
        if (!$errors && $this->find((string)$rec['id']) !== null) {
            $errors['id'] = 'Запись с таким ID уже существует.';
        }
        if ($errors) {
            return $errors;
        }
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $rec['custom'] = true;
        $rec['created_at'] = $now;
        $rec['updated_at'] = $now;

        $this->store->mutate($this->file, static function (mixed &$c) use ($rec): bool {
            $list = is_array($c) ? array_values($c) : [];
            $list[] = $rec;
            $c = $list;
            return true;
        });
        $this->audit->log($this->auditCreate, (string)$rec['id'], [
            'category' => $rec['category'],
            'engines' => $rec['supported_engines'],
            'params_count' => count($rec['params']),
        ], $user);
        return [];
    }

    /**
     * Обновление кастомной записи. Встроенные — read-only.
     * @param array<string,mixed> $input
     * @return array<string,string> ошибки; пустой массив — успех
     */
    public function update(string $id, array $input, string $user = ''): array
    {
        $existing = $this->find($id);
        if ($existing === null) {
            return ['id' => 'Запись не найдена.'];
        }
        if (($existing['custom'] ?? false) !== true) {
            throw new LibraryException('Встроенная запись редактированию недоступна.');
        }
        $rec = $this->normalize($input, isNew: false);
        $rec['id'] = $id; // id неизменяем после создания
        $errors = LibParamValidator::validate($rec, $this->kind === self::KIND_PATTERN);
        if ($errors) {
            return $errors;
        }

        $changed = [];
        foreach (['name', 'description', 'category', 'reference', 'supported_engines', 'params'] as $f) {
            if (($existing[$f] ?? null) != ($rec[$f] ?? null)) {
                $changed[] = $f;
            }
        }
        if ($this->kind === self::KIND_PATTERN) {
            foreach (['trajectory_hint', 'on_finish'] as $f) {
                if (($existing[$f] ?? null) != ($rec[$f] ?? null)) {
                    $changed[] = $f;
                }
            }
        }

        $now = gmdate('Y-m-d\TH:i:s\Z');
        $merged = array_merge($existing, $rec, [
            'id' => $id,
            'custom' => true,
            'created_at' => $existing['created_at'] ?? $now,
            'updated_at' => $now,
        ]);

        $this->store->mutate($this->file, static function (mixed &$c) use ($id, $merged): bool {
            $list = is_array($c) ? array_values($c) : [];
            foreach ($list as &$item) {
                if (is_array($item) && ($item['id'] ?? '') === $id) {
                    $item = $merged;
                    break;
                }
            }
            $c = $list;
            return true;
        });
        $this->audit->log($this->auditUpdate, $id, ['changed' => $changed], $user);
        return [];
    }

    /** Клонирование встроенной/кастомной записи в кастомную (ТЗ §7.2). */
    public function clone(string $sourceId, string $user = ''): array
    {
        $src = $this->find($sourceId);
        if ($src === null) {
            throw new LibraryException('Исходная запись не найдена.');
        }
        $base = $sourceId . '_copy';
        $newId = $base;
        $n = 2;
        while ($this->find($newId) !== null) {
            $newId = $base . '_' . $n;
            $n++;
        }
        $rec = $src;
        $rec['id'] = $newId;
        $errors = $this->create($rec, $user);
        if ($errors) {
            return ['ok' => false, 'errors' => $errors];
        }
        $this->audit->log($this->auditClone, $newId, ['from' => $sourceId, 'to' => $newId], $user);
        return ['ok' => true, 'id' => $newId];
    }

    /**
     * Удаление кастомной неиспользуемой записи.
     * @param list<string> $usedIn игры, использующие запись (проверка на стороне вызывающего)
     */
    public function delete(string $id, array $usedIn, string $user = ''): void
    {
        $rec = $this->find($id);
        if ($rec === null) {
            throw new LibraryException('Запись не найдена.');
        }
        if (($rec['custom'] ?? false) !== true) {
            throw new LibraryException('Встроенные записи удалять нельзя.');
        }
        if ($usedIn !== []) {
            $this->audit->log($this->auditDelete . '_blocked', $id, ['used_in_games' => $usedIn], $user);
            throw new LibraryException(
                'Запись используется в играх: ' . implode(', ', $usedIn) . '. Удаление заблокировано.'
            );
        }
        $this->store->mutate($this->file, static function (mixed &$c) use ($id): bool {
            $list = is_array($c) ? array_values($c) : [];
            $list = array_values(array_filter(
                $list,
                static fn ($item) => !is_array($item) || ($item['id'] ?? '') !== $id
            ));
            $c = $list;
            return true;
        });
        $this->audit->log($this->auditDelete, $id, [], $user);
    }

    /**
     * Seed встроенных записей из фикстур по категориям (ТЗ §6). Идемпотентен.
     * @return array{added:int,skipped:int,conflicts:int}
     */
    public function seed(string $user = ''): array
    {
        $fixtures = [];
        foreach ($this->categories as $cat) {
            $path = $this->projectRoot . '/' . $this->fixtureDir . '/' . $cat . '.json';
            if (!is_file($path)) {
                throw new LibraryException('Фикстура не найдена: ' . $path);
            }
            $data = json_decode((string)file_get_contents($path), true);
            if (!is_array($data)) {
                throw new LibraryException('Фикстура не является списком: ' . $path);
            }
            foreach ($data as $rec) {
                if (is_string($rec['id'] ?? null)) {
                    $rec['category'] = $rec['category'] ?? $cat;
                    $fixtures[] = $rec;
                }
            }
        }

        $result = ['added' => 0, 'skipped' => 0, 'conflicts' => 0];
        $this->store->mutate($this->file, function (mixed &$c) use ($fixtures, &$result): bool {
            $list = is_array($c) ? array_values($c) : [];
            $index = [];
            foreach ($list as $i => $item) {
                $index[(string)($item['id'] ?? '')] = $i;
            }
            $now = gmdate('Y-m-d\TH:i:s\Z');
            foreach ($fixtures as $rec) {
                $id = (string)$rec['id'];
                if (isset($index[$id])) {
                    $existing = $list[$index[$id]];
                    if (($existing['custom'] ?? false) === true) {
                        $result['conflicts']++; // не перезаписываем пользовательское
                    } else {
                        $result['skipped']++;
                    }
                    continue;
                }
                $rec['custom'] = false;
                $rec['created_at'] = $rec['created_at'] ?? $now;
                $rec['updated_at'] = $rec['updated_at'] ?? $now;
                $index[$id] = count($list);
                $list[] = $rec;
                $result['added']++;
            }
            $c = $list;
            return $result['added'] > 0;
        });
        $this->audit->log($this->auditSeed, '', $result, $user);
        return $result;
    }

    /**
     * Приведение входных данных формы к схеме. Поля created_at/updated_at/custom
     * из входа игнорируются (защита подмены, ТЗ §11).
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    private function normalize(array $input, bool $isNew): array
    {
        $rec = [];
        $rec['id'] = trim((string)($input['id'] ?? ''));
        $rec['name'] = trim((string)($input['name'] ?? ''));
        $rec['description'] = trim((string)($input['description'] ?? ''));
        $rec['category'] = trim((string)($input['category'] ?? ''));
        $rec['reference'] = trim((string)($input['reference'] ?? ''));
        $rec['supported_engines'] = array_values(array_unique(array_map(
            static fn ($e) => trim((string)$e),
            is_array($input['supported_engines'] ?? null) ? $input['supported_engines'] : []
        )));
        $params = [];
        $rawParams = is_array($input['params'] ?? null) ? array_values($input['params']) : [];
        foreach ($rawParams as $p) {
            if (!is_array($p)) {
                continue;
            }
            $np = [
                'key' => trim((string)($p['key'] ?? '')),
                'type' => trim((string)($p['type'] ?? 'number')),
            ];
            if ($np['type'] === 'number') {
                $np['min'] = is_numeric($p['min'] ?? null) ? $p['min'] + 0 : null;
                $np['max'] = is_numeric($p['max'] ?? null) ? $p['max'] + 0 : null;
                $np['options'] = null;
            } elseif ($np['type'] === 'enum') {
                $np['min'] = null;
                $np['max'] = null;
                $np['options'] = is_array($p['options'] ?? null)
                    ? array_values(array_map(static fn ($o) => trim((string)$o), $p['options']))
                    : [];
            } else {
                $np['min'] = null;
                $np['max'] = null;
                $np['options'] = null;
            }
            $np['default'] = match ($np['type']) {
                'boolean' => filter_var($p['default'] ?? false, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? (bool)($p['default'] ?? false),
                'number' => is_numeric($p['default'] ?? null) ? $p['default'] + 0 : $p['default'] ?? null,
                default => $p['default'] ?? null,
            };
            $unit = trim((string)($p['unit'] ?? ''));
            $np['unit'] = $unit !== '' ? $unit : null;
            $params[] = $np;
        }
        $rec['params'] = $params;

        if ($this->kind === self::KIND_PATTERN) {
            $rec['trajectory_hint'] = trim((string)($input['trajectory_hint'] ?? ''));
            $rec['on_finish'] = trim((string)($input['on_finish'] ?? 'despawn'));
        }
        return $rec;
    }
}
