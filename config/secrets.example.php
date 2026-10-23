<?php

declare(strict_types=1);

// Шаблон секретов (копировать в secrets.php и заполнить; secrets.php вне git — ТЗ §4.4).
return [
    'session_secret' => '', // random 64 hex: php -r "echo bin2hex(random_bytes(32));"
    'app_pepper'     => '', // опционально
];
