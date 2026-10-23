<?php

declare(strict_types=1);

/**
 * Общая шапка/подвал админ-страниц + мелкие хелперы. Подключается после _bootstrap.php.
 */

use App\Auth\Csrf;

function e(mixed $v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(Csrf::token()) . '">';
}

function admin_header(string $title, string $active = ''): void
{
    $user = \App\Auth\Authenticator::currentUser() ?? ['username' => '?'];
    ?>
<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?> — Mini CMS</title>
<link rel="stylesheet" href="/assets/admin.css">
</head>
<body class="admin">
<header class="topbar">
    <span class="brand">Mini CMS</span>
    <nav>
        <a href="/admin/index.php" class="<?= $active === 'dashboard' ? 'active' : '' ?>">Обзор</a>
        <a href="/admin/genres.php" class="<?= $active === 'genres' ? 'active' : '' ?>">Жанры</a>
        <a href="/admin/games.php" class="<?= $active === 'games' ? 'active' : '' ?>">Игры</a>
        <a href="/admin/categories.php" class="<?= $active === 'categories' ? 'active' : '' ?>">Категории</a>
        <a href="/admin/references.php" class="<?= $active === 'references' ? 'active' : '' ?>">Референсы</a>
        <a href="/admin/ai-models.php" class="<?= $active === 'ai-models' ? 'active' : '' ?>">AI-модели</a>
        <a href="/admin/movement-patterns.php" class="<?= $active === 'patterns' ? 'active' : '' ?>">Паттерны</a>
        <a href="/admin/users.php" class="<?= $active === 'users' ? 'active' : '' ?>">Пользователи</a>
        <a href="/admin/audit.php" class="<?= $active === 'audit' ? 'active' : '' ?>">Аудит</a>
    </nav>
    <form method="post" action="/admin/logout.php" class="logout-form">
        <?= csrf_field() ?>
        <span class="who"><?= e($user['username']) ?></span>
        <button type="submit" class="link-btn">Выйти</button>
    </form>
</header>
<main class="content">
<h1><?= e($title) ?></h1>
<?php foreach (flash_pull() as $f): ?>
    <p class="flash flash-<?= e($f['type']) ?>"><?= e($f['message']) ?></p>
<?php endforeach; ?>
<?php
}

function admin_footer(): void
{
    echo "</main>\n</body>\n</html>\n";
}
