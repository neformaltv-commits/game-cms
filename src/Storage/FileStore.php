<?php

declare(strict_types=1);

namespace App\Storage;

/**
 * Flat-file JSON-хранилище (ТЗ блок 2, §2).
 *
 * Атомарная запись через tmp + rename, блокировки flock на lock-файле,
 * валидация относительных путей (защита от path traversal).
 */
class FileStore
{
    private string $rootDir;

    public function __construct(string $rootDir)
    {
        $real = realpath($rootDir);
        if ($real === false) {
            // Создаём каталог данных, если его нет (типично для первой установки).
            if (!is_dir($rootDir) && !@mkdir($rootDir, 0755, true) && !is_dir($rootDir)) {
                throw new StorageException("Не удалось создать каталог: {$rootDir}");
            }
            $real = realpath($rootDir);
            if ($real === false) {
                throw new StorageException("Неверный корневой каталог: {$rootDir}");
            }
        }
        $this->rootDir = $real;
    }

    public function getRootDir(): string
    {
        return $this->rootDir;
    }

    /**
     * Читает JSON-файл. Если файла нет — возвращает $default.
     * Битый JSON — StorageException (ТЗ 2.3).
     */
    public function read(string $relativePath, mixed $default = []): mixed
    {
        $full = $this->resolvePath($relativePath);
        if (!file_exists($full)) {
            return $default;
        }

        $lock = $this->openLock($relativePath);
        try {
            if (flock($lock, LOCK_SH) === false) {
                throw new StorageException("Не удалось заблокировать для чтения: {$relativePath}");
            }
            $contents = file_get_contents($full);
            flock($lock, LOCK_UN);
        } finally {
            fclose($lock);
        }

        if ($contents === false) {
            throw new StorageException("Не удалось прочитать файл: {$relativePath}");
        }
        if (trim($contents) === '') {
            // Пустой файл трактуем как «нет данных».
            return $default;
        }

        $data = json_decode($contents, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new StorageException(
                "Битый JSON в файле {$relativePath}: " . json_last_error_msg()
            );
        }
        return $data;
    }

    /**
     * Атомарно записывает JSON-файл (ТЗ 2.2).
     */
    public function write(string $relativePath, mixed $data): void
    {
        $full = $this->resolvePath($relativePath);

        $json = json_encode(
            $data,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        if ($json === false || json_last_error() !== JSON_ERROR_NONE) {
            throw new StorageException(
                "Ошибка сериализации JSON для {$relativePath}: " . json_last_error_msg()
            );
        }

        $dir = dirname($full);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new StorageException("Не удалось создать каталог: {$dir}");
        }

        $lock = $this->openLock($relativePath);
        try {
            if (flock($lock, LOCK_EX) === false) {
                throw new StorageException("Не удалось заблокировать для записи: {$relativePath}");
            }

            $tmp = $full . '.tmp.' . uniqid('', true);
            if (file_put_contents($tmp, $json . "\n") === false) {
                @unlink($tmp);
                throw new StorageException("Не удалось записать временный файл: {$relativePath}");
            }
            // fsync содержимого до rename (если доступно).
            if (function_exists('fsync')) {
                $h = @fopen($tmp, 'r');
                if ($h !== false) {
                    @fsync($h);
                    fclose($h);
                }
            }
            if (!@rename($tmp, $full)) {
                @unlink($tmp);
                throw new StorageException("Не удалось переименовать временный файл в {$relativePath}");
            }
            @chmod($full, 0644);

            flock($lock, LOCK_UN);
        } finally {
            fclose($lock);
        }
    }

    public function exists(string $relativePath): bool
    {
        $full = $this->resolvePath($relativePath);
        return file_exists($full);
    }

    /**
     * Атомарно удаляет файл (с блокировкой). Отсутствие файла — не ошибка.
     */
    public function delete(string $relativePath): void
    {
        $full = $this->resolvePath($relativePath);
        if (!file_exists($full)) {
            return;
        }
        $lock = $this->openLock($relativePath);
        try {
            if (flock($lock, LOCK_EX) === false) {
                throw new StorageException("Не удалось заблокировать для удаления: {$relativePath}");
            }
            if (!@unlink($full)) {
                throw new StorageException("Не удалось удалить файл: {$relativePath}");
            }
            flock($lock, LOCK_UN);
        } finally {
            fclose($lock);
        }
    }

    /**
     * Транзакция: читает, применяет $mutator, пишет (ТЗ 2.4).
     * $mutator принимает &$data; возврат false — не сохранять.
     */
    public function mutate(string $relativePath, callable $mutator): void
    {
        $lock = $this->openLock($relativePath);
        try {
            if (flock($lock, LOCK_EX) === false) {
                throw new StorageException("Не удалось заблокировать для мутации: {$relativePath}");
            }
            $data = $this->read($relativePath);
            if ($mutator($data) !== false) {
                $this->write($relativePath, $data);
            }
            flock($lock, LOCK_UN);
        } finally {
            fclose($lock);
        }
    }

    /**
     * Валидация относительного пути (ТЗ блок 3, §5.1).
     */
    private function resolvePath(string $relativePath): string
    {
        if (!preg_match('#^[a-zA-Z0-9._/-]+$#', $relativePath)) {
            throw new StorageException("Недопустимый путь: {$relativePath}");
        }
        if (str_contains($relativePath, '..')) {
            throw new StorageException("Путь содержит «..»: {$relativePath}");
        }
        if (str_starts_with($relativePath, '/')) {
            throw new StorageException("Путь не может быть абсолютным: {$relativePath}");
        }
        if (str_contains($relativePath, "\0")) {
            throw new StorageException("Путь содержит NUL: {$relativePath}");
        }

        $full = $this->rootDir . '/' . $relativePath;
        // Если файл существует — проверяем realpath.
        $real = realpath($full);
        if ($real !== false && !str_starts_with($real, $this->rootDir . DIRECTORY_SEPARATOR)) {
            throw new StorageException("Путь вне корневого каталога: {$relativePath}");
        }
        // Для записи несуществующего файла дополнительно защищаемся от symlink-атак
        // по существующей части пути.
        $dirReal = realpath(dirname($full));
        if ($dirReal !== false && !str_starts_with($dirReal, $this->rootDir . DIRECTORY_SEPARATOR)) {
            throw new StorageException("Каталог вне корневого: {$relativePath}");
        }
        return $full;
    }

    /**
     * Открывает lock-файл рядом с целевым (ТЗ 2.2 п.4).
     * @return resource
     */
    private function openLock(string $relativePath)
    {
        $lockPath = $this->rootDir . '/' . $relativePath . '.lock';
        $dir = dirname($lockPath);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new StorageException("Не удалось создать каталог для lock-файла: {$dir}");
        }
        $handle = fopen($lockPath, 'c');
        if ($handle === false) {
            throw new StorageException("Не удалось открыть lock-файл: {$lockPath}");
        }
        return $handle;
    }
}
