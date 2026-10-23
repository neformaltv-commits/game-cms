<?php

declare(strict_types=1);

require __DIR__ . '/../_bootstrap.php';
require __DIR__ . '/_layout.php';

use App\Http\Response;
use App\Support\Slug;

$user = require_admin($app);
csrf_guard();

$games = $app['games'];
$genres = $app['genres'];
$categories = $app['categories'];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    $id = (string)($_POST['id'] ?? '');

    if ($action === 'create') {
        $res = $games->create([
            'title' => trim((string)($_POST['title'] ?? '')),
            'description' => trim((string)($_POST['description'] ?? '')),
            'genre_id' => (string)($_POST['genre_id'] ?? ''),
            'category_ids' => (array)($_POST['category_ids'] ?? []),
            'slug' => Slug::generate((string)($_POST['title'] ?? '')),
        ]);
        if ($res['ok']) {
            flash_set('success', "Игра «{$res['game']['title']}» создана.");
            Response::redirect('game-edit.php?id=' . urlencode((string)$res['game']['id']));
        }
        flash_set('error', implode(' ', $res['errors']));
        Response::redirect('games.php');
    }

    if ($action === 'save') {
        $game = $games->find($id);
        if ($game === null) {
            flash_set('error', 'Игра не найдена.');
            Response::redirect('games.php');
        }
        $game['title'] = trim((string)($_POST['title'] ?? ''));
        $game['description'] = trim((string)($_POST['description'] ?? ''));
        $slug = (string)($_POST['slug'] ?? '');
        if ($slug === '') {
            $slug = Slug::generate($game['title']) ?? '';
        }
        // Если slug слишком короткий для валидатора (<2 символов) — дополняем суффиксом
        if (strlen($slug) === 1) {
            $slug .= '-game';
        }
        $game['slug'] = $slug;
        $game['category_ids'] = array_values(array_map('strval', (array)($_POST['category_ids'] ?? [])));
        $errors = $games->save($game, false);
        flash_set($errors === [] ? 'success' : 'error',
            $errors === [] ? 'Карточка сохранена.' : implode(' ', $errors));
        Response::redirect('games.php');
    }

    if ($action === 'publish' || $action === 'unpublish' || $action === 'archive') {
        $status = ['publish' => 'published', 'unpublish' => 'draft', 'archive' => 'archived'][$action];
        $errors = $games->setStatus($id, $status);
        flash_set($errors === [] ? 'success' : 'error',
            $errors === [] ? "Статус изменён на «{$status}»." : implode(' ', $errors));
        Response::redirect('games.php');
    }

    if ($action === 'delete') {
        $errors = $games->delete($id);
        flash_set($errors === [] ? 'success' : 'error',
            $errors === [] ? "Игра {$id} удалена." : implode(' ', $errors));
        Response::redirect('games.php');
    }

    if ($action === 'cover-upload') {
        $errors = $games->uploadCover($id, $_FILES['cover'] ?? []);
        flash_set($errors === [] ? 'success' : 'error',
            $errors === [] ? 'Обложка загружена.' : implode(' ', $errors));
        Response::redirect('games.php');
    }
}

admin_header('Игры', 'games');
$allCategories = $categories->all();
?>
<h2>Новая игра</h2>
<form method="post" action="games.php" class="form">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="create">
    <label>Название <input type="text" name="title" required maxlength="80"></label>
    <label>Описание <textarea name="description" rows="3" maxlength="500"></textarea></label>
    <label>Жанр
        <select name="genre_id" required>
            <option value="">— выберите —</option>
            <?php foreach ($genres->all() as $g): ?>
                <option value="<?= e($g['id']) ?>"><?= e($g['name']) ?> (<?= e($g['engine']) ?>)</option>
            <?php endforeach; ?>
        </select>
    </label>
    <fieldset>
        <legend>Категории</legend>
        <?php foreach ($allCategories as $c): ?>
            <label><input type="checkbox" name="category_ids[]" value="<?= e($c['id']) ?>"> <?= e($c['name']) ?></label>
        <?php endforeach; ?>
    </fieldset>
    <button type="submit">Создать игру</button>
</form>

<h2>Список игр</h2>
<table class="table">
<thead><tr><th>ID</th><th>Название</th><th>Slug</th><th>Жанр</th><th>Статус</th><th>Уровни</th><th></th></tr></thead>
<tbody>
<?php foreach ($games->all() as $g): ?>
<tr>
    <td><?= e($g['id']) ?></td>
    <td><?= e($g['title']) ?></td>
    <td><?= e($g['slug']) ?></td>
    <td><?= e((string)($genres->find((string)$g['genre_id'])['name'] ?? $g['genre_id'])) ?></td>
    <td><span class="badge badge-<?= e($g['status']) ?>"><?= e($g['status']) ?></span></td>
    <td><?= count((array)$g['levels']) ?></td>
    <td>
        <a href="game-edit.php?id=<?= e($g['id']) ?>">Редактор</a>
        <?php if ($g['status'] === 'draft'): ?>
        <form method="post" action="games.php" style="display:inline">
            <?= csrf_field() ?><input type="hidden" name="action" value="publish">
            <input type="hidden" name="id" value="<?= e($g['id']) ?>">
            <button type="submit" class="link-btn">Опубликовать</button>
        </form>
        <?php elseif ($g['status'] === 'published'): ?>
        <form method="post" action="games.php" style="display:inline">
            <?= csrf_field() ?><input type="hidden" name="action" value="unpublish">
            <input type="hidden" name="id" value="<?= e($g['id']) ?>">
            <button type="submit" class="link-btn">Снять с публикации</button>
        </form>
        <?php endif; ?>
        <form method="post" action="games.php" style="display:inline" onsubmit="return confirm('Удалить игру со всеми уровнями?')">
            <?= csrf_field() ?><input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="<?= e($g['id']) ?>">
            <button type="submit" class="link-btn danger">Удалить</button>
        </form>
    </td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
<?php admin_footer(); ?>
