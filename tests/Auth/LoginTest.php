<?php

declare(strict_types=1);

namespace Tests\Auth;

use App\Audit\Logger;
use App\Auth\Authenticator;
use App\Auth\Csrf;
use App\Auth\Throttle;
use App\Storage\FileStore;

require_once __DIR__ . '/../TestCase.php';

/**
 * Тесты аутентификации (ТЗ блок 3 §2): bcrypt, throttle 5/10мин, смена пароля.
 * Сессионные ветки проверяются отдельно через HTTP (php -S) — scripts/smoke-http.sh.
 */
final class LoginTest extends \TestCase
{
    private string $tmp = '';
    private Authenticator $auth;
    private Throttle $throttle;

    private function boot(): void
    {
        $this->tmp = sys_get_temp_dir() . '/logintest-' . bin2hex(random_bytes(6));
        mkdir($this->tmp . '/data', 0777, true);
        $store = new FileStore($this->tmp . '/data');
        $audit = new Logger($store, 'audit.log', 14);
        $this->throttle = new Throttle($store);
        $this->auth = new Authenticator($store, $audit, $this->throttle, []);
    }

    public function tearDown(): void
    {
        if ($this->tmp !== '') {
            exec('rm -rf ' . escapeshellarg($this->tmp));
            $this->tmp = '';
        }
        parent::tearDown();
    }

    public function testCreateUserAndVerifyHash(): void
    {
        $this->boot();
        $u = $this->auth->createUser('admin', 'Sup3r#SecretPass!');
        $this->assertSame('admin', $u['username']);
        $this->assertTrue(str_starts_with((string)$u['password_hash'], '$2y$12$'), 'bcrypt cost 12');
        $saved = $this->auth->findByUsername('admin');
        $this->assertNotNull($saved);
        $this->assertFalse(isset($saved['password']), 'пароль в открытом виде не хранится');
    }

    public function testDuplicateUsernameRejected(): void
    {
        $this->boot();
        $this->auth->createUser('admin', 'Sup3r#SecretPass!');
        $this->assertThrows(\DomainException::class, function (): void {
            $this->auth->createUser('admin', 'An0ther#Secret!!');
        });
    }

    public function testWeakPasswordRejected(): void
    {
        $this->boot();
        $this->assertThrows(\InvalidArgumentException::class, function (): void {
            $this->auth->createUser('bob', 'short1#A'); // < 12 символов
        });
        $this->assertThrows(\InvalidArgumentException::class, function (): void {
            $this->auth->createUser('bob', 'qwerty'); // чёрный список (ТЗ §2.1)
        });
    }

    public function testAttemptSuccessUpdatesLastLogin(): void
    {
        $this->boot();
        $this->auth->createUser('admin', 'Sup3r#SecretPass!');
        $ok = $this->auth->attempt('admin', 'Sup3r#SecretPass!', '203.0.113.1');
        $this->assertTrue($ok);
        $u = $this->auth->findByUsername('admin');
        $this->assertNotNull($u['last_login_at'] ?? null);
    }

    public function testAttemptWrongPasswordFails(): void
    {
        $this->boot();
        $this->auth->createUser('admin', 'Sup3r#SecretPass!');
        $this->assertFalse($this->auth->attempt('admin', 'Wr0ng#Password!!', '203.0.113.2'));
        $this->assertFalse($this->auth->attempt('nosuchuser', 'Sup3r#SecretPass!', '203.0.113.2'));
    }

    public function testThrottleBlocksAfterFiveFailures(): void
    {
        $this->boot();
        $this->auth->createUser('admin', 'Sup3r#SecretPass!');
        $ip = '203.0.113.7';
        for ($i = 0; $i < 5; $i++) {
            $this->assertFalse($this->auth->attempt('admin', 'bad#Passw0rd!!', $ip));
        }
        $blocked = $this->throttle->check($ip);
        $this->assertTrue($blocked['blocked'], 'IP заблокирован после 5 неудач');
        $this->assertTrue($blocked['retry_after'] > 0 && $blocked['retry_after'] <= 600);
        // Успешный вход сбрасывает throttle (контроллер login.php проверяет блокировку до attempt).
        $this->assertTrue($this->auth->attempt('admin', 'Sup3r#SecretPass!', $ip));
        $this->assertFalse($this->throttle->check($ip)['blocked'], 'успешный вход сбрасывает throttle');
    }

    public function testChangePassword(): void
    {
        $this->boot();
        $u = $this->auth->createUser('admin', 'Sup3r#SecretPass!');
        $errs = $this->auth->changePassword((string)$u['id'], 'Bad#OldPassword!', 'N3w#SecretPass!!', 'N3w#SecretPass!!');
        $this->assertCount(1, $errs);
        $errs = $this->auth->changePassword((string)$u['id'], 'Sup3r#SecretPass!', 'N3w#SecretPass!!', 'Different#123!');
        $this->assertContains('Новый пароль и подтверждение не совпадают.', $errs);
        $errs = $this->auth->changePassword((string)$u['id'], 'Sup3r#SecretPass!', 'N3w#SecretPass!!', 'N3w#SecretPass!!');
        $this->assertSame([], $errs);
        $this->assertTrue($this->auth->attempt('admin', 'N3w#SecretPass!!', ''));
        $this->assertFalse($this->auth->attempt('admin', 'Sup3r#SecretPass!', ''));
    }

    public function testAuditLogsDoNotContainPasswords(): void
    {
        $this->boot();
        $this->auth->createUser('admin', 'Sup3r#SecretPass!');
        $this->auth->attempt('admin', 'bad', '198.51.100.1');
        $raw = (string)file_get_contents($this->tmp . '/data/audit.log');
        $this->assertFalse(str_contains($raw, 'Sup3r#SecretPass!'), 'пароль не попадает в аудит');
        $this->assertTrue(str_contains($raw, 'login_fail'));
    }

    /** CSRF: токен стабильный, hash_equals-проверка, ротация обесценивает старый токен. */
    public function testCsrfTokenLifecycle(): void
    {
        $_SESSION = [];
        $token = Csrf::token();
        $this->assertSame(64, strlen($token), 'токен — 32 байта hex');
        $this->assertSame($token, Csrf::token(), 'токен не ротируется на каждый запрос');
        $this->assertTrue(Csrf::validate($token));
        $this->assertFalse(Csrf::validate('deadbeef'));
        $this->assertFalse(Csrf::validate(null));
        Csrf::rotate();
        $rotated = Csrf::token();
        $this->assertFalse($rotated === $token, 'ротация меняет токен');
        $this->assertTrue(Csrf::validate($rotated));
        $this->assertFalse(Csrf::validate($token), 'старый токен невалиден после ротации');
        $_SERVER['HTTP_X_CSRF_TOKEN'] = $rotated;
        $this->assertSame($rotated, Csrf::tokenFromRequest());
        unset($_SERVER['HTTP_X_CSRF_TOKEN']);
        $_POST['_csrf'] = $rotated;
        $this->assertSame($rotated, Csrf::tokenFromRequest());
        unset($_POST['_csrf']);
    }
}
