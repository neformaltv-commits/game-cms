<?php

declare(strict_types=1);

/**
 * Seed встроенных паттернов движения из фикстур config/pattern-defaults/*.json.
 * Идемпотентен (ТЗ Блок 5 §6). Использование: php cli/seed-movement-patterns.php
 */

require dirname(__DIR__) . '/src/autoload.php';
require dirname(__DIR__) . '/src/bootstrap.php';

use App\Library\LibraryService;

$services = \App\services();
$svc = new LibraryService($services['store'], $services['audit'], LibraryService::KIND_PATTERN);
$result = $svc->seed('cli');

fwrite(STDOUT, sprintf(
    "Паттерны движения: добавлено %d, пропущено %d, конфликтов %d.\n",
    $result['added'],
    $result['skipped'],
    $result['conflicts']
));
exit(0);
