<?php

declare(strict_types=1);

namespace App;

use App\Audit\Logger;
use App\Storage\FileStore;
use App\Support\Id;
use App\Validation\GenreValidator;

/**
 * Репозиторий+сервис жанров (ТЗ блок 2 §4, блок 4 §3).
 */
final class GenreService
{
    public const FILE = 'genres.json';

    public function __construct(
        private readonly FileStore $store,
        private readonly Logger $audit,
        private readonly string $configDir,
    ) {
    }

    /** @return list<array<string,mixed>> отсортированные по sort_order */
    public function all(): array
    {
        $list = $this->store->read(self::FILE, []);
        if (!is_array($list)) {
            $list = [];
        }
        usort($list, static fn ($a, $b) => ((int)($a['sort_order'] ?? 0)) <=> ((int)($b['sort_order'] ?? 0)));
        return array_values($list);
    }

    /** @return array<string,mixed>|null */
    public function find(string $id): ?array
    {
        foreach ($this->all() as $g) {
            if (($g['id'] ?? null) === $id) {
                return $g;
            }
        }
        return null;
    }

    /**
     * Создание/обновление с валидацией. При создании генерирует id.
     * @param array<string,mixed> $data
     * @return array<string,string> ошибки валидации (пусто — успех)
     */
    public function save(array $data, bool $isNew): array
    {
        $existingSlugs = [];
        foreach ($this->all() as $g) {
            if (!$isNew && ($g['id'] ?? null) === ($data['id'] ?? null)) {
                continue; // не сравниваем slug с самим собой
            }
            $existingSlugs[] = (string)($g['slug'] ?? '');
        }
        $errors = GenreValidator::validate($data, $existingSlugs);
        if ($errors !== []) {
            return $errors;
        }

        $changed = [];
        $this->store->mutate(self::FILE, function (&$list) use ($data, $isNew, &$changed): bool {
            if (!is_array($list)) {
                $list = [];
            }
            $now = gmdate('Y-m-d\TH:i:s\Z');
            if ($isNew) {
                // Иммутабельный человекочитаемый id: genre-{slug} (совпадает со схемой ТЗ).
                $newId = 'genre-' . (string)$data['slug'];
                foreach ($list as $g) {
                    if (($g['id'] ?? null) === $newId) {
                        throw new \DomainException("Жанр с таким id уже существует: {$newId}");
                    }
                }
                $record = [
                    'id'              => $newId,
                    'slug'            => $data['slug'],
                    'name'            => $data['name'],
                    'description'     => (string)($data['description'] ?? ''),
                    'engine'          => $data['engine'],
                    'sort_order'      => (int)$data['sort_order'],
                    'created_at'      => $now,
                    'updated_at'      => $now,
                    'engine_config'   => $data['engine_config'] ?? $this->defaultEngineConfig((string)$data['engine']),
                    'default_legend'  => $data['default_legend'] ?? $this->defaultLegend((string)$data['engine']),
                ];
                $defaults = $this->engineDefaults((string)$data['engine']);
                $record['engine_config']['default_controls'] = $data['default_controls'] ?? $defaults['controls'];
                $record['engine_config']['default_sounds']   = $data['default_sounds'] ?? $defaults['sounds'];
                $list[] = $record;
                $changed = ['__id' => $newId];
            } else {
                foreach ($list as &$g) {
                    if (($g['id'] ?? null) === ($data['id'] ?? null)) {
                        foreach (['name', 'description', 'sort_order'] as $f) {
                            if (array_key_exists($f, $data) && (string)($g[$f] ?? '') !== (string)$data[$f]) {
                                $changed[] = $f;
                            }
                        }
                        $g['name'] = $data['name'];
                        $g['description'] = (string)($data['description'] ?? '');
                        $g['sort_order'] = (int)$data['sort_order'];
                        $g['updated_at'] = $now;
                        if (isset($data['default_legend'])) {
                            $g['default_legend'] = $data['default_legend'];
                            $changed[] = 'default_legend';
                        }
                    }
                }
                $changed['__id'] = (string)$data['id'];
            }
            return true;
        });

        $id = (string)($changed['__id'] ?? $data['id'] ?? '');
        unset($changed['__id']);
        $this->audit->log(
            $isNew ? 'genre.create' : 'genre.update',
            $id,
            $isNew ? ['engine' => (string)$data['engine']] : ['changed' => $changed]
        );
        return [];
    }

    /**
     * Смена движка: перезапись engine_config/default_controls/default_sounds фикстурой
     * нового движка (ТЗ блок 4 §3.3.2). default_legend НЕ трогаем здесь — редактор легенды отдельный.
     * @return int количество затронутых игр
     */
    public function changeEngine(string $id, string $newEngine): int
    {
        $genre = $this->find($id);
        if ($genre === null) {
            throw new \DomainException("Жанр не найден: {$id}");
        }
        if (!in_array($newEngine, \App\Engines::ALL, true)) {
            throw new \InvalidArgumentException("Неизвестный движок: {$newEngine}");
        }
        $old = (string)$genre['engine'];
        $affected = $this->countGamesUsing($id);

        $this->store->mutate(self::FILE, function (&$list) use ($id, $newEngine): bool {
            foreach ($list as &$g) {
                if (($g['id'] ?? null) === $id) {
                    $g['engine'] = $newEngine;
                    $g['engine_config'] = $this->defaultEngineConfig($newEngine);
                    $defs = $this->engineDefaults($newEngine);
                    $g['engine_config']['default_controls'] = $defs['controls'];
                    $g['engine_config']['default_sounds'] = $defs['sounds'];
                    $g['default_legend'] = $this->defaultLegend($newEngine);
                    $g['updated_at'] = gmdate('Y-m-d\TH:i:s\Z');
                }
            }
            return true;
        });

        $this->audit->log('genre.engine_change', $id, [
            'old' => $old, 'new' => $newEngine, 'affected_games' => $affected,
        ]);
        return $affected;
    }

