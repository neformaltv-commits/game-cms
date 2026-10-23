<?php

declare(strict_types=1);

/**
 * Минимальный тест-раннер без зависимостей (типичное решение для flat-file проекта).
 * Каждый файл tests/**/Test*.php объявляет класс, расширяющий TestCase.
 * Выход: 0 — все зелёные, 1 — есть падения.
 */

require __DIR__ . '/../src/autoload.php';
require __DIR__ . '/TestCase.php';

error_reporting(E_ALL);
ini_set('display_errors', '1');

$files = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    if ($f->isFile() && preg_match('/Test.*\.php$/', $f->getFilename())) {
        $files[] = $f->getPathname();
    }
}
sort($files);

$before = get_declared_classes();
foreach ($files as $file) {
    require $file;
}
$after = get_declared_classes();

$totalPass = 0;
$failures = [];

foreach (array_diff($after, $before) as $class) {
    $ref = new ReflectionClass($class);
    if (!$ref->isSubclassOf(TestCase::class) || $ref->isAbstract()) {
        continue;
    }
    foreach ($ref->getMethods(ReflectionMethod::IS_PUBLIC) as $m) {
        if (!str_starts_with($m->getName(), 'test')) {
            continue;
        }
        $instance = $ref->newInstance();
        try {
            $instance->{$m->getName()}();
            $totalPass++;
            echo '.';
        } catch (\Throwable $e) {
            $failures[] = sprintf(
                "%s::%s\n    %s\n    at %s:%d",
                $class,
                $m->getName(),
                $e->getMessage(),
                str_replace(dirname(__DIR__) . '/', '', $e->getFile()),
                $e->getLine()
            );
            echo 'F';
        } finally {
            $instance->tearDown();
        }
    }
}

echo "\n\n";
if ($failures !== []) {
    echo "FAILURES (" . count($failures) . "):\n";
    foreach ($failures as $i => $f) {
        echo '  ' . ($i + 1) . ") {$f}\n";
    }
    echo "\n";
    exit(1);
}
echo "OK: {$totalPass} tests passed.\n";
exit(0);
