<?php

declare(strict_types=1);

/**
 * Базовый класс тестов: assert-хелперы + временный каталог данных.
 */
abstract class TestCase
{
    /** @var list<string> */
    private array $tmpDirs = [];

    protected function assertTrue(bool $cond, string $message = 'expected true'): void
    {
        if (!$cond) {
            throw new RuntimeException($message);
        }
    }

    protected function assertFalse(bool $cond, string $message = 'expected false'): void
    {
        if ($cond) {
            throw new RuntimeException($message);
        }
    }

    protected function assertSame(mixed $expected, mixed $actual, string $message = ''): void
    {
        if ($expected !== $actual) {
            $e = var_export($expected, true);
            $a = var_export($actual, true);
            throw new RuntimeException(($message ?: 'not identical') . "\n      expected: {$e}\n      actual:   {$a}");
        }
    }

    protected function assertEquals(mixed $expected, mixed $actual, string $message = ''): void
    {
        if ($expected != $actual) {
            throw new RuntimeException(($message ?: 'not equal') . "\n      expected: " . var_export($expected, true)
                . "\n      actual:   " . var_export($actual, true));
        }
    }

    protected function assertCount(int $expected, \Countable|array $c, string $message = ''): void
    {
        $this->assertSame($expected, count($c), $message ?: 'wrong count');
    }

    /** Ожидаемое исключение. */
    protected function assertThrows(string $class, callable $fn, string $message = ''): void
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            if (!($e instanceof $class)) {
                throw new RuntimeException(($message ?: 'wrong exception type') . ': ' . get_class($e) . ' — ' . $e->getMessage());
            }
            return;
        }
        throw new RuntimeException(($message ?: 'exception not thrown') . ": ожидалось {$class}");
    }

    /** Создаёт временный каталог и возвращает путь (удаляется в tearDown автоматически). */
    protected function tmpDir(string $prefix = 'cms-test-'): string
    {
        $dir = sys_get_temp_dir() . '/' . $prefix . bin2hex(random_bytes(6));
        mkdir($dir, 0755, true);
        $this->tmpDirs[] = $dir;
        return $dir;
    }

    public function tearDown(): void
    {
        foreach ($this->tmpDirs as $dir) {
            $this->rrmdir($dir);
        }
        $this->tmpDirs = [];
        $_SESSION = [];
    }

    private function rrmdir(string $dir): void
    {
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_dir($path)) {
                $this->rrmdir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
