<?php

declare(strict_types=1);

require __DIR__ . '/../_bootstrap.php';
require __DIR__ . '/_layout.php';

use App\Http\Response;

$user = require_admin($app);
csrf_guard();

$importer = $app['references'];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'import') {
    $target = (string)($_POST['target'] ?? ''); // genres | categories
    $mode = (string)($_POST['mode'] ?? 'add');  // add | replace
    $file = $_FILES['payload'] ?? null;

    $errors = [];
    if (!in_array($target, ['genres', 'categories'], true)) {
        $errors[] = 'Неизвестный справочник.';
    }
    if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $errors[] = 'Файл не загружен.';
    } elseif ((int)$file['size'] > \App\ReferenceImporter::MAX_BYTES) {
        $errors[] = 'Файл превышает 1 MB.';
    }

    if ($errors === []) {
        $jsonText = (string)file_get_contents((string)$file['tmp_name']);
        $parsed = $importer->parse($jsonText);
        $errors = $parsed['errors'];
    }

    if ($errors === []) {
        $targetFile = $target . '.json';
        if ($target === 'genres') {
            $validator = static fn (array $row): array => \App\Validation\GenreValidator::validate($row, []);
        } else {
            $allCats = $app['categories']->all();
            $slugs = array_map(static fn ($c) => (string)($c['slug'] ?? ''), $allCats);
            $validator = static fn (array $row): array => \App\Validation\CategoryValidator::validate($row, $allCats, $slugs);
        }

        if ($mode === 'replace') {
            // Полная замена: валидируем ВСЕ строки до записи (ТЗ §5.2 п.3)
            $rowErrors = [];
            foreach ($parsed['data'] as $i => $row) {
                $e = $validator($row);
                if ($e !== []) {
                    $rowErrors[] = 'строка ' . ($i + 1) . ': ' . implode(' ', $e);
                }
            }
            if ($rowErrors !== []) {
                $errors = $rowErrors;
            } else {
                $backup = $importer->replaceAll($targetFile, $parsed['data']);
                $app['audit']->log('reference.import_replace', $targetFile, [
                    'rows' => count($parsed['data']), 'backup' => $backup,
                ]);
                flash_set('success', 'Справочник заменён (' . count($parsed['data']) . ' записей).'
                    . ($backup !== null ? " Бэкап: {$backup}." : ''));
                Response::redirect('references.php');
            }
        } else {
            $res = $importer->mergeAdd($targetFile, $parsed['data'], $validator);
            $app['audit']->log('reference.import_add', $targetFile, $res);
            flash_set('success', "Импорт «Добавить»: добавлено {$res['added']}, пропущено {$res['skipped']}.");
            Response::redirect('references.php');
        }
    }
    flash_set('error', implode(' ', $errors));
    Response::redirect('references.php');
}

admin_header('Импорт/экспорт справочников', 'references');
?>
<h2>Экспорт</h2>
<p><a class="btn" href="/export.php?target=genres">Скачать genres.json</a>
   <a class="btn" href="/export.php?target=categories">Скачать categories.json</a></p>

<h2>Импорт</h2>
<form method="post" action="references.php" enctype="multipart/form-data" class="form">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="import">
    <label>Справочник
        <select name="target">
            <option value="genres">Жанры</option>
            <option value="categories">Категории</option>
        </select>
    </label>
    <label>Режим
        <select name="mode">
            <option value="add">Добавить (пропустить существующие id)</option>
            <option value="replace">Заменить полностью (с бэкапом)</option>
        </select>
    </label>
    <label>JSON-файл (массив записей, до 1 MB) <input type="file" name="payload" accept=".json,application/json" required></label>
    <button type="submit">Импортировать</button>
</form>
<?php admin_footer(); ?>
