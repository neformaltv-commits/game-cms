<?php

declare(strict_types=1);

namespace App\Auth;

use App\Storage\FileStore;

/**
 * CSRF-токен (ТЗ блок 3, §3).
 * Токен генерируется в сессии, не ротируется на каждый запрос,
 * ротация — только при логине. Проверка через hash_equals.
 */
final class Csrf
{
    public static function token(): string
    {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    /** Ротация токена (вызывается при успешном логине). */
    public static function rotate(): void
    {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    /**
     * Извлечение токена из запроса: $_POST['_csrf'] или заголовок X-CSRF-Token.
     */
    public static function tokenFromRequest(): ?string
    {
        $fromPost = $_POST['_csrf'] ?? null;
        if (is_string($fromPost) && $fromPost !== '') {
            return $fromPost;
        }
        $header = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
        if (is_string($header) && $header !== '') {
            return $header;
        }
        return null;
    }

    /**
     * Валидная ли пара токен-из-запроса / токен-из-сессии.
     */
    public static function validate(?string $token): bool
    {
        $sessionToken = $_SESSION['csrf_token'] ?? null;
        if (!is_string($sessionToken) || !is_string($token) || $token === '' || $sessionToken === '') {
            return false;
        }
        return hash_equals($sessionToken, $token);
    }
}
