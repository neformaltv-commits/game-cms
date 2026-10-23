<?php

declare(strict_types=1);

require __DIR__ . '/../_bootstrap.php';
require __DIR__ . '/_layout.php';

use App\Engines;
use App\Http\Response;

$user = require_admin($app);
csrf_guard();

$genres = $app['genres'];

// Действия
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'seed') {
        $r = $genres->seed();
        flash_set('success', "Seed: добавлено {$r['added']}, пропущено {$r['skipped']}"
            . ($r['conflicts'] !== [] ? ', конфликты: ' . implode(', ', $r['conflicts']) : ''));
        Response::redirect('genres.php');
    } elseif ($action === 'delete') {
        $id = (string)($_POST['id'] ?? '');
        $errors = $genres->delete($id);
        flash_set($errors === [] ? 'success' : 'error',
            $errors === [] ? "Жанр {$id} удалён." : implode(' ', $errors));
        Response::redirect('genres.php');
    } elseif ($action === 'change-engine') {
        $id = (string)($_POST['id'] ?? '');
        $engine = (string)($_POST['engine'] ?? '');
        try {
            $affected = $genres->changeEngine($id, $engine);
            flash_set('success', "Движок изменён. Затронуто игр: {$affected}.");
        } catch (\Throwable $e) {
            flash_set('error', $e->getMessage());
        }
        Response::redirect('genres.php');
    } elseif ($action === 'save') {
        $data = [
            'id'          => (string)($_POST['id'] ?? ''),
            'slug'        => \App\Support\Slug::generate((string)($_POST['name'] ?? '')),
            'name'        => trim((string)($_POST['name'] ?? '')),
            'description' => trim((string)($_POST['description'] ?? '')),
            'engine'      => (string)($_POST['engine'] ?? ''),
            'sort_order'  => (int)($_POST['sort_order'] ?? 0),
        ];
        $isNew = $data['id'] === '';
        $errors = $genres->save($data, $isNew);
        if ($errors !== []) {
            // Сохраняем введённое для повторного рендера формы
            $_SESSION['form_genre'] = $data;
            flash_set('error', implode(' ', $errors));
        } else {
            unset($_SESSION['form_genre']);
            flash_set('success', $isNew ? 'Жанр создан.' : 'Жанр обновлён.');
        }
        Response::redirect('genres.php');
    }
}

$editing = null;
if (isset($_GET['edit'])) {
    $editing = $genres->find((string)$_GET['edit']);
}
$form = $_SESSION['form_genre'] ?? ($editing !== null ? [
    'id' => $editing['id'], 'slug' => $editing['slug'], 'name' => $editing['name'],
    'description' => $editing['description'] ?? '', 'engine' => $editing['engine'],
    'sort_order' => $editing['sort_order'] ?? 0,
] : null);
unset($_SESSION['form_genre']);

admin_header('Жанры', 'genres');
?>
<h2>Список жанров</h2>
<form method="post" action="genres.php" style="display:inline">
    <?= csrf_field() ?><input type="hidden" name="action" value="seed">
    <button type="submit">Seed 5 базовых жанров из фикстур</button>
</form>
<table class="table">
<thead><tr><th>ID</th><th>Название</th><th>Slug</th><th>Движок</th><th>Порядок</th><th></th></tr></thead>
<tbody>
<?php foreach ($genres->all() as $g): ?>
<tr>
    <td><?= e($g['id']) ?></td>
    <td><?= e($g['name']) ?></td>
    <td><?= e($g['slug']) ?></td>
    <td><?= e($g['engine']) ?></td>
    <td><?= e($g['sort_order'] ?? 0) ?></td>
    <td>
        <a href="genres.php?edit=<?= e($g['id']) ?>">Ред.</a>
        <form method="post" action="genres.php" style="display:inline" onsubmit="return confirm('Удалить жанр?')">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="<?= e($g['id']) ?>">
            <button type="submit" class="link-btn danger">Удалить</button>
        </form>
    </td>
</tr>
<?php endforeach; ?>
</tbody>
</table>

<h2><?= $form !== null && ($form['id'] ?? '') !== '' ? 'Редактирование жанра' : 'Новый жанр' ?></h2>
<form method="post" action="genres.php" class="form">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="id" value="<?= e($form['id'] ?? '') ?>">
    <label>Название <input type="text" name="name" required value="<?= e($form['name'] ?? '') ?>"></label>
    <label>Описание <textarea name="description" rows="3"><?= e($form['description'] ?? '') ?></textarea></label>
    <label>Движок
        <select name="engine">
            <?php foreach (Engines::ALL as $eng): ?>
                <option value="<?= e($eng) ?>" <?= ($form['engine'] ?? '') === $eng ? 'selected' : '' ?>><?= e($eng) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label>Порядок <input type="number" name="sort_order" value="<?= e($form['sort_order'] ?? 0) ?>"></label>
    <button type="submit">Сохранить</button>
    <?php if (($form['id'] ?? '') !== ''): ?>
        <a href="genres.php">Отмена</a>
    <?php endif; ?>
</form>
<?php admin_footer(); ?>
