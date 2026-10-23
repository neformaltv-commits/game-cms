<?php

declare(strict_types=1);

/**
 * Публичный эндпоинт /api/emoji-bank.json (ТЗ блок 6 §7).
 * Отдаёт только {codepoint: {type, url/frames}} — без id/name/category/hash/source_size.
 * Без авторизации; rate-limit 120/мин на IP; Cache-Control + ETag + Last-Modified.
 */

require __DIR__ . '/../_bootstrap.php';

use App\Http\ClientIp;
use App\Http\Response;

$limit = $app['limiter']->check(
    \App\RateLimit\Limiter::keyFor('api/emoji-bank.json', ClientIp::get($app['config'])),
    120,
    60,
);
if (!$limit['allowed']) {
    header('Retry-After: ' . $limit['retry_after']);
    Response::json(429, ['error' => 'Слишком много запросов. Повторите позже.']);
    exit;
}

$bankFile = rtrim((string)$app['config']['data_dir'], '/') . '/emoji-bank.json';
$mtime = is_file($bankFile) ? (int)filemtime($bankFile) : 0;
$map = $app['emojiBank']->publicMap();
$body = (string)json_encode($map, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$etag = '"' . hash('sha256', $body) . '"';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=3600');
header('ETag: ' . $etag);
if ($mtime > 0) {
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
}

// 304 по If-None-Match (условный запрос — типовое решение)
if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
    http_response_code(304);
    exit;
}

http_response_code(200);
echo $body;
