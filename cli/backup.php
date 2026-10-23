<?php

declare(strict_types=1);

/**
 * cli/backup.php — резервная копия data/, public/media/, config/ (без secrets.php), VERSION.
 * ТЗ, блок 13 §13.2. Типовое решение: tar.gz + sha256 + flock + ротация по количеству.
 *
 * Использование:
 *   php cli/backup.php [--out=/path] [--keep=14] [--tag=manual|scheduled]
 */

$root = dirname(__DIR__);
require $root . '/src/autoload.php';

// --- аргументы ---------------------------------------------------------------
$outDir = $root . '/data/backups';
$keep = 14;
$tag = 'manual';
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--out=(.+)$/', $arg, $m)) {
        $outDir = rtrim($m[1], '/');
    } elseif (preg_match('/^--keep=(\d+)$/', $arg, $m)) {
        $keep = max(1, (int)$m[1]);
    } elseif (preg_match('/^--tag=([a-z0-9_-]+)$/i', $arg, $m)) {
        $tag = $m[1];
    } else {
        fwrite(STDERR, "Неизвестный аргумент: {$arg}\nИспользование: php cli/backup.php [--out=/path] [--keep=14] [--tag=manual|scheduled]\n");
        exit(2);
    }
}

if (!is_dir($outDir) && !mkdir($outDir, 0775, true) && !is_dir($outDir)) {
    fwrite(STDERR, "Не удалось создать каталог бэкапов: {$outDir}\n");
    exit(1);
}

// --- flock: защита от параллельных запусков ----------------------------------
$lockFp = fopen($outDir . '/.backup.lock', 'c');
if ($lockFp === false || !flock($lockFp, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "Другой процесс бэкапа уже выполняется.\n");
    exit(1);
}

// --- 1. валидация всех JSON в data/ ------------------------------------------
$jsonErrors = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/data', FilesystemIterator::SKIP_DOTS));
foreach ($it as $file) {
    /** @var SplFileInfo $file */
    if (strtolower($file->getExtension()) !== 'json') {
        continue;
    }
    $raw = file_get_contents($file->getPathname());
    if ($raw === false) {
        $jsonErrors[] = $file->getPathname() . ': не читается';
        continue;
    }
    json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        $jsonErrors[] = $file->getPathname() . ': ' . json_last_error_msg();
    }
}
if ($jsonErrors !== []) {
    fwrite(STDERR, "Валидация JSON не пройдена, бэкап НЕ создан:\n - " . implode("\n - ", $jsonErrors) . "\n");
    flock($lockFp, LOCK_UN);
    exit(1);
}

// --- 2. создание архива (во временный файл -> rename, атомарно) ---------------
$ts = date('Ymd-His');
$finalName = "backup-{$ts}-{$tag}.tar.gz";
$tmpPath = $outDir . "/.tmp-{$finalName}";
$finalPath = $outDir . '/' . $finalName;

$items = [];
foreach (['data', 'public/media', 'config'] as $dir) {
    if (is_dir($root . '/' . $dir)) {
        $items[] = $dir;
    }
}
if (is_file($root . '/VERSION')) {
    $items[] = 'VERSION';
}

$cmd = [
    'tar', '-czf', $tmpPath,
    '--exclude=./data/backups',       // сами бэкапы в бэкап не кладём
    '--exclude=config/secrets.php',   // секреты не бэкапируются (ТЗ §13.2)
    '-C', $root,
];
$cmd = array_merge($cmd, $items);

exec(implode(' ', array_map('escapeshellarg', $cmd)) . ' 2>&1', $out, $code);
if ($code !== 0 || !is_file($tmpPath)) {
    fwrite(STDERR, "tar завершился с кодом {$code}: " . implode("\n", $out) . "\n");
    @unlink($tmpPath);
    flock($lockFp, LOCK_UN);
    exit(1);
}

// --- 3. sha256 + rename --------------------------------------------------------
$sha = hash_file('sha256', $tmpPath);
if ($sha === false) {
    fwrite(STDERR, "Не удалось посчитать sha256.\n");
    @unlink($tmpPath);
    flock($lockFp, LOCK_UN);
    exit(1);
}
if (!rename($tmpPath, $finalPath)) {
    fwrite(STDERR, "Не удалось переместить архив в {$finalPath}.\n");
    @unlink($tmpPath);
    flock($lockFp, LOCK_UN);
    exit(1);
}
file_put_contents($finalPath . '.sha256', $sha . "  {$finalName}\n");
$bytes = filesize($finalPath) ?: 0;

// --- 4. ротация: хранить последние --keep архивов ------------------------------
$archives = glob($outDir . '/backup-*.tar.gz') ?: [];
// имена содержат YYYYMMDD-HHMMSS — лексикографическая сортировка = хронологическая
sort($archives);
$excess = count($archives) - $keep;
for ($i = 0; $i < $excess; $i++) {
    unlink($archives[$i]);
    @unlink($archives[$i] . '.sha256');
}

// --- 5. запись в data/backup.log (JSON Lines) -----------------------------------
$logLine = json_encode([
    'ts' => date('c'),
    'file' => $finalName,
    'bytes' => $bytes,
    'sha256' => $sha,
    'tag' => $tag,
], JSON_UNESCAPED_UNICODE) . "\n";
file_put_contents($root . '/data/backup.log', $logLine, FILE_APPEND | LOCK_EX);

echo "OK: {$finalName} ({$bytes} bytes, sha256={$sha})\n";

flock($lockFp, LOCK_UN);
fclose($lockFp);
exit(0);
