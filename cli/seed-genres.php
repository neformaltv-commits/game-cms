<?php

declare(strict_types=1);

require __DIR__ . '/../src/autoload.php';

use App\GenreService;
use App\Storage\FileStore;

/**
 * CLI: seed пяти жанров из фикстур config/genre-defaults (ТЗ блок 4 §3.2).
 * Идемпотентен. Выход: 0 — успех, 1 — ошибка.
 */
$dataDir = dirname(__DIR__) . '/data';
$configDir = dirname(__DIR__) . '/config';

$store = new FileStore($dataDir);
$audit = new \App\Audit\Logger($store);
$genres = new GenreService($store, $audit, $configDir);

$result = $genres->seed();

echo sprintf(
    "Seeded %d genres (%d existed, %d conflicts).\n",
    $result['added'],
    $result['skipped'],
    count($result['conflicts'])
);
foreach ($result['conflicts'] as $c) {
    echo "CONFLICT: {$c}\n";
}
exit(count($result['conflicts']) > 0 ? 1 : 0);
