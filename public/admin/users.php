<?php

declare(strict_types=1);

require __DIR__ . '/../_bootstrap.php';
require __DIR__ . '/_layout.php';

use App\Auth\PasswordPolicy;
use App\Http\Response;

$user = require_admin($app);
csrf_guard();

$auth = $app['auth'];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'create') {
        try {
            $auth->createUser(
                trim((string)($_POST['username'] ?? '')),
                (string)($_POST['password'] ?? ''),
                'admin',
            );
            flash_set('success', 'Пользователь создан.');
        } catch (\Throwable $e) {
            flash_set('error', $e->getMessage());
        }
        Response::redirect('users.php');
    } elseif ($action === 'change_password') {
        $errors = $auth->changePassword(
            (string)($_POST['id'] ?? ''),
            (string)($_POST['old_password'] ?? ''),
            (string)($_POST['new_password'] ?? ''),
            (string)($_POST['confirm_password'] ?? ''),
        );
        flash_set($errors === [] ? 'success' : 'error',
            $errors === [] ? 'Пароль изменён.' : implode(' ', $errors));
        Response::redirect('users.php');
    }
}

admin_header('Пользователи', 'users');
?>
<h2>Список</h2>
<table class="table">
<thead><tr><th>ID</th><th>Имя</th><th>Роль</th><th>Создан</th><th>Последний вход</th></tr></thead>
<tbody>
<?php foreach ($auth->allUsers() as $u): ?>
<tr>
    <td><?= e($u['id']) ?></td>
    <td><?= e($u['username']) ?></td>
    <td><?= e($u['role'] ?? '') ?></td>
    <td><?= e($u['created_at'] ?? '') ?></td>
    <td><?= e($u['last_login_at'] ?? '—') ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>

<h2>Создать пользователя</h2>
<form method="post" action="users.php" class="form">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="create">
    <label>Имя (3–40 символов, латиница/цифры/_-)
        <input type="text" name="username" required pattern="[a-zA-Z0-9_-]{3,40}"></label>
    <label>Пароль (мин. 10 символов — ТЗ §2.1)
        <input type="password" name="password" required minlength="10"></label>
    <button type="submit">Создать</button>
</form>

<h2>Смена собственного пароля</h2>
<form method="post" action="users.php" class="form">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="change_password">
    <input type="hidden" name="id" value="<?= e($user['id']) ?>">
    <label>Текущий пароль <input type="password" name="old_password" required></label>
    <label>Новый пароль <input type="password" name="new_password" required minlength="10"></label>
    <label>Подтверждение <input type="password" name="confirm_password" required minlength="10"></label>
    <button type="submit">Сменить пароль</button>
</form>
<?php admin_footer(); ?>
