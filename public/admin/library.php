<?php

declare(strict_types=1);

require __DIR__ . '/../_bootstrap.php';
require __DIR__ . '/_layout.php';

use App\Library\LibraryException;
use App\Library\LibraryService;
use App\Validation\LibParamValidator;

/**
 * Админка библиотек (ТЗ Блок 5 §7): AI-модели (?kind=ai) и
 * паттерны движения (?kind=pattern). Встроенные — read-only + клонирование.
 */

$user = require_admin($app);
csrf_guard();

/** flash() — короткое имя для существующего flash_set(). */
if (!function_exists('flash')) {
    function flash(string $type, string $message): void
    {
        flash_set($type, $message);
    }
}

$kind = (($_GET['kind'] ?? $_POST['kind'] ?? 'ai') === 'pattern')
    ? LibraryService::KIND_PATTERN
    : LibraryService::KIND_AI;

$isPattern = $kind === LibraryService::KIND_PATTERN;
$svc = $isPattern ? $app['patterns'] : $app['aiModels'];
$pageTitle = $isPattern ? 'Паттерны движения' : 'AI-модели';
$self = $isPattern ? 'movement-patterns.php' : 'ai-models.php';
$categories = $isPattern ? LibParamValidator::PATTERN_CATEGORIES : LibParamValidator::AI_CATEGORIES;
$errors = [];

/** Используется ли запись играми (data/games.json level_json содержит id в ai/pattern полях). */
$usedInGames = static function (string $id) use ($app): array {
    $games = $app['store']->read('games.json', []);
    $hits = [];
    foreach (is_array($games) ? $games : [] as $g) {
        $lj = json_encode($g['levels'] ?? [], JSON_UNESCAPED_UNICODE);
        if (str_contains((string)$lj, '"' . $id . '"')) {
            $hits[] = (string)($g['slug'] ?? $g['id'] ?? '?');
        }
    }
    return $hits;
};

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'seed') {
        $res = $svc->seed($user['username']);
        flash('ok', sprintf(
            '%s: добавлено %d, пропущено %d, конфликтов %d.',
            $pageTitle,
            $res['added'],
            $res['skipped'],
            $res['conflicts']
        ));
        header('Location: /admin/' . $self . '?kind=' . $kind);
        exit;
    }

    if ($action === 'clone') {
        try {
            $res = $svc->clone((string)($_POST['id'] ?? ''), $user['username']);
            if (($res['ok'] ?? false) === true) {
                flash('ok', 'Создана копия: ' . $res['id']);
            } else {
                flash('err', implode(' ', $res['errors'] ?? ['Не удалось клонировать.']));
            }
        } catch (LibraryException $e) {
            flash('err', $e->getMessage());
        }
        header('Location: /admin/' . $self . '?kind=' . $kind);
        exit;
    }

    if ($action === 'delete') {
        try {
            $id = (string)($_POST['id'] ?? '');
            $svc->delete($id, $usedInGames($id), $user['username']);
            flash('ok', 'Запись удалена: ' . $id);
        } catch (LibraryException $e) {
            flash('err', $e->getMessage());
        }
        header('Location: /admin/' . $self . '?kind=' . $kind);
        exit;
    }

    if ($action === 'save') {
        $id = trim((string)($_POST['id'] ?? ''));
        $isNew = ($_POST['is_new'] ?? '1') === '1';

        // параметры из формы: rows[key][type|min|max|default|unit|options]
        $params = [];
        $rows = is_array($_POST['rows'] ?? null) ? $_POST['rows'] : [];
        foreach ($rows as $row) {
            if (!is_array($row) || trim((string)($row['key'] ?? '')) === '') {
                continue;
            }
            $p = [
                'key' => trim((string)$row['key']),
                'type' => (string)($row['type'] ?? 'number'),
            ];
            if ($p['type'] === 'number') {
                $p['min'] = is_numeric($row['min'] ?? null) ? $row['min'] + 0 : null;
                $p['max'] = is_numeric($row['max'] ?? null) ? $row['max'] + 0 : null;
                $p['default'] = is_numeric($row['default'] ?? null) ? $row['default'] + 0 : null;
                $p['options'] = null;
            } elseif ($p['type'] === 'boolean') {
                $p['default'] = ($row['default'] ?? '') === '1';
                $p['min'] = $p['max'] = $p['options'] = null;
            } elseif ($p['type'] === 'enum') {
                $opts = array_values(array_filter(array_map('trim', explode(',', (string)($row['options'] ?? ''))), fn ($s) => $s !== ''));
                $p['options'] = $opts;
                $p['default'] = trim((string)($row['default'] ?? ''));
                $p['min'] = $p['max'] = null;
            } else { // string
                $p['default'] = (string)($row['default'] ?? '');
                $p['min'] = $p['max'] = $p['options'] = null;
            }
            $unit = trim((string)($row['unit'] ?? ''));
            $p['unit'] = $unit !== '' ? $unit : null;
            $params[] = $p;
        }

        $input = [
            'id' => $id,
            'name' => (string)($_POST['name'] ?? ''),
            'description' => (string)($_POST['description'] ?? ''),
            'category' => (string)($_POST['category'] ?? ''),
            'reference' => (string)($_POST['reference'] ?? ''),
            'supported_engines' => array_values((array)($_POST['engines'] ?? [])),
            'params' => $params,
        ];
        if ($isPattern) {
            $input['trajectory_hint'] = (string)($_POST['trajectory_hint'] ?? '');
            $input['on_finish'] = (string)($_POST['on_finish'] ?? 'despawn');
        }

        $errors = $isNew ? $svc->create($input, $user['username']) : $svc->update($id, $input, $user['username']);
        if ($errors === []) {
            flash('ok', $isNew ? 'Запись создана.' : 'Запись обновлена.');
            header('Location: /admin/' . $self . '?kind=' . $kind);
            exit;
        }
    }
}

