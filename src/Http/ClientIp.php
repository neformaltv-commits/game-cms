<?php

declare(strict_types=1);

namespace App\Http;

/**
 * Определение IP клиента (ТЗ блок 3, §6.4): REMOTE_ADDR; при доверенном
 * прокси — первый недоверенный адрес из X-Forwarded-For; иначе fallback.
 */
final class ClientIp
{
    /** @param list<string> $trustedProxies */
    public static function from(array $server, array $trustedProxies = []): string
    {
        $remote = (string)($server['REMOTE_ADDR'] ?? '0.0.0.0');
        if (!filter_var($remote, FILTER_VALIDATE_IP) || $trustedProxies === []) {
            return $remote !== '' ? $remote : '0.0.0.0';
        }
        if (!in_array($remote, $trustedProxies, true)) {
            return $remote;
        }
        $xff = (string)($server['HTTP_X_FORWARDED_FOR'] ?? '');
        if ($xff === '') {
            return $remote;
        }
        $parts = array_map('trim', explode(',', $xff));
        foreach ($parts as $p) {
            if (filter_var($p, FILTER_VALIDATE_IP) && !in_array($p, $trustedProxies, true)) {
                return $p;
            }
        }
        return $remote;
    }
}
