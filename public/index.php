<?php

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

use App\Http\Response;

function e(mixed $v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

// Публичная часть: на этапе 1 — заглушка-витрина (каталог игр появится на этапе 4).
$genres = $app['genres']->all();
$gamesDir = $app['store']->getRootDir() . '/games';
$gameFiles = glob($gamesDir . '/*.json') ?: [];
?>
<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Mini CMS — 8-битные игры</title>
<link rel="stylesheet" href="/assets/site.css">
</head>
<body>
<header class="site-header">
    <a class="logo" href="/">MINI·CMS</a>
    <nav><a href="/admin/">Админ</a></nav>
</header>
<main>
    <h1>Каталог игр</h1>
    <?php if ($gameFiles === []): ?>
        <p>Игр пока нет. Создайте жанры в <a href="/admin/genres.php">админке</a> и опубликуйте первую игру.</p>
    <?php else: ?>
        <ul class="games">
        <?php foreach ($gameFiles as $f):
            $g = json_decode((string)file_get_contents((string)$f), true);
            if (!is_array($g)) { continue; } ?>
            <li><a href="/game.php?id=<?= e($g['id'] ?? '') ?>"><?= e($g['title'] ?? basename((string)$f)) ?></a></li>
        <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <h2>Жанры</h2>
    <ul class="genres">
    <?php foreach ($genres as $g): ?>
        <li><?= e($g['name']) ?> <small>(<?= e($g['engine']) ?>)</small></li>
    <?php endforeach; ?>
    </ul>
</main>
</body>
</html>
<?php
