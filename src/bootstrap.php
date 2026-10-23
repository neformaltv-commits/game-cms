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
 * @return array<string,mixed>
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
    $aiModels = new \App\Library\LibraryService($store, $audit, \App\Library\LibraryService::KIND_AI);
    $patterns = new \App\Library\LibraryService($store, $audit, \App\Library\LibraryService::KIND_PATTERN);
    $legendValidator = new \App\Validation\LegendValidator($aiModels, $patterns);
    $gameValidator = new \App\Validation\GameValidator($genres, $categories, $legendValidator, $store, $config['media_dir']);
    $games = new GameService($store, $audit, $genres, $gameValidator, $config['media_dir']);
    $levels = new LevelService($store, $audit, $games, $genres, $legendValidator);
    $settings = new \App\Settings\SettingsService($store, $audit, $genres, $games);

    return compact('config', 'store', 'audit', 'throttle', 'auth', 'limiter', 'genres', 'categories', 'references', 'emojiBank', 'aiModels', 'patterns', 'legendValidator', 'gameValidator', 'games', 'levels', 'settings');
}
