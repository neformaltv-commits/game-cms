<?php

declare(strict_types=1);

require __DIR__ . '/../_bootstrap.php';
require __DIR__ . '/_layout.php';

$user = require_admin($app);
admin_header('Журнал аудита', 'audit');

$limit = max(10, min(500, (int)($_GET['limit'] ?? 200)));
$rows = $app['audit']->tail($limit);
?>
<p>Последние <?= count($rows) ?> записей (файл data/audit.log, ротация по размеру).</p>
<table class="table">
<thead><tr><th>Время</th><th>Действие</th><th>Цель</th><th>Пользователь</th><th>IP</th><th>Детали</th></tr></thead>
<tbody>
<?php foreach ($rows as $r): ?>
<tr>
    <td><?= e($r['ts'] ?? '') ?></td>
    <td><?= e($r['action'] ?? '') ?></td>
    <td><?= e($r['target'] ?? '') ?></td>
    <td><?= e($r['user'] ?? '') ?></td>
    <td><?= e($r['ip'] ?? '') ?></td>
    <td><code><?= e(json_encode($r['details'] ?? [], JSON_UNESCAPED_UNICODE)) ?></code></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
<?php admin_footer(); ?>
