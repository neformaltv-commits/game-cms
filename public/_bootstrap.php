<?php

declare(strict_types=1);

/**
 * Общая загрузка приложения для всех веб-точек входа (public/*.php, public/admin/*.php).
 * Типовая плоская структура: каждый PHP-файл в public/ — самостоятельная страница.
 */

$root = dirname(__DIR__);

require $root . '/src/autoload.php';
require $root . '/src/bootstrap.php';

$app = \App\services($root);

// Заголовки безопасности до любого вывода (ТЗ блок 3 §9.4, §10).
\App\Http\Response::securityHeaders(
    https: (($_SERVER['HTTPS'] ?? '') !== '' && $_SERVER['HTTPS'] !== 'off'),
    isAdmin: str_starts_with((string)($_SERVER['SCRIPT_NAME'] ?? ''), '/admin/')
        || str_contains((string)($_SERVER['SCRIPT_NAME'] ?? ''), '/admin/'),
);

// Сессия с безопасными cookie (ТЗ блок 3 §2.2–2.3).
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'domain' => '',
    'secure' => (($_SERVER['HTTPS'] ?? '') !== '' && $_SERVER['HTTPS'] !== 'off'),
    'httponly' => true,
    'samesite' => 'Lax',
]);
ini_set('session.use_strict_mode', '1');
ini_set('session.gc_maxlifetime', (string)($app['config']['session_timeout'] ?? 3600));
session_name('minicms_session');
session_start();

// Простейший роутер flash-сообщений.
function flash_set(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function flash_pull(): array
{
    $all = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $all;
}

/** Защита страницы: требует активную сессию администратора, иначе redirect на login. */
function require_admin(array $services): array
{
    if (!\App\Auth\Authenticator::checkSession($services['config'])) {
        \App\Http\Response::redirect('login.php?expired=0');
    }
    $user = \App\Auth\Authenticator::currentUser();
    if ($user === null) {
        \App\Http\Response::redirect('login.php');
    }
    return $user;
}

/** Проверка CSRF для POST-запросов; при ошибке — 403 и выход. */
function csrf_guard(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        return;
    }
    if (!\App\Auth\Csrf::validate(\App\Auth\Csrf::tokenFromRequest())) {
        \App\Http\Response::forbidden('CSRF проверка не пройдена. Обновите страницу и попробуйте снова.');
        exit;
    }
}
