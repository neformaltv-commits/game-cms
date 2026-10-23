<?php

declare(strict_types=1);

namespace App\Auth;

use App\Storage\FileStore;

/**
 * Защита от брутфорса по IP (ТЗ блок 3, §2.5).
 * data/throttle.json: { "sha1(ip)": {fail_count, first_fail_at, last_fail_at} }.
 * 5 неудач за 10 минут → блокировка; ленивая чистка записей старше 1 часа.
 */
final class Throttle
{
    private const FILE = 'throttle.json';
    public const MAX_FAILS = 5;
    public const WINDOW_SECONDS = 600;   // 10 минут
    private const PURGE_AFTER = 3600;    // 1 час

    public function __construct(private readonly FileStore $store)
    {
    }

    public static function key(string $ip): string
    {
        return sha1($ip);
    }

    /**
     * Заблокирован ли IP (fail_count >= 5 и окно не истекло).
     * @return array{blocked: bool, retry_after: int}
     */
    public function check(string $ip): array
    {
        $data = $this->store->read(self::FILE, []);
        $entry = $data[self::key($ip)] ?? null;
        if (!is_array($entry)) {
            return ['blocked' => false, 'retry_after' => 0];
        }
        $fails = (int)($entry['fail_count'] ?? 0);
        $first = strtotime((string)($entry['first_fail_at'] ?? '')) ?: 0;
        $elapsed = time() - $first;
        if ($fails >= self::MAX_FAILS && $elapsed < self::WINDOW_SECONDS) {
            return ['blocked' => true, 'retry_after' => self::WINDOW_SECONDS - $elapsed];
        }
        if ($fails >= self::MAX_FAILS && $elapsed >= self::WINDOW_SECONDS) {
            // Окно истекло — обнуляем счётчик.
            $this->reset($ip);
        }
        return ['blocked' => false, 'retry_after' => 0];
    }

    public function registerFailure(string $ip): void
    {
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $this->store->mutate(self::FILE, function (&$data) use ($ip, $now): bool {
            if (!is_array($data)) {
                $data = [];
            }
            self::purge($data);
            $k = self::key($ip);
            $entry = $data[$k] ?? ['fail_count' => 0, 'first_fail_at' => $now, 'last_fail_at' => $now];
            $first = strtotime((string)$entry['first_fail_at']) ?: time();
            if (time() - $first >= self::WINDOW_SECONDS) {
                // Новое окно.
                $entry = ['fail_count' => 0, 'first_fail_at' => $now, 'last_fail_at' => $now];
            }
            $entry['fail_count'] = (int)$entry['fail_count'] + 1;
            $entry['last_fail_at'] = $now;
            $data[$k] = $entry;
            return true;
        });
    }

    public function reset(string $ip): void
    {
        $this->store->mutate(self::FILE, function (&$data) use ($ip): bool {
            unset($data[self::key($ip)]);
            return true;
        });
    }

    /** Ленивая чистка: удаляем записи старше 1 часа. Вызывается под LOCK_EX внутри mutate. */
    private static function purge(array &$data): void
    {
        $cutOff = time() - self::PURGE_AFTER;
        foreach ($data as $k => $entry) {
            $last = strtotime((string)($entry['last_fail_at'] ?? '')) ?: 0;
            if ($last < $cutOff) {
                unset($data[$k]);
            }
        }
    }
}
