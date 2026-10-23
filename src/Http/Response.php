<?php

declare(strict_types=1);

namespace App\Http;

/**
 * Минимальная обвязка HTTP-ответов и заголовков безопасности
 * (ТЗ блок 3, §9.4 и §10). Статические методы — тривиальная обёртка над header().
 */
final class Response
{
    public static function securityHeaders(bool $https = false, bool $isAdmin = true): void
    {
        $csp = "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline';"
             . " img-src 'self' data:; font-src 'self'; connect-src 'self'; media-src 'self';"
             . " object-src 'none'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'";

        self::header('Content-Security-Policy: ' . $csp);
        self::header('X-Content-Type-Options: nosniff');
        self::header('X-Frame-Options: DENY');
        self::header('Referrer-Policy: same-origin');
        self::header('Permissions-Policy: camera=(), microphone=(), geolocation=(), interest-cohort=()');
        if ($https) {
            self::header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }
        self::header($isAdmin ? 'Cache-Control: no-store' : 'Cache-Control: public, max-age=3600');
    }

    public static function html(int $status, string $html): void
    {
        self::status($status);
        self::header('Content-Type: text/html; charset=utf-8');
        echo $html;
    }

    public static function json(int $status, mixed $data): void
    {
        self::status($status);
        self::header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public static function text(int $status, string $body): void
    {
        self::status($status);
        self::header('Content-Type: text/plain; charset=utf-8');
        echo $body;
    }

    public static function redirect(string $url, int $status = 302): void
    {
        self::status($status);
        self::header('Location: ' . $url);
        exit;
    }

    public static function status(int $status): void
    {
        http_response_code($status);
    }

    /** Простейший header() с защитой от повторной отправки в тестах. */
    public static function header(string $line): void
    {
        if (!headers_sent()) {
            header($line);
        }
    }

    public static function notFound(): void
    {
        self::html(404, '<!doctype html><meta charset="utf-8"><title>404</title><h1>404 — Не найдено</h1>');
    }

    public static function forbidden(string $message = 'Forbidden'): void
    {
        self::text(403, $message);
    }

    public static function tooManyRequests(int $retryAfter): void
    {
        self::status(429);
        self::header('Retry-After: ' . $retryAfter);
        self::text(429, 'Too many requests. Please try again later.');
    }
}