// форма редактирования/создания
$editing = null;
if (isset($_GET['edit'])) {
    $rec = $svc->find((string)$_GET['edit']);
    if ($rec !== null && ($rec['custom'] ?? false) === true) {
        $editing = $rec;
    }
}
$creating = isset($_GET['new']) && $editing === null;

$list = $svc->all();
$byCat = [];
foreach ($list as $rec) {
    $byCat[(string)($rec['category'] ?? '')][] = $rec;
}

admin_header($pageTitle, $isPattern ? 'patterns' : 'ai-models');
?>
<p class="muted">Всего записей: <?= count($list) ?>. Встроенные (seed из фикстур) доступны только на чтение и клонирование.</p>

<form method="post" action="/admin/<?= $self ?>?kind=<?= $kind ?>" style="display:inline">
    <?= csrf_field() ?>
    <input type="hidden" name="kind" value="<?= $kind ?>">
    <button type="submit" name="action" value="seed">Seed встроенных из фикстур</button>
</form>
<a class="btn" href="/admin/<?= $self ?>?kind=<?= $kind ?>&new=1">+ Новая кастомная запись</a>

<?php if ($errors !== []): ?>
<div class="flash flash-err"><ul><?php foreach ($errors as $k => $m): ?><li><b><?= e((string)$k) ?></b>: <?= e($m) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<?php if ($editing !== null || $creating):
    $rec = $editing ?? [
        'id' => '', 'name' => '', 'description' => '', 'category' => $categories[0],
        'reference' => '', 'supported_engines' => ['static-screen'], 'params' => [],
        'trajectory_hint' => '', 'on_finish' => 'despawn',
    ];
