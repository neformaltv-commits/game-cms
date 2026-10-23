<?php

declare(strict_types=1);

require __DIR__ . '/../_bootstrap.php';
require __DIR__ . '/_layout.php';

use App\Http\Response;
use App\LevelService;

$user = require_admin($app);
csrf_guard();

$games = $app['games'];
$levels = $app['levels'];

$gameId = (string)($_GET['id'] ?? $_POST['game_id'] ?? '');
$game = $gameId !== '' ? $games->find($gameId) : null;
if ($game === null) {
    flash_set('error', 'Игра не найдена.');
    Response::redirect('games.php');
}

// ---------- POST-действия ----------
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'level-create') {
        $res = $levels->create((string)$game['id']);
        flash_set($res['ok'] ? 'success' : 'error',
            $res['ok'] ? "Создан уровень «{$res['level']['name']}»." : implode(' ', $res['errors']));
        Response::redirect('game-edit.php?id=' . urlencode((string)$game['id'])
            . ($res['ok'] ? '&level=' . urlencode((string)$res['level']['id']) : ''));
    }

    if ($action === 'level-duplicate') {
        $res = $levels->duplicate((string)$game['id'], (string)($_POST['level_id'] ?? ''));
        flash_set($res['ok'] ? 'success' : 'error',
            $res['ok'] ? "Создана копия «{$res['level']['name']}»." : implode(' ', $res['errors']));
        Response::redirect('game-edit.php?id=' . urlencode((string)$game['id']));
    }

    if ($action === 'level-delete') {
        $errors = $levels->delete((string)$game['id'], (string)($_POST['level_id'] ?? ''));
        flash_set($errors === [] ? 'success' : 'error',
            $errors === [] ? 'Уровень удалён.' : implode(' ', $errors));
        Response::redirect('game-edit.php?id=' . urlencode((string)$game['id']));
    }

    if ($action === 'level-save') {
        $levelId = (string)($_POST['level_id'] ?? '');
        $current = null;
        foreach ((array)$game['levels'] as $lv) {
            if (($lv['id'] ?? '') === $levelId) {
                $current = $lv;
            }
        }
        if ($current === null) {
            flash_set('error', 'Уровень не найден.');
            Response::redirect('game-edit.php?id=' . urlencode((string)$game['id']));
        }
        // Сетка приходит из textarea: 24 строки, ячейки через пробел
        $gridRows = array_slice(
            preg_split('/\R/', trim((string)($_POST['grid'] ?? ''))) ?: [],
            0,
            LevelService::ROWS
        );
        $level = [
            'id' => $levelId,
            'name' => trim((string)($_POST['name'] ?? '')),
            'width' => LevelService::COLS,
            'height' => LevelService::ROWS,
            'grid' => $gridRows,
            'spawn' => $current['spawn'] ?? null,
            'exit_emoji' => $current['exit_emoji'] ?? null,
            'time_limit_s' => ($_POST['time_limit_s'] ?? '') === '' ? null : (int)$_POST['time_limit_s'],
        ];
        $errors = $levels->save((string)$game['id'], $level);
        if ($errors !== []) {
            $_SESSION['form_level'] = ['id' => $levelId, 'name' => $level['name'],
                                       'grid' => $gridRows, 'time_limit_s' => $_POST['time_limit_s'] ?? ''];
            flash_set('error', implode(' ', $errors));
        } else {
            unset($_SESSION['form_level']);
            flash_set('success', 'Уровень сохранён.');
        }
        Response::redirect('game-edit.php?id=' . urlencode((string)$game['id'])
            . '&level=' . urlencode($levelId));
    }
}

// ---------- Данные для рендера ----------
$levelList = array_values((array)$game['levels']);
$currentLevelId = (string)($_GET['level'] ?? ($levelList[0]['id'] ?? ''));
$current = null;
foreach ($levelList as $lv) {
    if (($lv['id'] ?? '') === $currentLevelId) {
        $current = $lv;
    }
}
if ($current === null && $levelList !== []) {
    $current = $levelList[0];
    $currentLevelId = (string)$current['id'];
}

$legend = $games->effectiveLegend($game);
$genre = $app['genres']->find((string)$game['genre_id']);
$engine = (string)($genre['engine'] ?? '');
$legendErrors = $app['legendValidator']->validate($legend, $engine);

// Палитра: эмодзи эффективной легенды + '.' (пусто), как в ТЗ §8.3
$palette = [['key' => '.', 'name' => 'Пусто', 'role' => 'background']];
foreach ($legend as $emoji => $el) {
    if (is_array($el)) {
        $palette[] = ['key' => (string)$emoji, 'name' => (string)($el['name'] ?? ''), 'role' => (string)($el['role'] ?? '')];
    }
}

// Восстановление несохранённой сетки после ошибки валидации
$formLevel = $_SESSION['form_level'] ?? null;
unset($_SESSION['form_level']);
$gridRows = (array)($current['grid'] ?? []);
if ($formLevel !== null && (string)$formLevel['id'] === $currentLevelId) {
    $gridRows = (array)$formLevel['grid'];
}

admin_header('Редактор: ' . (string)$game['title'], 'games');
?>
<p><a href="games.php">← Все игры</a> | <b><?= e($game['title']) ?></b>
   (жанр: <?= e((string)($genre['name'] ?? '?')) ?>, движок: <?= e($engine) ?>, статус: <?= e((string)$game['status']) ?>)
   | <a href="game-settings.php?id=<?= e($game['id']) ?>">Управление и настройки</a>
   | <a href="game-combat.php?id=<?= e($game['id']) ?>">Бой и триггеры</a></p>

