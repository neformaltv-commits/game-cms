<?php

declare(strict_types=1);

require __DIR__ . '/../_bootstrap.php';

use App\Auth\Authenticator;
use App\Auth\Csrf;
use App\Http\ClientIp;
use App\Http\Response;

// Уже авторизованных — на dashboard.
if (Authenticator::checkSession($app['config'])) {
    Response::redirect('index.php');
}

$auth = $app['auth'];
$error = '';
$ip = ClientIp::from($_SERVER, $app['config']['trusted_proxies'] ?? []);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!Csrf::validate(Csrf::tokenFromRequest())) {
        $error = 'Сессия истекла. Повторите попытку.';
    } else {
        $blocked = $app['throttle']->check($ip);
        if ($blocked['blocked']) {
            $error = 'Слишком много неудачных попыток. Повторите через '
                . ceil($blocked['retry_after'] / 60) . ' мин.';
        } else {
            $username = trim((string)($_POST['username'] ?? ''));
            $password = (string)($_POST['password'] ?? '');
            if ($auth->login($username, $password, $ip)) {
                flash_set('success', 'Вход выполнен.');
                Response::redirect('index.php');
            }
            $error = 'Неверное имя пользователя или пароль.';
        }
    }
}
?>
<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Вход — Mini CMS</title>
<link rel="stylesheet" href="/assets/admin.css">
</head>
<body class="login-page">
<main class="login-card">
    <h1>Mini CMS · Админ</h1>
    <?php if ($error !== ''): ?>
        <p class="flash flash-error"><?= htmlspecialchars($error, ENT_QUOTES) ?></p>
    <?php endif; ?>
    <form method="post" action="login.php">
        <input type="hidden" name="_csrf" value="<?= htmlspecialchars(Csrf::token(), ENT_QUOTES) ?>">
        <label>Имя пользователя
            <input type="text" name="username" autocomplete="username" required autofocus>
        </label>
        <label>Пароль
            <input type="password" name="password" autocomplete="current-password" required>
        </label>
        <button type="submit">Войти</button>
    </form>
</main>
</body>
</html>
