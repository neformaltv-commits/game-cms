<?php

declare(strict_types=1);

/**
 * Seed встроенных AI-моделей из фикстур config/ai-defaults/*.json.
 * Идемпотентен (ТЗ Блок 5 §6). Использование: php cli/seed-ai-models.php
 */

require dirname(__DIR__) . '/src/autoload.php';
require dirname(__DIR__) . '/src/bootstrap.php';

use App\Library\LibraryService;

$services = \App\services();
$svc = new LibraryService($services['store'], $services['audit'], LibraryService::KIND_AI);
$result = $svc->seed('cli');

fwrite(STDOUT, sprintf(
    "AI-модели: добавлено %d, пропущено %d, конфликтов %d.\n",
    $result['added'],
    $result['skipped'],
    $result['conflicts']
));
exit(0);
