<?php

declare(strict_types=1);

require __DIR__ . '/../_bootstrap.php';
require __DIR__ . '/_layout.php';

use App\Engines;

$user = require_admin($app);
admin_header('Обзор', 'dashboard');

$genresCount = count($app['genres']->all());
$categoriesCount = count($app['categories']->all());
$gamesDir = $app['store']->getRootDir() . '/games';
$gamesCount = count(glob($gamesDir . '/*.json') ?: []);
$auditTail = $app['audit']->tail(10);
?>
<div class="cards">
    <div class="card"><span class="num"><?= $genresCount ?></span><br>Жанров</div>
    <div class="card"><span class="num"><?= $categoriesCount ?></span><br>Категорий</div>
    <div class="card"><span class="num"><?= $gamesCount ?></span><br>Игр</div>
</div>

<h2>Последние события аудита</h2>
<?php if ($auditTail === []): ?>
    <p>Записей пока нет.</p>
<?php else: ?>
<table class="table">
    <thead><tr><th>Время</th><th>Действие</th><th>Цель</th><th>Пользователь</th></tr></thead>
    <tbody>
    <?php foreach ($auditTail as $row): ?>
        <tr>
            <td><?= e($row['ts'] ?? '') ?></td>
            <td><?= e($row['action'] ?? '') ?></td>
            <td><?= e($row['target'] ?? '') ?></td>
            <td><?= e($row['user'] ?? '') ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>

<h2>Движки</h2>
<p><?= e(implode(', ', Engines::ALL)) ?></p>
<?php admin_footer(); ?>
