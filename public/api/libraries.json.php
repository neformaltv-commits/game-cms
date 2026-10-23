<?php

declare(strict_types=1);

/**
 * Публичный эндпоинт /api/libraries.json (ТЗ блок 5): справочник встроенных
 * AI-моделей и паттернов движения для редактора/движков. Только read-only поля.
 * Без авторизации; rate-limit 120/мин на IP; Cache-Control + ETag.
 */

require __DIR__ . '/../_bootstrap.php';

use App\Http\ClientIp;
use App\Http\Response;

$limit = $app['limiter']->check(
    \App\RateLimit\Limiter::keyFor('api/libraries.json', ClientIp::from($_SERVER, $app['config']['trusted_proxies'] ?? [])),
    120,
    60,
);
if (!$limit['allowed']) {
    header('Retry-After: ' . $limit['retry_after']);
    Response::json(429, ['error' => 'Слишком много запросов. Повторите позже.']);
    exit;
}

$publicView = static fn (array $rec): array => [
    'id' => (string)($rec['id'] ?? ''),
    'name' => (string)($rec['name'] ?? ''),
    'description' => (string)($rec['description'] ?? ''),
    'category' => (string)($rec['category'] ?? ''),
    'supported_engines' => array_values((array)($rec['supported_engines'] ?? [])),
    'params' => array_values(array_map(
        static fn (array $p): array => [
            'key' => (string)($p['key'] ?? ''),
            'type' => (string)($p['type'] ?? 'number'),
            'min' => $p['min'] ?? null,
            'max' => $p['max'] ?? null,
            'default' => $p['default'] ?? null,
            'unit' => $p['unit'] ?? null,
            'options' => $p['options'] ?? null,
        ],
        (array)($rec['params'] ?? [])
    )),
];

$body = (string)json_encode([
    'ai_models' => array_map($publicView, $app['aiModels']->all()),
    'movement_patterns' => array_map(
        static function (array $rec) use ($publicView): array {
            return $publicView($rec) + [
                'trajectory_hint' => (string)($rec['trajectory_hint'] ?? ''),
                'on_finish' => (string)($rec['on_finish'] ?? 'despawn'),
            ];
        },
        $app['patterns']->all()
    ),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

$etag = '"' . sha1($body) . '"';
$ifNoneMatch = trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''));
if ($ifNoneMatch === $etag) {
    http_response_code(304);
    exit;
}

header('Cache-Control: public, max-age=60');
header('ETag: ' . $etag);
Response::json(200, json_decode($body, true, 512, JSON_THROW_ON_ERROR));
