<?php

declare(strict_types=1);

/**
 * Простейший PSR-4 автозагрузчик для namespace App\ => src/ (нулевые зависимости, ТЗ блок 1 §3).
 */
spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $file = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require $file;
    }
});