<?php if ($legendErrors !== []): ?>
<div class="flash flash-error"><b>Легенда жанра/игры невалидна:</b> <?= e(implode(' ', $legendErrors)) ?></div>
<?php endif; ?>

<h2>Уровни</h2>
<ul class="level-tabs">
<?php foreach ($levelList as $lv): ?>
    <li><a href="game-edit.php?id=<?= e($game['id']) ?>&amp;level=<?= e($lv['id']) ?>"
           class="<?= (string)$lv['id'] === $currentLevelId ? 'active' : '' ?>"><?= e((string)$lv['name']) ?></a>
        <form method="post" action="game-edit.php" style="display:inline">
            <?= csrf_field() ?><input type="hidden" name="game_id" value="<?= e($game['id']) ?>">
            <input type="hidden" name="action" value="level-duplicate">
            <input type="hidden" name="level_id" value="<?= e($lv['id']) ?>">
            <button type="submit" class="link-btn">Копия</button>
        </form>
        <form method="post" action="game-edit.php" style="display:inline" onsubmit="return confirm('Удалить уровень?')">
            <?= csrf_field() ?><input type="hidden" name="game_id" value="<?= e($game['id']) ?>">
            <input type="hidden" name="action" value="level-delete">
            <input type="hidden" name="level_id" value="<?= e($lv['id']) ?>">
            <button type="submit" class="link-btn danger">✕</button>
        </form>
    </li>
<?php endforeach; ?>
</ul>
<form method="post" action="game-edit.php" style="display:inline">
    <?= csrf_field() ?><input type="hidden" name="game_id" value="<?= e($game['id']) ?>">
    <input type="hidden" name="action" value="level-create">
    <button type="submit">+ Новый уровень</button>
</form>

<?php if ($current !== null): ?>
<h2>Сетка уровня: <?= e((string)$current['name']) ?> (<?= e($currentLevelId) ?>)</h2>
<form method="post" action="game-edit.php" class="form">
    <?= csrf_field() ?>
    <input type="hidden" name="game_id" value="<?= e($game['id']) ?>">
    <input type="hidden" name="action" value="level-save">
    <input type="hidden" name="level_id" value="<?= e($currentLevelId) ?>">
    <label>Название уровня <input type="text" name="name" required maxlength="60"
        value="<?= e($formLevel['name'] ?? (string)$current['name']) ?>"></label>
    <label>Лимит времени, c <input type="number" name="time_limit_s" min="0" max="9999"
        value="<?= e($formLevel['time_limit_s'] ?? ((string)($current['time_limit_s'] ?? ''))) ?>"></label>

    <h3>Палитра (кликните элемент, затем клетку в сетке)</h3>
    <div class="palette" id="palette">
        <?php foreach ($palette as $p): ?>
            <button type="button" class="swatch" data-cell="<?= e($p['key']) ?>" title="<?= e($p['role']) ?>">
                <?= e($p['key']) ?><?php if ($p['name'] !== ''): ?><small> <?= e($p['name']) ?></small><?php endif; ?>
            </button>
        <?php endforeach; ?>
    </div>

    <h3>Сетка 32×24 (строки ячеек через пробел; редактируйте вручную или кликами)</h3>
    <textarea name="grid" id="grid-input" rows="<?= count($gridRows) > 0 ? count($gridRows) : LevelService::ROWS ?>"
              cols="130" spellcheck="false" wrap="off"><?= e(implode("\n", $gridRows)) ?></textarea>

    <table class="grid-editor" id="grid-view"></table>
    <p><button type="submit">Сохранить уровень</button>
       <span class="hint">Клик по клетке таблицы заменяет её выбранным элементом палитры; изменения синхронизируются с текстом.</span></p>
</form>

<script>
(function () {
    var COLS = <?= LevelService::COLS ?>, ROWS = <?= LevelService::ROWS ?>;
    var input = document.getElementById('grid-input');
    var view = document.getElementById('grid-view');
    var selected = '.';
    var currentBrush = null;

    function parseGrid() {
        return input.value.split('\n').map(function (row) {
            return row.trim() === '' ? [] : row.trim().split(/\s+/);
        });
    }

    function serializeGrid(rows) {
        input.value = rows.map(function (r) { return r.join(' '); }).join('\n');
    }

    function render() {
        var rows = parseGrid();
        view.innerHTML = '';
        for (var r = 0; r < ROWS; r++) {
            var tr = document.createElement('tr');
            for (var c = 0; c < COLS; c++) {
                var td = document.createElement('td');
                td.textContent = (rows[r] && rows[r][c]) !== undefined ? rows[r][c] : '';
                td.dataset.row = r; td.dataset.col = c;
                tr.appendChild(td);
            }
            view.appendChild(tr);
        }
    }

    view.addEventListener('click', function (ev) {
        var td = ev.target.closest('td');
        if (!td) return;
        var rows = parseGrid();
        while (rows.length < ROWS) rows.push([]);
        var r = +td.dataset.row, c = +td.dataset.col;
        while (rows[r].length <= c) rows[r].push('.');
        rows[r][c] = selected;
        for (var i = 0; i < ROWS; i++) { while (rows[i].length < COLS) rows[i].push('.'); rows[i] = rows[i].slice(0, COLS); }
        serializeGrid(rows);
        render();
    });

    document.getElementById('palette').addEventListener('click', function (ev) {
        var b = ev.target.closest('.swatch');
        if (!b) return;
        selected = b.dataset.cell;
        document.querySelectorAll('.swatch.active').forEach(function (x) { x.classList.remove('active'); });
        b.classList.add('active');
    });

    input.addEventListener('change', render);
    render();
})();
</script>
<?php else: ?>
<p>У уровней пока нет — создайте первый уровень.</p>
<?php endif; ?>
<?php admin_footer(); ?>
