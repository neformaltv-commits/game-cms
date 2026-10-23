<?php

declare(strict_types=1);

namespace App;

use App\Storage\FileStore;

/**
 * Импорт/экспорт JSON-справочников (жанры, категории) — ТЗ блок 4 §5.
 */
final class ReferenceImporter
{
    public const MAX_BYTES = 1048576; // 1 MB

    public function __construct(private readonly FileStore $store)
    {
    }

    /**
     * Валидация синтаксиса и базовой схемы импортируемого файла.
     * @return array{ok: bool, errors: list<string>, data: list<array<string,mixed>>}
     */
    public function parse(string $jsonText): array
    {
        if (strlen($jsonText) > self::MAX_BYTES)
         {
            return ['ok' => false, 'errors' => ['Файл превышает 1 MB.'], 'data' => []];
        }
        $data = json_decode($jsonText, true);
        if (!is_array($data) || !array_is_list($data)) {
            return ['ok' => false, 'errors' => ['Ожидался JSON-массив записей.'], 'data' => []];
        }
        return ['ok' => true, 'errors' => [], 'data' => $data];
    }

    /**
     * Режим «Добавить»: только новые по id; существующие пропускаются.
     * @param list<array<string,mixed>> $incoming
     * @return array{added:int, skipped:int}
     */
    public function mergeAdd(string $file, array $incoming, callable $recordValidator): array
    {
        $current = $this->store->read($file, []);
        if (!is_array($current)) {
            $current = [];
        }
        $byId = [];
        foreach ($current as $r) {
            $byId[(string)($r['id'] ?? '')] = true;
        }
        $added = 0;
        $skipped = 0;
        $newRows = [];
        foreach ($incoming as $row) {
            $id = (string)($row['id'] ?? '');
            if ($id === '' || isset($byId[$id]) || $recordValidator($row) !== []) {
                $skipped++;
                continue;
            }
            $byId[$id] = true;
            $newRows[] = $row;
            $added++;
        }
        if ($newRows !== []) {
            $this->store->mutate($file, function (&$list) use ($newRows): bool {
                foreach ($newRows as $row) {
                    $list[] = $row;
                }
                return true;
            });
        }
        return ['added' => $added, 'skipped' => $skipped];
    }

    /**
     * Режим «Заменить полностью» с бэкапом data/backups/{name}-{ts}.bak (ТЗ §5.2 п.4).
     * @param list<array<string,mixed>> $rows уже валидированные externally
     */
    public function replaceAll(string $file, array $rows): ?string
    {
        $backupPath = null;
        if ($this->store->exists($file)) {
            $backupName = 'backups/' . pathinfo($file, PATHINFO_FILENAME) . '-' . gmdate('Ymd-His') . '.bak';
            $raw = @file_get_contents($this->store->getRootDir() . '/' . $file);
            if ($raw !== false) {
                $dir = dirname($this->store->getRootDir() . '/' . $backupName);
                if (!is_dir($dir)) {
                    @mkdir($dir, 0755, true);
                }
                if (@file_put_contents($this->store->getRootDir() . '/' . $backupName, $raw) !== false) {
                    $backupPath = $backupName;
                }
            }
        }
        $this->store->write($file, $rows);
        return $backupPath;
    }
}
