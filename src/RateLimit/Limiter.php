<?php

declare(strict_types=1);

namespace App\RateLimit;

use App\Storage\FileStore;

/**
 * Файловый rate-limiting (ТЗ блок 3, §6).
 * data/rate-limit/{sha1(endpoint:ip)}.json = {"window_start": int, "count": int}.
 */
final class Limiter
{
    private const DIR = 'rate-limit';
    private const PURGE_AFTER = 3600; // ленивая чистка файлов старше 1 часа

    public function __construct(private readonly FileStore $store)
    {
    }

    public static function keyFor(string $endpoint, string $ip): string
    {
        return sha1($endpoint . ':' . $ip);
    }

    /**
     * @return array{allowed: bool, retry_after: int}
     */
    public function check(string $key, int $limit, int $windowSeconds): array
    {
        $path = self::DIR . '/' . $key . '.json';
        $now = time();
        $this->purgeOld($windowSeconds);

        $result = ['allowed' => true, 'retry_after' => 0];
        $this->store->mutate($path, function (&$data) use ($now, $limit, $windowSeconds, &$result): bool {
            if (!is_array($data) || !isset($data['window_start'])) {
                $data = ['window_start' => $now, 'count' => 1];
                $result = ['allowed' => true, 'retry_after' => 0];
                return true;
            }
            $windowStart = (int)$data['window_start'];
            if ($now - $windowStart > $windowSeconds) {
                $windowStart = $now;
                $data['count'] = 0;
            }
            $data['window_start'] = $windowStart;
            $data['count'] = (int)$data['count'] + 1;
            if ((int)$data['count'] > $limit) {
                $retry = max(1, $windowStart + $windowSeconds - $now);
                $result = ['allowed' => false, 'retry_after' => $retry];
            } else {
                $result = ['allowed' => true, 'retry_after' => 0];
            }
            return true;
        });
        return $result;
    }

    /** Ленивая чистка файлов старше 1 часа. Не частще раза в минуту. */
    private function purgeOld(int $windowSeconds): void
    {
        static $lastPurge = 0;
        $now = time();
        if ($now - $lastPurge < 60) {
            return;
        }
        $lastPurge = $now;
        $dir = $this->store->getRootDir() . '/' . self::DIR;
        if (!is_dir($dir)) {
            return;
        }
        foreach (glob($dir . '/*.json') ?: [] as $file) {
            if (filemtime((string)$file) < $now - self::PURGE_AFTER) {
                @unlink($file);
            }
        }
    }
}
