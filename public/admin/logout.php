<?php

declare(strict_types=1);

require __DIR__ . '/../_bootstrap.php';

use App\Auth\Authenticator;
use App\Auth\Csrf;
use App\Http\Response;

csrf_guard(); // logout — POST с CSRF (ТЗ блок 3 §3.4)

Authenticator::destroySession();

// Новая пустая сессия только для flash-сообщения.
session_start();
Csrf::rotate();
flash_set('success', 'Вы вышли из системы.');
Response::redirect('login.php');
