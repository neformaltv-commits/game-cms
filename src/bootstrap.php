<?php

declare(strict_types=1);

namespace App;

use App\Audit\Logger;
use App\Auth\Authenticator;
use App\Auth\Throttle;
use App\RateLimit\Limiter;
use App\Storage\FileStore;

/**
 * Сборка сервисов приложения (простая фабрика без DI-контейнера).
 *
 * @return array{
 *   config: array<string,mixed>,
 *   store: FileStore,
 *   audit: Logger,
 *   throttle: Throttle,
 *   auth: Authenticator,
 *   limiter: Limiter,
 *   genres: GenreService,
 *   categories: CategoryService,
 *   references: ReferenceImporter
 * }
 */
function services(?string $root = null): array
{
    $root ??= dirname(__DIR__);
    $config = require $root . '/config/config.php';
    if (is_file($root . '/config/secrets.php')) {
        $config += require $root . '/config/secrets.php';
    }

    $store = new FileStore($config['data_dir']);
    $audit = new Logger($store, 'audit.log', (int)($config['audit_rotation_keep'] ?? 14));
    $throttle = new Throttle($store);
    $auth = new Authenticator($store, $audit, $throttle, $config);
    $limiter = new Limiter($store);
    $genres = new GenreService($store, $audit, $config['config_dir']);
    $categories = new CategoryService($store, $audit);
    $references = new ReferenceImporter($store);
    $emojiBank = new \App\Emoji\EmojiBankService($store, $audit, $config['media_dir']);

    return compact('config', 'store', 'audit', 'throttle', 'auth', 'limiter', 'genres', 'categories', 'references', 'emojiBank');
}