    /**
     * Удаление с проверкой ссылочной целостности (ТЗ блок 4 §3.5, §6).
     * @return list<string> ошибки (пусто — удалено)
     */
    public function delete(string $id): array
    {
        $using = $this->gamesUsing($id);
        if ($using !== []) {
            return ['Жанр используется играми: ' . implode(', ', $using) . '. Сначала переназначьте или удалите эти игры.'];
        }
        $this->store->mutate(self::FILE, function (&$list) use ($id): bool {
            $list = array_values(array_filter(
                $list,
                static fn ($g) => ($g['id'] ?? null) !== $id
            ));
            return true;
        });
        $this->audit->log('genre.delete', $id);
        return [];
    }

    public function countGamesUsing(string $id): int
    {
        return count($this->gamesUsing($id));
    }

    /** @return list<string> id/slug игр, использующих жанр */
    public function gamesUsing(string $id): array
    {
        $out = [];
        $dir = $this->store->getRootDir() . '/games';
        foreach (glob($dir . '/*.json') ?: [] as $file) {
            $game = json_decode((string)file_get_contents((string)$file), true);
            if (is_array($game) && ($game['genre_id'] ?? null) === $id) {
                $out[] = (string)($game['id'] ?? basename((string)$file, '.json'));
            }
        }
        return $out;
    }

    /**
     * Seed пяти жанров из фикстур (ТЗ блок 4 §3.2). Идемпотентен.
     * @return array{added:int, skipped:int, conflicts:list<string>}
     */
    public function seed(): array
    {
        $fixtures = ['loderunner.json', 'arcade-adventure.json', 'platformer.json', 'myth.json', 'riverraid.json'];
        $result = ['added' => 0, 'skipped' => 0, 'conflicts' => []];

        $this->store->mutate(self::FILE, function (&$list) use ($fixtures, &$result): bool {
            if (!is_array($list)) {
                $list = [];
            }
            foreach ($fixtures as $f) {
                $path = $this->configDir . '/genre-defaults/' . $f;
                if (!is_file($path)) {
                    continue;
                }
                $fixture = json_decode((string)file_get_contents($path), true);
                if (!is_array($fixture) || !isset($fixture['id'])) {
                    continue;
                }
                $found = null;
                foreach ($list as $g) {
                    if (($g['id'] ?? null) === $fixture['id']) {
                        $found = $g;
                        break;
                    }
                }
                if ($found !== null) {
                    if (($found['engine'] ?? null) !== ($fixture['engine'] ?? null)) {
                        $result['conflicts'][] = (string)$fixture['id'];
                        $this->audit->log('genre.seed_conflict', (string)$fixture['id'], [
                            'expected_engine' => (string)($fixture['engine'] ?? ''),
                            'existing_engine' => (string)($found['engine'] ?? ''),
                        ]);
                    }
                    $result['skipped']++;
                    continue;
                }
                $fixture['created_at'] = $fixture['created_at'] ?? gmdate('Y-m-d\TH:i:s\Z');
                $fixture['updated_at'] = gmdate('Y-m-d\TH:i:s\Z');
                $list[] = $fixture;
                $result['added']++;
            }
            return true;
        });

        $this->audit->log('genre.seed', '', ['added' => $result['added'], 'skipped' => $result['skipped']]);
        return $result;
    }

    /** @return array<string,mixed> */
    public function defaultEngineConfig(string $engine): array
    {
        $fixture = $this->fixtureFile($engine);
        $cfg = $fixture['engine_config'] ?? [];
        unset($cfg['default_controls'], $cfg['default_sounds']);
        return $cfg;
    }

    /** @return array<string,mixed> */
    public function defaultLegend(string $engine): array
    {
        $fixture = $this->fixtureFile($engine);
        return (array)($fixture['default_legend'] ?? []);
    }

    /** @return array{controls: array<string,mixed>, sounds: list<array<string,mixed>>} */
    public function engineDefaults(string $engine): array
    {
        $cfg = $this->fixtureFile($engine)['engine_config'] ?? [];
        return [
            'controls' => (array)($cfg['default_controls'] ?? []),
            'sounds'   => (array)($cfg['default_sounds'] ?? []),
        ];
    }

    /** @return array<string,mixed> */
    private function fixtureFile(string $engine): array
    {
        $name = \App\Engines::fixtureForEngine($engine);
        if ($name === null) {
            return [];
        }
        $data = json_decode((string)file_get_contents($this->configDir . '/genre-defaults/' . $name), true);
        return is_array($data) ? $data : [];
    }
}
