<?php

declare(strict_types=1);

namespace App\Auth;

/**
 * Валидация пароля (ТЗ блок 3, §2.1): длина >= 10, запрещён пустой и из
 * фиксированного чёрного списка; мягкая рекомендация букв+цифр.
 */
final class PasswordPolicy
{
    private const BLACKLIST = ['123456', 'password', 'admin', 'qwerty'];

    /** @return list<string> список ошибок; пусто — пароль валиден */
    public static function validate(string $password): array
    {
        $errors = [];
        if ($password === '') {
            $errors[] = 'Пароль не может быть пустым.';
            return $errors;
        }
        if (mb_strlen($password) < 10) {
            $errors[] = 'Пароль должен быть не короче 10 символов.';
        }
        if (in_array(mb_strtolower($password), self::BLACKLIST, true)) {
            $errors[] = 'Пароль слишком простой, выберите другой.';
        }
        return $errors;
    }

    public static function hash(string $password): string
    {
        return password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
    }

    public static function verify(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }
}
