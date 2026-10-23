<?php

declare(strict_types=1);

namespace App\Audit;

use App\Storage\FileStore;

/**
 * Аудит в формате JSON Lines (ТЗ блок 3, §11).
 * Ротация при достижении 10 MB, хранение последних N файлов (config.audit_rotation_keep, по умолчанию 14).
 */
class Logger
{
    private const MAX_BYTES = 10 * 1024 * 1024;

    public function __construct(
        private readonly FileStore $store,
        private readonly string $fileName = 'audit.log',
        private readonly int $rotationKeep = 14,
    ) {
    }

    /**
     * Записывает событие. Пароли и секреты в details попадать не должны —
     * поле `details` фильтруется рекурсивно от ключей password/secret/token.
     */
    public function log(string $action, string $target = '', array $details = [], string $user = ''): void
    {
        $entry = [
            'ts'      => gmdate('Y-m-d\TH:i:s\Z'),
            'user'    => $user,
            'action'  => $action,
            'target'  => $target,
            'details' => self::sanitizeDetails($details),
        ];
        $line = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($line === false) {
            return; // Нечего логировать о самом себе.
        }

        $this->rotateIfNeeded();

        $path = $this->store->getRootDir() . '/' . $this->fileName;
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $h = fopen($path, 'a');
        if ($h === false) {
            return;
        }
        if (flock($h, LOCK_EX)) {
            fwrite($h, $line . "\n");
            fflush($h);
            flock($h, LOCK_UN);
        }
        fclose($h);
    }

    /**
     * Читает последние $limit записей (для /admin/log и дашборда).
     * @return list<array<string,mixed>>
     */
    public function tail(int $limit = 200): array
    {
        $path = $this->store->getRootDir() . '/' . $this->fileName;
        if (!file_exists($path)) {
            return [];
        }
        $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return [];
        }
        $lines = array_slice($lines, -$limit);
        $out = [];
        foreach (array_reverse($lines) as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $out[] = $decoded;
            }
        }
        return $out;
    }

    private function rotateIfNeeded(): void
    {
        $path = $this->store->getRootDir() . '/' . $this->fileName;
        clearstatcache(true, $path);
        if (!file_exists($path) || filesize($path) < self::MAX_BYTES) {
            return;
        }
        $rotated = $this->fileName === 'audit.log'
            ? 'audit-' . gmdate('Ymd-His') . '.log'
            : preg_replace('/\.log$/', '-' . gmdate('Ymd-His') . '.log', $this->fileName);
        @rename($path, dirname($path) . '/' . $rotated);

        // Удаляем старые ротированные файлы, оставляем rotationKeep.
        $base = pathinfo($this->fileName, PATHINFO_FILENAME);
        $glob = glob(dirname($path) . '/' . $base . '-*.log') ?: [];
        if (count($glob) > $this->rotationKeep) {
            usort($glob, fn ($a, $b) => strcmp(basename((string)$b), basename((string)$a)));
            foreach (array_slice($glob, $this->rotationKeep) as $old) {
                @unlink($old);
            }
        }
    }

    /** @param array<mixed,mixed> $details */
    private static function sanitizeDetails(array $details): array
    {
        $out = [];
        foreach ($details as $k => $v) {
            if (is_string($k) && preg_match('/password|secret|token|pepper/i', $k)) {
                continue;
            }
            $out[$k] = is_array($v) ? self::sanitizeDetails($v) : $v;
        }
        return $out;
    }
}
