<?php

declare(strict_types=1);

require __DIR__ . '/../src/autoload.php';

use App\Auth\PasswordPolicy;
use App\Auth\Throttle;
use App\Audit\Logger;
use App\Auth\Authenticator;
use App\Storage\FileStore;

/**
 * CLI: создание администратора (ТЗ блок 3 §2.6).
 *   php cli/create-admin.php --username=admin --password=secret
 * Идемпотентна: повторный запуск с тем же username — ошибка и код выхода 1.
 */
$options = getopt('', ['username::', 'password::']);
$username = (string)($options['username'] ?? '');
$password = (string)($options['password'] ?? '');

if ($username === '' || $password === '') {
    fwrite(STDERR, "Использование: php cli/create-admin.php --username=<name> --password=<pass>\n");
    exit(1);
}

$errors = PasswordPolicy::validate($password);
if ($errors !== []) {
    fwrite(STDERR, implode("\n", $errors) . "\n");
    exit(1);
}

$root = dirname(__DIR__);
$config = require $root . '/config/config.php';
$store = new FileStore($config['data_dir']);
$audit = new Logger($store);
$auth = new Authenticator($store, $audit, new Throttle($store), $config);

try {
    $user = $auth->createUser($username, $password);
} catch (\DomainException | \InvalidArgumentException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

// Пароль в вывод не печатаем.
fwrite(STDOUT, "Создан пользователь {$user['id']} ({$user['username']}).\n");
exit(0);