?>
<h2><?= $editing ? 'Редактирование: ' . e((string)$rec['id']) : 'Новая запись' ?></h2>
<form method="post" action="/admin/<?= $self ?>?kind=<?= $kind ?>" class="form">
    <?= csrf_field() ?>
    <input type="hidden" name="kind" value="<?= $kind ?>">
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="is_new" value="<?= $editing ? '0' : '1' ?>">

    <label>ID (^[a-z][a-z0-9_]{1,40}$)
        <input name="id" value="<?= e((string)$rec['id']) ?>" <?= $editing ? 'readonly' : '' ?> required>
    </label>
    <label>Название (2–80) <input name="name" value="<?= e((string)$rec['name']) ?>" required></label>
    <label>Описание (10–300) <textarea name="description" required><?= e((string)$rec['description']) ?></textarea></label>
    <label>Категория
        <select name="category">
            <?php foreach ($categories as $c): ?>
                <option value="<?= e($c) ?>" <?= $rec['category'] === $c ? 'selected' : '' ?>><?= e($c) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label>Референс (≤120) <input name="reference" value="<?= e((string)($rec['reference'] ?? '')) ?>"></label>
    <fieldset><legend>Поддерживаемые движки</legend>
        <?php foreach (LibParamValidator::ENGINES as $eng): ?>
            <label><input type="checkbox" name="engines[]" value="<?= e($eng) ?>"
                <?= in_array($eng, (array)$rec['supported_engines'], true) ? 'checked' : '' ?>> <?= e($eng) ?></label>
        <?php endforeach; ?>
    </fieldset>
    <?php if ($isPattern): ?>
    <label>Формула-подсказка траектории (≤200) <input name="trajectory_hint" value="<?= e((string)($rec['trajectory_hint'] ?? '')) ?>"></label>
    <label>Поведение после завершения (on_finish)
        <select name="on_finish">
            <?php foreach (['despawn', 'straight_down', 'loop'] as $o): ?>
                <option value="<?= e($o) ?>" <?= ($rec['on_finish'] ?? '') === $o ? 'selected' : '' ?>><?= e($o) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <?php endif; ?>

    <h3>Параметры (до 20)</h3>
    <table class="params" id="paramTable">
      <thead><tr><th>key</th><th>type</th><th>min</th><th>max</th><th>default</th><th>unit</th><th>options (через запятую)</th><th></th></tr></thead>
      <tbody>
      <?php foreach ((array)$rec['params'] as $i => $p): ?>
        <tr>
          <td><input name="rows[<?= $i ?>][key]" value="<?= e((string)$p['key']) ?>"></td>
          <td><select name="rows[<?= $i ?>][type]"><?php foreach (['number','boolean','enum','string'] as $t): ?><option <?= $p['type']===$t?'selected':'' ?>><?= $t ?></option><?php endforeach; ?></select></td>
          <td><input name="rows[<?= $i ?>][min]" value="<?= e((string)($p['min'] ?? '')) ?>" size="5"></td>
          <td><input name="rows[<?= $i ?>][max]" value="<?= e((string)($p['max'] ?? '')) ?>" size="5"></td>
          <td><input name="rows[<?= $i ?>][default]" value="<?= e(is_bool($p['default'] ?? null) ? ($p['default'] ? '1' : '0') : (string)($p['default'] ?? '')) ?>"></td>
          <td><input name="rows[<?= $i ?>][unit]" value="<?= e((string)($p['unit'] ?? '')) ?>" size="8"></td>
          <td><input name="rows[<?= $i ?>][options]" value="<?= e(implode(',', (array)($p['options'] ?? []))) ?>"></td>
          <td><button type="button" onclick="this.closest('tr').remove()">×</button></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <button type="button" onclick="addRow()">+ Параметр</button>
    <script>
    let rowIdx = <?= count((array)$rec['params']) ?>;
    function addRow() {
      const tb = document.querySelector('#paramTable tbody');
      const tr = document.createElement('tr');
      tr.innerHTML = '<td><input name="rows['+rowIdx+'][key]"></td>'
        + '<td><select name="rows['+rowIdx+'][type]"><option>number</option><option>boolean</option><option>enum</option><option>string</option></select></td>'
        + '<td><input name="rows['+rowIdx+'][min]" size="5"></td><td><input name="rows['+rowIdx+'][max]" size="5"></td>'
        + '<td><input name="rows['+rowIdx+'][default]"></td><td><input name="rows['+rowIdx+'][unit]" size="8"></td>'
        + '<td><input name="rows['+rowIdx+'][options]"></td><td><button type="button" onclick="this.closest(\'tr\').remove()">×</button></td>';
      tb.appendChild(tr); rowIdx++;
    }
    </script>
    <button type="submit">Сохранить</button>
    <a href="/admin/<?= $self ?>?kind=<?= $kind ?>">Отмена</a>
</form>
<?php endif; ?>

<?php foreach ($categories as $cat): if (!isset($byCat[$cat])) continue; ?>
<h2><?= e($cat) ?> (<?= count($byCat[$cat]) ?>)</h2>
<table class="list">
<thead><tr><th>ID</th><th>Название</th><th>Движки</th><th>Тип</th><th>Пары</th><th>Действия</th></tr></thead>
<tbody>
<?php foreach ($byCat[$cat] as $rec): $builtin = ($rec['custom'] ?? false) !== true; ?>
<tr>
  <td><?= e((string)$rec['id']) ?></td>
  <td><?= e((string)$rec['name']) ?></td>
  <td><?= e(implode(', ', (array)$rec['supported_engines'])) ?></td>
  <td><?= $builtin ? 'встроенная' : 'кастомная' ?></td>
  <td><?= count((array)($rec['params'] ?? [])) ?></td>
  <td>
    <form method="post" action="/admin/<?= $self ?>?kind=<?= $kind ?>" style="display:inline">
      <?= csrf_field() ?><input type="hidden" name="kind" value="<?= $kind ?>">
      <input type="hidden" name="id" value="<?= e((string)$rec['id']) ?>">
      <?php if ($builtin): ?>
        <button type="submit" name="action" value="clone">Клонировать</button>
      <?php else: ?>
        <a href="/admin/<?= $self ?>?kind=<?= $kind ?>&edit=<?= e((string)$rec['id']) ?>">Изменить</a>
        <button type="submit" name="action" value="delete" onclick="return confirm('Удалить запись?')">Удалить</button>
      <?php endif; ?>
    </form>
  </td>
</tr>
<?php endforeach; ?>
</tbody></table>
<?php endforeach; ?>
<?php admin_footer(); ?>
