<?php

declare(strict_types=1);

require __DIR__ . '/../_bootstrap.php';
require __DIR__ . '/_layout.php';

use App\Http\Response;

$user = require_admin($app);
csrf_guard();

$cats = $app['categories'];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'save') {
        $data = [
            'id'          => (string)($_POST['id'] ?? ''),
            'name'        => trim((string)($_POST['name'] ?? '')),
            'slug'        => \App\Support\Slug::make(trim((string)($_POST['name'] ?? ''))),
            'parent_id'   => (string)($_POST['parent_id'] ?? '') ?: null,
            'description' => trim((string)($_POST['description'] ?? '')),
        ];
        $isNew = $data['id'] === '';
        $errors = $cats->save($data, $isNew);
        if ($errors !== []) {
            $_SESSION['form_category'] = $data;
            flash_set('error', implode(' ', $errors));
        } else {
            unset($_SESSION['form_category']);
            flash_set('success', $isNew ? 'Категория создана.' : 'Категория обновлена.');
        }
        Response::redirect('categories.php');
    } elseif ($action === 'delete') {
        $errors = $cats->delete((string)($_POST['id'] ?? ''));
        flash_set($errors === [] ? 'success' : 'error',
            $errors === [] ? 'Категория удалена.' : implode(' ', $errors));
        Response::redirect('categories.php');
    } elseif ($action === 'move') {
        $ok = $cats->reorder((string)($_POST['id'] ?? ''), (string)($_POST['dir'] ?? 'up'));
        flash_set($ok ? 'success' : 'error', $ok ? 'Порядок изменён.' : 'Не удалось изменить порядок.');
        Response::redirect('categories.php');
    }
}

$editing = isset($_GET['edit']) ? $cats->find((string)$_GET['edit']) : null;
$form = $_SESSION['form_category'] ?? ($editing !== null ? [
    'id' => $editing['id'], 'name' => $editing['name'], 'slug' => $editing['slug'],
    'parent_id' => $editing['parent_id'] ?? null, 'description' => $editing['description'] ?? '',
] : null);
unset($_SESSION['form_category']);

admin_header('Категории', 'categories');

function render_cat_options(array $nodes, int $depth, ?string $selected): void
{
    foreach ($nodes as $n) {
        $pad = str_repeat('— ', $depth);
        echo '<option value="' . e($n['id']) . '"' . ($selected === $n['id'] ? ' selected' : '')
            . '>' . e($pad . $n['name']) . '</option>';
        if (!empty($n['children'])) {
            render_cat_options($n['children'], $depth + 1, $selected);
        }
    }
}

function render_cat_rows(array $nodes, int $depth): void
{
    foreach ($nodes as $n) {
        echo '<tr><td>' . str_repeat('&nbsp;&nbsp;', $depth) . e($n['name'])
            . ' <code>' . e($n['slug']) . '</code></td><td>' . e($n['id']) . '</td><td>';
        echo '<a href="categories.php?edit=' . e($n['id']) . '">Ред.</a> ';
        echo '<form method="post" style="display:inline">' . csrf_field()
            . '<input type="hidden" name="action" value="move"><input type="hidden" name="id" value="' . e($n['id']) . '">'
            . '<button class="link-btn" name="dir" value="up">↑</button>'
            . '<button class="link-btn" name="dir" value="down">↓</button></form> ';
        echo '<form method="post" style="display:inline" onsubmit="return confirm(\'Удалить категорию?\')">' . csrf_field()
            . '<input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="' . e($n['id']) . '">'
            . '<button class="link-btn danger">Удалить</button></form>';
        echo '</td></tr>';
        if (!empty($n['children'])) {
            render_cat_rows($n['children'], $depth + 1);
        }
    }
}
?>
<table class="table">
<thead><tr><th>Дерево категорий</th><th>ID</th><th></th></tr></thead>
<tbody><?php render_cat_rows($cats->tree(), 0); ?></tbody>
</table>

<h2><?= $form !== null && ($form['id'] ?? '') !== '' ? 'Редактирование категории' : 'Новая категория' ?></h2>
<form method="post" action="categories.php" class="form">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="id" value="<?= e($form['id'] ?? '') ?>">
    <label>Название <input type="text" name="name" required value="<?= e($form['name'] ?? '') ?>"></label>
    <label>Родительская
        <select name="parent_id">
            <option value="">— нет (корень) —</option>
            <?php render_cat_options($cats->tree(), 0, $form['parent_id'] ?? null); ?>
        </select>
    </label>
    <label>Описание <textarea name="description" rows="2"><?= e($form['description'] ?? '') ?></textarea></label>
    <button type="submit">Сохранить</button>
    <?php if (($form['id'] ?? '') !== ''): ?><a href="categories.php">Отмена</a><?php endif; ?>
</form>
<?php admin_footer(); ?>
