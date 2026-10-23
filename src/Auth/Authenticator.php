<?php

declare(strict_types=1);

namespace App\Auth;

use App\Audit\Logger;
use App\Storage\FileStore;

/**
 * Аутентификация (ТЗ блок 3, §2): users.json + bcrypt + сессии PHP.
 * Класс совместим с CLI/тестами: сессионные операции выполняются только
 * если сессия активна (session_status() === PHP_SESSION_ACTIVE).
 */
class Authenticator
{
    public const USERS_FILE = 'users.json';

    public function __construct(
        private readonly FileStore $store,
        private readonly Logger $audit,
        private readonly Throttle $throttle,
        private readonly array $config = [],
    ) {
    }

    /** @return list<array<string,mixed>> */
    public function allUsers(): array
    {
        $users = $this->store->read(self::USERS_FILE, []);
        return is_array($users) ? array_values($users) : [];
    }

    /** @return array<string,mixed>|null */
    public function findByUsername(string $username): ?array
    {
        foreach ($this->allUsers() as $u) {
            if (($u['username'] ?? null) === $username) {
                return $u;
            }
        }
        return null;
    }

    /**
     * Создаёт пользователя. Идемпотентность по username — ошибка (ТЗ §2.6).
     * @return array<string,mixed> созданная запись
     */
    public function createUser(string $username, string $password, string $role = 'admin'): array
    {
        if (!preg_match('#^[a-zA-Z0-9_-]{3,40}$#', $username)) {
            throw new \InvalidArgumentException('Имя пользователя: 3–40 символов [a-zA-Z0-9_-].');
        }
        $errors = PasswordPolicy::validate($password);
        if ($errors !== []) {
            throw new \InvalidArgumentException(implode(' ', $errors));
        }
        if ($this->findByUsername($username) !== null) {
            throw new \DomainException("Пользователь «{$username}» уже существует.");
        }

        $newUser = null;
        $this->store->mutate(self::USERS_FILE, function (&$data) use ($username, $password, $role, &$newUser): bool {
            if (!is_array($data)) {
                $data = [];
            }
            $ids = array_map(static fn ($u) => $u['id'] ?? '', $data);
            $newUser = [
                'id'            => \App\Support\Id::next('user', $ids),
                'username'      => $username,
                'password_hash' => PasswordPolicy::hash($password),
                'role'          => $role,
                'created_at'    => gmdate('Y-m-d\TH:i:s\Z'),
                'last_login_at' => null,
            ];
            $data[] = $newUser;
            return true;
        });
        $this->audit->log('user.create', (string)$newUser['id'], ['username' => $username]);
        return $newUser;
    }

    /**
     * Попытка входа без CSRF/throttle-HTTP-обвязки (используется в тестах и из login-контроллера).
     * Возвращает true при успехе. При успехе обновляет last_login_at и сбрасывает throttle.
     */
    public function attempt(string $username, string $password, string $ip = ''): bool
    {
        $user = $this->findByUsername($username);
        $ok = false;
        if ($user !== null && PasswordPolicy::verify($password, (string)$user['password_hash'])) {
            $ok = true;
        }

        if (!$ok) {
            if ($ip !== '') {
                $this->throttle->registerFailure($ip);
            }
            $this->audit->log('login_fail', '', ['username' => $username, 'ip' => $ip]);
            usleep(random_int(200_000, 500_000)); // замедление перебора (ТЗ §2.5)
            return false;
        }

        if ($ip !== '') {
            $this->throttle->reset($ip);
        }
        $this->store->mutate(self::USERS_FILE, function (&$data) use ($user): bool {
            foreach ($data as &$u) {
                if (($u['id'] ?? null) === ($user['id'] ?? null)) {
                    $u['last_login_at'] = gmdate('Y-m-d\TH:i:s\Z');
                }
            }
            return true;
        });
        $this->audit->log('login', (string)$user['id'], ['username' => $username], (string)$user['username']);
        return true;
    }

    /**
     * Полная процедура входа для веб-контекста: regenerate id, запись в сессию, ротация CSRF.
     */
    public function login(string $username, string $password, string $ip = ''): bool
    {
        $user = $this->findByUsername($username);
        if ($user === null) {
            // Timing-safe: сверяем с фиктивным хешем.
            password_verify($password, '$2y$12$invalidinvalidinvalidinvalidinvalidinvalidinvalidinvalidinv');
        }
        if (!$this->attempt($username, $password, $ip)) {
            return false;
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
            $_SESSION['user'] = [
                'id'       => $user['id'],
                'username' => $user['username'],
                'role'     => $user['role'],
            ];
            $_SESSION['last_activity'] = time();
            $_SESSION['user_agent_hash'] = hash('sha256', (string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
            Csrf::rotate();
        }
        return true;
    }

    /** Проверка активной сессии: существование, таймаут, UA-хеш (ТЗ §2.3). */
    public static function checkSession(array $config = []): bool
    {
        if (session_status() !== PHP_SESSION_ACTIVE || empty($_SESSION['user'])) {
            return false;
        }
        $timeout = (int)($config['session_timeout'] ?? 3600);
        $last = (int)($_SESSION['last_activity'] ?? 0);
        if (time() - $last >= $timeout) {
            self::destroySession();
            return false;
        }
        $uaHash = hash('sha256', (string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
        if (($_SESSION['user_agent_hash'] ?? null) !== $uaHash) {
            self::destroySession();
            return false;
        }
        $_SESSION['last_activity'] = time();
        return true;
    }

    public static function currentUser(): ?array
    {
        return $_SESSION['user'] ?? null;
    }

    public static function destroySession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION = [];
            if (ini_get('session.use_cookies')) {
                $p = session_get_cookie_params();
                setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
            }
            session_destroy();
        }
    }

    /**
     * Смена пароля (ТЗ §2.6). Проверяет old_password, валидирует new, совпадение подтверждения.
     * @return list<string> ошибки; пусто — успех
     */
    public function changePassword(string $userId, string $oldPassword, string $newPassword, string $confirm): array
    {
        $errors = [];
        $user = null;
        foreach ($this->allUsers() as $u) {
            if (($u['id'] ?? null) === $userId) {
                $user = $u;
                break;
            }
        }
        if ($user === null) {
            return ['Пользователь не найден.'];
        }
        if (!PasswordPolicy::verify($oldPassword, (string)$user['password_hash'])) {
            $errors[] = 'Текущий пароль неверен.';
        }
        $errors = array_merge($errors, PasswordPolicy::validate($newPassword));
        if ($newPassword !== $confirm) {
            $errors[] = 'Новый пароль и подтверждение не совпадают.';
        }
        if ($errors !== []) {
            return $errors;
        }
        $hash = PasswordPolicy::hash($newPassword);
        $this->store->mutate(self::USERS_FILE, function (&$data) use ($userId, $hash): bool {
            foreach ($data as &$u) {
                if (($u['id'] ?? null) === $userId) {
                    $u['password_hash'] = $hash;
                }
            }
            return true;
        });
        $this->audit->log('user.password_change', $userId);
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        return [];
    }
}
