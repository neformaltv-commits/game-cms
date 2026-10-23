<?php

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

use App\Auth\Authenticator;
use App\Http\Response;

// Экспорт справочников доступен только администратору.
if (!Authenticator::checkSession($app['config'])) {
    Response::redirect('admin/login.php');
}

$target = (string)($_GET['target'] ?? '');
$file = match ($target) {
    'genres' => 'genres.json',
    'categories' => 'categories.json',
    default => null,
};
if ($file === null) {
    Response::notFound();
    exit;
}

$data = $app['store']->read($file, []);
$app['audit']->log('reference.export', $file);

Response::status(200);
header('Content-Type: application/json; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $file . '"');
echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
