<?php

declare(strict_types=1);

/**
 * cli/restore.php — восстановление из архива backup-*.tar.gz.
 * ТЗ, блок 13 §13.3.
 *
 * Использование:
 *   php cli/restore.php backup-20261021-220000-scheduled.tar.gz [--dir=/path] --confirm
 *
 * Без --confirm выполняется dry-run: проверка sha256, распаковка во временный
 * каталог и валидация структуры без изменения рабочих данных.
 */

$root = dirname(__DIR__);
require $root . '/src/autoload.php';

$args = array_slice($argv, 1);
$confirm = false;
$backupFile = null;
$backupDir = $root . '/data/backups';

foreach ($args as $arg) {
    if ($arg === '--confirm') {
        $confirm = true;
    } elseif (preg_match('/^--dir=(.+)$/', $arg, $m)) {
        $backupDir = rtrim($m[1], '/');
    } elseif ($backupFile === null && preg_match('/^[\w.-]+\.tar\.gz$/', $arg) && !str_contains($arg, '/')) {
        // только имя файла — path traversal исключён (ТЗ §3.5)
        $backupFile = $arg;
    } else {
        fwrite(STDERR, "Неизвестный аргумент: {$arg}\nИспользование: php cli/restore.php <backup.tar.gz> [--dir=/path] [--confirm]\n");
        exit(2);
    }
}

if ($backupFile === null) {
    fwrite(STDERR, "Укажите файл бэкапа. Использование: php cli/restore.php <backup.tar.gz> [--dir=/path] [--confirm]\n");
    exit(2);
}

$archivePath = $backupDir . '/' . $backupFile;
if (!is_file($archivePath)) {
    fwrite(STDERR, "Архив не найден: {$archivePath}\n");
    exit(1);
}

// --- 1. проверка sha256 ---------------------------------------------------------
$shaFile = $archivePath . '.sha256';
if (!is_file($shaFile)) {
    fwrite(STDERR, "Отсутствует {$backupFile}.sha256 — невозможна проверка целостности.\n");
    exit(1);
}
$expected = trim(explode(' ', trim((string)file_get_contents($shaFile)))[0]);
$actual = hash_file('sha256', $archivePath);
if ($expected === '' || $expected !== $actual) {
    fwrite(STDERR, "Контрольная сумма НЕ совпадает!\n  ожидалось: {$expected}\n  получено:  {$actual}\n");
    exit(1);
}
echo "sha256 OK\n";

// --- 2. распаковка во временный каталог ------------------------------------------
$tmpDir = sys_get_temp_dir() . '/cms-restore-' . bin2hex(random_bytes(6));
mkdir($tmpDir, 0700, true);

exec(implode(' ', array_map('escapeshellarg', ['tar', '-xzf', $archivePath, '-C', $tmpDir])) . ' 2>&1', $out, $code);
if ($code !== 0) {
    fwrite(STDERR, "Распаковка не удалась (код {$code}): " . implode("\n", $out) . "\n");
    exec('rm -rf ' . escapeshellarg($tmpDir));
    exit(1);
}

// защита от path traversal внутри архива: все пути должны остаться внутри tmpDir
$realTmp = realpath($tmpDir);
$rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmpDir, FilesystemIterator::SKIP_DOTS));
foreach ($rii as $f) {
    $rp = realpath($f->getPathname());
    if ($rp === false || !str_starts_with($rp, (string)$realTmp)) {
        fwrite(STDERR, "Подозрительный путь в архиве, восстановление прервано.\n");
        exec('rm -rf ' . escapeshellarg($tmpDir));
        exit(1);
    }
}

// --- 3. валидация структуры и JSON -------------------------------------------------
$errors = [];
if (!is_dir($tmpDir . '/data')) {
    $errors[] = 'в архиве отсутствует каталог data/';
}
$jsonIt = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmpDir . '/data', FilesystemIterator::SKIP_DOTS));
foreach ($jsonIt as $file) {
    if (strtolower($file->getExtension()) !== 'json') {
        continue;
    }
    json_decode((string)file_get_contents($file->getPathname()), true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        $errors[] = $file->getPathname() . ': ' . json_last_error_msg();
    }
}
if ($errors !== []) {
    fwrite(STDERR, "Валидация распакованных данных не пройдена:\n - " . implode("\n - ", $errors) . "\n");
    exec('rm -rf ' . escapeshellarg($tmpDir));
    exit(1);
}
echo "структура и JSON валидны\n";

if (!$confirm) {
    echo "DRY-RUN: изменения не выполнены. Добавьте --confirm для восстановления.\n";
    exec('rm -rf ' . escapeshellarg($tmpDir));
    exit(0);
}

// --- 4. страховочный бэкап текущего состояния ---------------------------------------
$preTs = date('Ymd-His');
$preName = "backup-pre-restore-{$preTs}.tar.gz";
$preItems = [];
foreach (['data', 'public/media'] as $d) {
    if (is_dir($root . '/' . $d)) {
        $preItems[] = $d;
    }
}
exec(
    implode(' ', array_map('escapeshellarg', ['tar', '-czf', $backupDir . '/' . $preName,
        '--exclude=./data/backups', '-C', $root])) . ' ' . implode(' ', $preItems) . ' 2>&1',
    $out2,
    $code2
);
if ($code2 !== 0) {
    fwrite(STDERR, "Не удалось создать страховочный бэкап, восстановление ОТМЕНЕНО.\n");
    exec('rm -rf ' . escapeshellarg($tmpDir));
    exit(1);
}
$preSha = hash_file('sha256', $backupDir . '/' . $preName);
file_put_contents($backupDir . '/' . $preName . '.sha256', $preSha . "  {$preName}\n");
file_put_contents($root . '/data/backup.log', json_encode([
    'ts' => date('c'),
    'file' => $preName,
    'bytes' => filesize($backupDir . '/' . $preName) ?: 0,
    'sha256' => $preSha,
    'tag' => 'pre-restore',
], JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
echo "страховочный бэкап: {$preName}\n";

// --- 5. замена data/ и public/media/ ---------------------------------------------------
$start = microtime(true);
foreach (['data', 'public/media'] as $d) {
    if (!is_dir($tmpDir . '/' . $d)) {
        continue;
    }
    exec('rm -rf ' . escapeshellarg($root . '/' . $d));
    mkdir($root . '/' . $d, 0775, true);
    exec('cp -a ' . escapeshellarg($tmpDir . '/' . $d) . '/. ' . escapeshellarg($root . '/' . $d));
}
// восстановить .htaccess-и защиты (мог отсутствовать в архиве)
foreach ([
    $root . '/data/.htaccess' => "Require all denied\n",
    $root . '/public/media/.htaccess' => file_exists($root . '/public/media/.htaccess') ? null : null,
] as $p => $content) {
    if ($content !== null && !is_file($p)) {
        file_put_contents($p, $content);
    }
}

$durationMs = (int)round((microtime(true) - $start) * 1000);

// --- 6. аудит ---------------------------------------------------------------------------
$logLine = json_encode([
    'ts' => date('c'),
    'user' => 'system',
    'action' => 'restore.completed',
    'details' => ['file' => $backupFile, 'duration_ms' => $durationMs, 'pre_restore_backup' => $preName],
], JSON_UNESCAPED_UNICODE) . "\n";
file_put_contents($root . '/data/audit.log', $logLine, FILE_APPEND | LOCK_EX);

exec('rm -rf ' . escapeshellarg($tmpDir));
echo "OK: восстановление завершено за {$durationMs} мс.\n";
exit(0);
