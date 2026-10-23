<?php

declare(strict_types=1);

namespace App\Emoji;

use App\Audit\Logger;
use App\Storage\FileStore;
use App\Support\Id;

/**
 * Банк эмодзи (ТЗ блок 6).
 *
 * Хранит data/emoji-bank.json (массив записей) и data/emoji-bank-counter.json
 * (PUA-счётчик, не уменьшается при удалении). Файлы — public/media/emoji-bank/.
 */
final class EmojiBankService
{
    public const BANK_FILE = 'emoji-bank.json';
    public const COUNTER_FILE = 'emoji-bank-counter.json';
    public const MEDIA_DIR = 'emoji-bank'; // внутри public/media
    public const PUA_START = 0xE000;
    public const PUA_END = 0xF8FF;
    public const PUA_LIMIT = 6400;

    public const CATEGORIES = ['персонажи', 'оружие', 'природа', 'UI', 'символы', 'другое'];
    public const LOOP_MODES = ['loop', 'once', 'ping_pong'];

    public function __construct(
        private readonly FileStore $store,
        private readonly Logger $audit,
        private readonly string $mediaRoot, // абсолютный путь к public/media
        private readonly string $user = '',
    ) {
    }

    /** @return list<array<string,mixed>> */
    public function all(): array
    {
        /** @var list<array<string,mixed>> $rows */
        $rows = $this->store->read(self::BANK_FILE, []);
        return $rows;
    }

    /** @return array<string,mixed>|null */
    public function find(string $id): ?array
    {
        foreach ($this->all() as $row) {
            if (($row['id'] ?? '') === $id) {
                return $row;
            }
        }
        return null;
    }

    /** @return array<string,mixed>|null */
    public function findByCodepoint(string $codepoint): ?array
    {
        foreach ($this->all() as $row) {
            if (($row['codepoint'] ?? '') === $codepoint) {
                return $row;
            }
        }
        return null;
    }

    public function counterNext(): int
    {
        $c = $this->store->read(self::COUNTER_FILE, ['next' => 0]);
        return (int)(is_array($c) ? ($c['next'] ?? 0) : 0);
    }

    /**
     * Назначает id/codepoint по счётчику (ТЗ §3.6). Бросает UploadException при исчерпании PUA.
     *
     * @return array{id: string, codepoint: string}
     */
    public function allocateSlot(): array
    {
        $next = $this->counterNext();
        if ($next >= self::PUA_LIMIT) {
            throw new UploadException('Не осталось свободных PUA-кодпоинтов. Лимит — 6400.');
        }
        $idNum = $next + 1;
        return [
            'id' => 'e' . str_pad((string)$idNum, 3, '0', STR_PAD_LEFT),
            'codepoint' => sprintf('U+%04X', self::PUA_START + ($idNum - 1)),
        ];
    }

    /**
     * Добавляет статичный эмодзи из уже обработанных байтов (PNG или санитизированный SVG).
     * Счётчик инкрементируется только после успешной записи файла и JSON (ТЗ §3.6).
     *
     * @param string $format png|svg
     * @param array{width:int,height:int} $sourceSize
     * @return array<string,mixed> созданная запись
     */
    public function addStatic(
        string $format,
        string $bytes,
        string $name,
        string $category,
        array $sourceSize,
        bool $isGifSource = false,
        ?string $sanitizedHash = null,
    ): array {
        $errors = $this->validateMeta($name, $category);
        if ($errors !== []) {
            throw new UploadException(implode(' ', $errors));
        }
        if (!in_array($format, ['png', 'svg'], true)) {
            throw new UploadException('Неподдерживаемый формат хранения');
        }

        // Атомарная резервация слота под блокировкой счётчика (ТЗ §3.6):
        // параллельные загрузки не могут получить один и тот же codepoint.
        // Мутатор меняет сам $c (call-by-reference в mutate), а возвращает слот.
        $slot = $this->store->mutate(self::COUNTER_FILE, static function (mixed &$c) use ($format): array {
            $next = (int)(is_array($c) ? ($c['next'] ?? 0) : 0);
            if ($next >= self::PUA_LIMIT) {
                throw new UploadException('Не осталось свободных PUA-кодпоинтов. Лимит — 6400.');
            }
            $idNum = $next + 1;
            $num = 'e' . str_pad((string)$idNum, 3, '0', STR_PAD_LEFT);
            $c = ['next' => $idNum];
            return [
                'id' => $num,
                'codepoint' => sprintf('U+%04X', self::PUA_START + ($idNum - 1)),
                'file' => self::MEDIA_DIR . '/' . $num . '.' . $format,
            ];
        });
        /** @var array{id: string, codepoint: string, file: string} $slot */
        $id = $slot['id'];
        $relInMedia = $slot['file'];
        $absPath = $this->mediaRoot . '/' . $relInMedia;

        $dir = dirname($absPath);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            $this->rollbackCounter((int)substr($id, 1));
            throw new UploadException('Не удалось создать каталог медиа');
        }

        $now = gmdate('Y-m-d\TH:i:s\Z');
        $record = [
            'id' => $id,
            'codepoint' => $slot['codepoint'],
            'name' => $name,
            'category' => $category,
            'type' => 'static',
            'source_format' => $isGifSource ? 'gif' : $format,
            'stored_format' => $format,
            'image_path' => 'media/' . $relInMedia,
            'source_size' => ['width' => $sourceSize['width'], 'height' => $sourceSize['height']],
            'sanitized_hash' => $sanitizedHash,
            'frames' => null,
            'loop_mode' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        // Транзакция: файл -> JSON (ТЗ §3.6); ошибка — откат файла и счётчика.
        $fileWritten = false;
        try {
            if (file_put_contents($absPath, $bytes) === false) {
                throw new UploadException('Не удалось записать файл эмодзи');
            }
            $fileWritten = true;
            $this->store->mutate(self::BANK_FILE, static function (mixed &$rows) use ($record): bool {
                $rows = is_array($rows) ? $rows : [];
                $rows[] = $record;
                return true;
            });
        } catch (\Throwable $e) {
            if ($fileWritten) {
                @unlink($absPath);
            }
            $this->rollbackCounter((int)substr($id, 1));
            throw $e;
        }

        $this->audit->log('emoji_bank.upload', $id, [
            'format' => $isGifSource ? 'gif' : $format,
            'bytes' => strlen($bytes),
            'source_size' => $sourceSize,
            'codepoint' => $record['codepoint'],
        ], $this->user);

        return $record;
    }

    /**
     * Единая точка загрузки файла в банк (ТЗ §3.1–3.6): MIME -> формат-специфичная
     * обработка -> сохранение. Используется и админкой, и preview-эндпоинтом.
     *
     * @return array{record?: array<string,mixed>, preview?: array{format:string,bytes:string,width:int,height:int}, errors?: list<string>}
     */
    public function ingestFile(string $tmpPath, string $name, string $category, bool $persist = true): array
    {
        if (!is_file($tmpPath)) {
            throw new UploadException('Файл не найден');
        }
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = (string)$finfo->file($tmpPath);
        $allowed = ['image/png', 'image/gif', 'image/svg+xml', 'text/xml', 'application/xml'];
        if (!in_array($mime, $allowed, true)) {
            throw new UploadException('Допустимы только PNG, GIF, SVG');
        }
        if (filesize($tmpPath) > 200 * 1024) {
            throw new UploadException('Файл больше 200 KB');
        }

        if ($mime === 'image/png' || $mime === 'image/gif') {
            $res = (new RasterReencoder())->reencodeToPng($tmpPath);
            $preview = ['format' => 'png', 'bytes' => $res['png'], 'width' => $res['width'], 'height' => $res['height']];
            if (!$persist) {
                return ['preview' => $preview];
            }
            $record = $this->addStatic('png', $res['png'], $name, $category,
                ['width' => $res['width'], 'height' => $res['height']], $mime === 'image/gif');
            return ['record' => $record, 'preview' => $preview];
        }

        // SVG: xml-форматы принимаем только если корень <svg> (проверит санитайзер).
        $raw = (string)file_get_contents($tmpPath);
        $sanitized = (new GeometrySvgSanitizer())->sanitize($raw);
        $w = $sanitized->sourceWidth ?? 64;
        $h = $sanitized->sourceHeight ?? 64;
        $preview = ['format' => 'svg', 'bytes' => $sanitized->xml, 'width' => $w, 'height' => $h];
        if (!$persist) {
            return ['preview' => $preview];
        }
        $record = $this->addStatic('svg', $sanitized->xml, $name, $category,
            ['width' => $w, 'height' => $h], false, $sanitized->hash);
        return ['record' => $record, 'preview' => $preview];
    }

    /**
     * Заменяет изображение существующего static-эмодзи из файла (ТЗ §6.3).
     */
    public function replaceFromFile(string $id, string $tmpPath): array
    {
        $out = $this->ingestFile($tmpPath, '', '', false);
        $p = $out['preview'];
        if ($p['format'] === 'png') {
            return $this->replaceImage($id, 'png', $p['bytes'],
                ['width' => $p['width'], 'height' => $p['height']]);
        }
        return $this->replaceImage($id, 'svg', $p['bytes'],
            ['width' => $p['width'], 'height' => $p['height']], false,
            'sha256:' . hash('sha256', $p['bytes']));
    }

    /**
     * Заменяет изображение с уже обработанными байтами (см. replaceImage) — обёртка для SVG-хэша.
     */
    public function addStaticFromPreview(array $preview, string $name, string $category): array
    {
        if ($preview['format'] === 'png') {
            return $this->addStatic('png', $preview['bytes'], $name, $category,
                ['width' => $preview['width'], 'height' => $preview['height']]);
        }
        return $this->addStatic('svg', $preview['bytes'], $name, $category,
            ['width' => $preview['width'], 'height' => $preview['height']], false,
            'sha256:' . hash('sha256', $preview['bytes']));
    }

    /**
     * Заменяет изображение существующего static-эмодзи (ТЗ §6.3).
     */
    public function replaceImage(string $id, string $format, string $bytes, array $sourceSize, bool $isGifSource = false, ?string $sanitizedHash = null): array
    {
        $row = $this->find($id);
        if ($row === null || ($row['type'] ?? '') !== 'static') {
            throw new UploadException('Эмодзи не найден или не является статичным');
        }
        $absPath = $this->mediaRoot . '/' . preg_replace('#^media/#', '', (string)$row['image_path']);
        if (file_put_contents($absPath, $bytes) === false) {
            throw new UploadException('Не удалось записать файл эмодзи');
        }
        $result = $row;
        $this->store->mutate(self::BANK_FILE, static function (mixed &$rows) use ($id, $format, $sourceSize, $isGifSource, $sanitizedHash, &$result): bool {
            $rows = is_array($rows) ? $rows : [];
            foreach ($rows as &$r) {
                if (($r['id'] ?? '') === $id) {
                    $r['source_format'] = $isGifSource ? 'gif' : $format;
                    $r['stored_format'] = $format;
                    $r['source_size'] = ['width' => $sourceSize['width'], 'height' => $sourceSize['height']];
                    $r['sanitized_hash'] = $sanitizedHash;
                    $r['updated_at'] = gmdate('Y-m-d\TH:i:s\Z');
                    $result = $r;
                }
            }
            unset($r);
            return true;
        });
        $this->audit->log('emoji_bank.update', $id, ['changed' => ['image']], $this->user);
        return $result;
    }

    /**
     * Обновляет метаданные (name/category).
     *
     * @return list<string> ошибки; пустой список — успех
     */
    public function updateMeta(string $id, string $name, string $category): array
    {
        $errors = $this->validateMeta($name, $category);
        if ($errors !== []) {
            return $errors;
        }
        $found = false;
        $this->store->mutate(self::BANK_FILE, static function (mixed &$rows) use ($id, $name, $category, &$found): bool {
            $rows = is_array($rows) ? $rows : [];
            foreach ($rows as &$r) {
                if (($r['id'] ?? '') === $id) {
                    $r['name'] = $name;
                    $r['category'] = $category;
                    $r['updated_at'] = gmdate('Y-m-d\TH:i:s\Z');
                    $found = true;
                }
            }
            unset($r);
            return true;
        });
        if (!$found) {
            return ['Эмодзи не найден.'];
        }
        $this->audit->log('emoji_bank.update', $id, ['changed' => ['name', 'category']], $this->user);
        return [];
    }

    /**
     * Создаёт анимацию из кадров (ТЗ §5, §8.3).
     *
     * @param list<array{codepoint:string,duration_ms:int}> $frames
     * @return array<string,mixed>|\ArrayAccess запись либо массив ошибок (проверка is_error)
     */
    public function createAnimation(string $name, string $category, array $frames, string $loopMode): array
    {
        $errors = $this->validateMeta($name, $category);
        if ($errors !== []) {
            return ['errors' => $errors];
        }

        // Кадры валидируются ДО резервации слота (ТЗ §8.3): при ошибке счётчик не тратится.
        $frameErrs = array_merge($this->validateFrames($frames), $this->validateLoopMode($loopMode));
        if ($frameErrs !== []) {
            return ['errors' => $frameErrs];
        }

        $slot = $this->allocateSlot();
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $record = [
            'id' => $slot['id'],
            'codepoint' => $slot['codepoint'],
            'name' => $name,
            'category' => $category,
            'type' => 'animation',
            'source_format' => 'animation',
            'stored_format' => null,
            'image_path' => null,
            'source_size' => null,
            'sanitized_hash' => null,
            'frames' => array_values(array_map(static fn(array $f): array => [
                'codepoint' => (string)$f['codepoint'],
                'duration_ms' => (int)$f['duration_ms'],
            ], $frames)),
            'loop_mode' => $loopMode,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        $this->store->mutate(self::BANK_FILE, static function (mixed &$rows) use ($record): bool {
            $rows = is_array($rows) ? $rows : [];
            $rows[] = $record;
            return true;
        });
        $this->bumpCounterTo((int)substr($record['id'], 1));

        $total = array_sum(array_column($record['frames'], 'duration_ms'));
        $this->audit->log('emoji_bank.animation_create', $record['id'], [
            'codepoint' => $record['codepoint'],
            'frames' => array_column($record['frames'], 'codepoint'),
            'loop_mode' => $loopMode,
            'total_duration_ms' => $total,
        ], $this->user);

        return $record;
    }

    /**
     * Обновляет кадры/режим анимации.
     *
     * @param list<array{codepoint:string,duration_ms:int}>|null $frames
     * @return list<string>
     */
    public function updateAnimation(string $id, ?array $frames, ?string $loopMode): array
    {
        $row = $this->find($id);
        if ($row === null || ($row['type'] ?? '') !== 'animation') {
            return ['Анимация не найдена.'];
        }
        $errors = [];
        if ($frames !== null) {
            $errors = array_merge($errors, $this->validateFrames($frames));
        }
        if ($loopMode !== null) {
            $errors = array_merge($errors, $this->validateLoopMode($loopMode));
        }
        if ($errors !== []) {
            return $errors;
        }
        $changed = [];
        $this->store->mutate(self::BANK_FILE, static function (mixed &$rows) use ($id, $frames, $loopMode, &$changed): bool {
            $rows = is_array($rows) ? $rows : [];
            foreach ($rows as &$r) {
                if (($r['id'] ?? '') === $id) {
                    if ($frames !== null) {
                        $r['frames'] = array_values(array_map(static fn(array $f): array => [
                            'codepoint' => (string)$f['codepoint'],
                            'duration_ms' => (int)$f['duration_ms'],
                        ], $frames));
                        $changed[] = 'frames';
                    }
                    if ($loopMode !== null) {
                        $r['loop_mode'] = $loopMode;
                        $changed[] = 'loop_mode';
                    }
                    $r['updated_at'] = gmdate('Y-m-d\TH:i:s\Z');
                }
            }
            unset($r);
            return true;
        });
        $this->audit->log('emoji_bank.animation_update', $id, ['changed' => $changed], $this->user);
        return [];
    }

    /**
     * Удаление с проверкой использования (ТЗ §6.4, §8.4).
     *
     * @return list<string> ошибки (пусто — удалено)
     */
    public function delete(string $id): array
    {
        $row = $this->find($id);
        if ($row === null) {
            return ['Эмодзи не найден.'];
        }
        $usage = $this->usage($id);
        if ($usage !== []) {
            $this->audit->log('emoji_bank.delete_blocked', $id, $usage, $this->user);
            $msgs = [];
            if (!empty($usage['used_in_games'])) {
                $msgs[] = 'Используется в легендах игр: ' . implode(', ', $usage['used_in_games']) . '.';
            }
            if (!empty($usage['used_in_levels'])) {
                $msgs[] = 'Используется в уровнях: ' . implode(', ', $usage['used_in_levels']) . '.';
            }
            if (!empty($usage['used_in_genres'])) {
                $msgs[] = 'Используется в жанрах: ' . implode(', ', $usage['used_in_genres']) . '.';
            }
            if (!empty($usage['used_in_animations'])) {
                $msgs[] = 'Используется в анимациях: ' . implode(', ', $usage['used_in_animations']) . '.';
            }
            return $msgs !== [] ? $msgs : ['Эмодзи используется.'];
        }

        $this->store->mutate(self::BANK_FILE, static function (mixed &$rows) use ($id): bool {
            $rows = is_array($rows) ? $rows : [];
            $rows = array_values(array_filter($rows, static fn(array $r): bool => ($r['id'] ?? '') !== $id));
            return true;
        });
        // Файл удаляем после JSON; кодпоинт НЕ переиспользуется — счётчик не трогаем.
        if (is_string($row['image_path'] ?? null)) {
            $abs = $this->mediaRoot . '/' . preg_replace('#^media/#', '', $row['image_path']);
            @unlink($abs);
        }
        $this->audit->log('emoji_bank.delete', $id, [], $this->user);
        return [];
    }

    /**
     * Проверка использования кодпоинта (ТЗ §8.4).
     *
     * @return array<string, list<string>>
     */
    public function usage(string $id): array
    {
        $row = $this->find($id);
        if ($row === null) {
            return [];
        }
        $cp = (string)$row['codepoint'];
        $used = [];

        // 1-2. Игры: legend[codepoint] и levels[].grid.
        $gamesDir = $this->store->getRootDir() . '/games';
        foreach (glob($gamesDir . '/game-*.json') ?: [] as $file) {
            $game = json_decode((string)file_get_contents($file), true);
            if (!is_array($game)) {
                continue;
            }
            $gid = basename($file, '.json');
            $legend = $game['legend'] ?? [];
            if (is_array($legend) && array_key_exists($cp, $legend)) {
                $used['used_in_games'][] = $gid;
            }
            foreach ($game['levels'] ?? [] as $level) {
                $grid = (string)($level['grid'] ?? '');
                if ($grid !== '' && mb_strpos($grid, $cp) !== false) {
                    $used['used_in_levels'][] = $gid . '/' . (string)($level['id'] ?? '?');
                }
            }
        }

        // 3. Жанры: default_legend[codepoint].
        /** @var list<array<string,mixed>> $genres */
        $genres = $this->store->read('genres.json', []);
        foreach ($genres as $g) {
            $dl = $g['default_legend'] ?? [];
            if (is_array($dl) && array_key_exists($cp, $dl)) {
                $used['used_in_genres'][] = (string)($g['id'] ?? '?');
            }
        }

        // 4. Анимации: frames[].codepoint (для static).
        if (($row['type'] ?? '') === 'static') {
            foreach ($this->all() as $r2) {
                if (($r2['type'] ?? '') === 'animation') {
                    foreach ($r2['frames'] ?? [] as $f) {
                        if (($f['codepoint'] ?? '') === $cp) {
                            $used['used_in_animations'][] = (string)$r2['id'];
                            break;
                        }
                    }
                }
            }
        }

        return array_map(static fn(array $l): array => array_values(array_unique($l)), $used);
    }

    /**
     * Публичная карта для /api/emoji-bank.json (ТЗ §7.1): только codepoint -> {type,url/frames}.
     *
     * @return array<string,array<string,mixed>>
     */
    public function publicMap(): array
    {
        $byCp = [];
        foreach ($this->all() as $r) {
            $byCp[(string)$r['codepoint']] = $r;
        }
        $out = [];
        foreach ($byCp as $cp => $r) {
            if (($r['type'] ?? '') === 'static') {
                $out[$cp] = ['type' => 'static', 'url' => '/' . ltrim((string)$r['image_path'], '/')];
            } elseif (($r['type'] ?? '') === 'animation') {
                $frames = [];
                $total = 0;
                foreach ($r['frames'] ?? [] as $f) {
                    $frameRow = $byCp[(string)($f['codepoint'] ?? '')] ?? null;
                    if ($frameRow === null || ($frameRow['type'] ?? '') !== 'static') {
                        continue; // повреждённая ссылка — не отдаём кадр
                    }
                    $dur = (int)($f['duration_ms'] ?? 0);
                    $frames[] = ['url' => '/' . ltrim((string)$frameRow['image_path'], '/'), 'duration_ms' => $dur];
                    $total += $dur;
                }
                if (count($frames) < 2) {
                    continue;
                }
                $out[$cp] = [
                    'type' => 'animation',
                    'loop_mode' => $r['loop_mode'] ?? 'loop',
                    'frames' => $frames,
                    'total_duration_ms' => $total,
                ];
            }
        }
        return $out;
    }

    /**
     * Экспорт ZIP (ТЗ §6.5): emoji-bank.json, emoji-bank-counter.json, files/{id}.{png|svg}.
     */
    public function exportZip(): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'emoji-export-') . '.zip';
        $zip = new \ZipArchive();
        if ($zip->open($tmp, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Не удалось создать ZIP');
        }
        $zip->addFromString(self::BANK_FILE, (string)json_encode($this->all(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        $zip->addFromString(self::COUNTER_FILE, (string)json_encode(['next' => $this->counterNext()]));
        foreach ($this->all() as $r) {
            if (($r['type'] ?? '') !== 'static' || !is_string($r['image_path'] ?? null)) {
                continue;
            }
            $abs = $this->mediaRoot . '/' . preg_replace('#^media/#', '', $r['image_path']);
            $bytes = is_file($abs) ? file_get_contents($abs) : false;
            if ($bytes !== false) {
                $zip->addFromString('files/' . basename($r['image_path']), $bytes);
            }
        }
        $zip->close();
        return $tmp;
    }

    /**
     * Импорт ZIP, двухпроходный, атомарный (ТЗ §6.5, §8.5).
     *
     * @return array{added: int}
     * @throws UploadException с перечнем причин; при ошибке ничего не записано
     */
    public function importZip(string $zipPath): array
    {
        $errors = [];
        if (!is_file($zipPath)) {
            throw new UploadException('Файл не найден');
        }
        if (filesize($zipPath) > 20 * 1024 * 1024) {
            throw new UploadException('ZIP больше 20 MB');
        }
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($zipPath);
        if (!in_array($mime, ['application/zip', 'application/x-zip-compressed'], true)) {
            throw new UploadException('Ожидается ZIP-архив');
        }

        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new UploadException('Не удалось открыть ZIP');
        }

        // Защита от zip-bomb: суммарный распакованный размер <= 100 MB.
        $uncompressed = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $st = $zip->statIndex($i);
            if ($st === false) {
                continue;
            }
            $uncompressed += (int)$st['size'];
            if ($uncompressed > 100 * 1024 * 1024) {
                $zip->close();
                throw new UploadException('Распакованный размер больше 100 MB');
            }
        }

        $bankJson = $zip->getFromName(self::BANK_FILE);
        if ($bankJson === false) {
            $zip->close();
            throw new UploadException('В архиве нет emoji-bank.json');
        }
        $rows = json_decode($bankJson, true);
        if (!is_array($rows) || !array_is_list($rows)) {
            $zip->close();
            throw new UploadException('emoji-bank.json повреждён или не массив');
        }
        if (count($rows) > self::PUA_LIMIT) {
            $zip->close();
            throw new UploadException('Больше 6400 записей');
        }

        // Читаем файлы архива: files/{id}.{png|svg}; любой другой путь — zip-slip отказ.
        $files = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $st = $zip->statIndex($i);
            if ($st === false) {
                continue;
            }
            $name = (string)$st['name'];
            if ($name === '' || str_ends_with($name, '/')) {
                continue;
            }
            if (in_array($name, [self::BANK_FILE, self::COUNTER_FILE], true)) {
                continue;
            }
            if (!preg_match('#^files/(e\d{3,})\.(png|svg)$#', $name, $m)) {
                $zip->close();
                throw new UploadException('Zip-slip: недопустимый путь в архиве: ' . $name);
            }
            $data = $zip->getFromIndex($i);
            if (is_string($data)) {
                $files[$m[1] . '.' . $m[2]] = $data;
            }
        }
        $zip->close();

        // --- Проход 1: валидация статики и конфликтов ---
        $existing = $this->all();
        $existingIds = array_column($existing, 'id');
        $existingCps = array_column($existing, 'codepoint');
        $seenCps = [];
        $animations = [];
        $reencoder = new RasterReencoder();
        $sanitizer = new GeometrySvgSanitizer();
        $prepared = []; // id => ['bytes' => string|null, 'record' => array]

        foreach ($rows as $r) {
            $id = (string)($r['id'] ?? '');
            $cp = (string)($r['codepoint'] ?? '');
            if (!Id::isValid('emoji', $id)) {
                $errors[] = "Невалидный id: {$id}";
                continue;
            }
            if (!preg_match('#^U\+(E[0-9A-F]{3}|F[0-7][0-9A-F]{2})$#', $cp)) {
                $errors[] = "Кодпоинт вне PUA-диапазона: {$cp}";
                continue;
            }
            if (isset($prepared[$id]) || in_array($id, $existingIds, true)) {
                $errors[] = "Конфликт id: {$id}";
                continue;
            }
            if (isset($seenCps[$cp]) || in_array($cp, $existingCps, true)) {
                $errors[] = "Конфликт codepoint: {$cp}";
                continue;
            }
            $seenCps[$cp] = true;

            $metaErr = $this->validateMeta((string)($r['name'] ?? ''), (string)($r['category'] ?? ''));
            if ($metaErr !== []) {
                $errors[] = "{$id}: " . implode(' ', $metaErr);
                continue;
            }

            $type = (string)($r['type'] ?? '');
            if ($type === 'static') {
                $expectedFile = null;
                if (preg_match('#^media/emoji-bank/' . preg_quote($id, '#') . '\.(png|svg)$#', (string)($r['image_path'] ?? ''), $m)) {
                    $expectedFile = $id . '.' . $m[1];
                }
                if ($expectedFile === null || !isset($files[$expectedFile])) {
                    $errors[] = "{$id}: файл не найден в архиве или image_path неверен";
                    continue;
                }
                $bytes = $files[$expectedFile];
                $ext = $m[1];
                $tmp = tempnam(sys_get_temp_dir(), 'emoji-import-');
                if ($tmp === false) {
                    $errors[] = "{$id}: ошибка временного файла";
                    continue;
                }
                file_put_contents($tmp, $bytes);
                try {
                    if ($ext === 'png') {
                        $rec = $reencoder->reencodeToPng($tmp); // MIME+размеры+ре-энкод
                        $prepared[$id] = [
                            'bytes' => $rec['png'],
                            'record' => $this->makeImportStaticRecord($r, $id, $cp, 'png', $rec['width'], $rec['height'], null),
                        ];
                    } else {
                        $san = $sanitizer->sanitize($bytes); // повторная санитизация (§7.4)
                        $prepared[$id] = [
                            'bytes' => $san->xml,
                            'record' => $this->makeImportStaticRecord($r, $id, $cp, 'svg', $san->sourceWidth ?? 64, $san->sourceHeight ?? 64, $san->hash),
                        ];
                    }
                } catch (UploadException | SvgSanitizeException $e) {
                    unset($seenCps[$cp]);
                    $errors[] = "{$id}: " . $e->getMessage();
                } finally {
                    @unlink($tmp);
                }
            } elseif ($type === 'animation') {
                $animations[] = $r;
            } else {
                unset($seenCps[$cp]);
                $errors[] = "{$id}: неизвестный type {$type}";
            }
        }

        // --- Проход 2: анимации (кадры должны существовать после шага 1) ---
        $staticCps = $existingCps; // статичные кодпоинты существующего банка
        foreach ($existing as $r) {
            if (($r['type'] ?? '') !== 'static') {
                $staticCps = array_diff($staticCps, [(string)$r['codepoint']]);
            }
        }
        $newStaticCps = [];
        foreach ($prepared as $p) {
            if ($p['bytes'] !== null) {
                $newStaticCps[] = (string)$p['record']['codepoint'];
            }
        }
        $allowedFrameCps = array_values(array_merge($staticCps, $newStaticCps));
        foreach ($animations as $r) {
            $id = (string)$r['id'];
            $cp = (string)($r['codepoint'] ?? '');
            $frames = $r['frames'] ?? [];
            $fErr = $this->validateFrames($frames, $allowedFrameCps);
            $fErr = array_merge($fErr, $this->validateLoopMode((string)($r['loop_mode'] ?? '')));
            if ($fErr !== []) {
                unset($seenCps[$cp]);
                $errors[] = "{$id}: " . implode(' ', $fErr);
                continue;
            }
            $prepared[$id] = ['bytes' => null, 'record' => $this->makeImportAnimationRecord($r)];
        }

        if ($errors !== []) {
            throw new UploadException(implode(' ', $errors)); // ничего не записано
        }
        if ($prepared === []) {
            return ['added' => 0];
        }

        // --- Запись: файлы, затем JSON, затем счётчик. При ошибке — откат записанных файлов. ---
        $writtenFiles = [];
        $newRecords = [];
        try {
            foreach ($prepared as $id => $p) {
                $record = $p['record'];
                if ($p['bytes'] !== null) {
                    $abs = $this->mediaRoot . '/' . self::MEDIA_DIR . '/' . basename((string)$record['image_path']);
                    $dir = dirname($abs);
                    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
                        throw new UploadException('Не удалось создать каталог медиа');
                    }
                    if (file_put_contents($abs, $p['bytes']) === false) {
                        throw new UploadException('Не удалось записать файл ' . $id);
                    }
                    $writtenFiles[] = $abs;
                }
                $newRecords[] = $record;
            }
            $maxIdNum = $this->counterNext();
            $this->store->mutate(self::BANK_FILE, static function (mixed &$rows) use ($newRecords, &$maxIdNum): bool {
                $rows = is_array($rows) ? $rows : [];
                foreach ($newRecords as $rec) {
                    $rows[] = $rec;
                    $maxIdNum = max($maxIdNum, (int)substr((string)$rec['id'], 1));
                }
                return true;
            });
            // Счётчик импорта: next = max(id_num всех записей) (ТЗ §6.5).
            $this->store->write(self::COUNTER_FILE, ['next' => $maxIdNum]);
        } catch (\Throwable $e) {
            foreach ($writtenFiles as $f) {
                @unlink($f);
            }
            throw $e instanceof UploadException ? $e : new UploadException('Ошибка импорта: ' . $e->getMessage());
        }

        $this->audit->log('emoji_bank.import', '', ['added' => count($newRecords)], $this->user);
        return ['added' => count($newRecords)];
    }

    /** @param array<string,mixed> $r */
    private function makeImportStaticRecord(array $r, string $id, string $cp, string $format, int $w, int $h, ?string $hash): array
    {
        $srcFmt = (string)($r['source_format'] ?? $format);
        if ($format === 'png' && $srcFmt !== 'gif') {
            $srcFmt = 'png';
        }
        if ($format === 'svg') {
            $srcFmt = 'svg';
        }
        $now = gmdate('Y-m-d\TH:i:s\Z');
        return [
            'id' => $id,
            'codepoint' => $cp,
            'name' => (string)$r['name'],
            'category' => (string)$r['category'],
            'type' => 'static',
            'source_format' => $srcFmt,
            'stored_format' => $format,
            'image_path' => 'media/' . self::MEDIA_DIR . '/' . $id . '.' . $format,
            'source_size' => ['width' => $w, 'height' => $h],
            'sanitized_hash' => $hash,
            'frames' => null,
            'loop_mode' => null,
            'created_at' => (string)($r['created_at'] ?? $now),
            'updated_at' => $now,
        ];
    }

    /** @param array<string,mixed> $r */
    private function makeImportAnimationRecord(array $r): array
    {
        $now = gmdate('Y-m-d\TH:i:s\Z');
        return [
            'id' => (string)$r['id'],
            'codepoint' => (string)$r['codepoint'],
            'name' => (string)$r['name'],
            'category' => (string)$r['category'],
            'type' => 'animation',
            'source_format' => 'animation',
            'stored_format' => null,
            'image_path' => null,
            'source_size' => null,
            'sanitized_hash' => null,
            'frames' => array_values(array_map(static fn(array $f): array => [
                'codepoint' => (string)$f['codepoint'],
                'duration_ms' => (int)$f['duration_ms'],
            ], $r['frames'] ?? [])),
            'loop_mode' => (string)$r['loop_mode'],
            'created_at' => (string)($r['created_at'] ?? $now),
            'updated_at' => $now,
        ];
    }

    /** @return list<string> */
    private function validateMeta(string $name, string $category): array
    {
        $errors = [];
        $len = mb_strlen(trim($name));
        if ($len < 2 || $len > 80) {
            $errors[] = 'Название: 2–80 символов.';
        }
        if (!in_array($category, self::CATEGORIES, true)) {
            $errors[] = 'Категория: одна из ' . implode(', ', self::CATEGORIES) . '.';
        }
        return $errors;
    }

    /**
     * @param mixed $frames
     * @param list<string>|null $allowedStaticCps если задан — проверка по этому списку (для импорта)
     * @return list<string>
     */
    private function validateFrames(mixed $frames, ?array $allowedStaticCps = null): array
    {
        $errors = [];
        if (!is_array($frames) || count($frames) < 2 || count($frames) > 20) {
            $errors[] = 'Кадры: от 2 до 20.';
            return $errors;
        }
        $bankByCp = null;
        if ($allowedStaticCps === null) {
            $bankByCp = [];
            foreach ($this->all() as $r) {
                $bankByCp[(string)$r['codepoint']] = (string)($r['type'] ?? '');
            }
        }
        $total = 0;
        foreach ($frames as $f) {
            $cp = (string)($f['codepoint'] ?? '');
            $dur = $f['duration_ms'] ?? null;
            if ($allowedStaticCps !== null) {
                if (!in_array($cp, $allowedStaticCps, true)) {
                    $errors[] = "Кадр {$cp}: не найден статичный эмодзи.";
                }
            } else {
                $type = $bankByCp[$cp] ?? null;
                if ($type === null) {
                    $errors[] = "Кадр {$cp}: эмодзи не существует.";
                } elseif ($type !== 'static') {
                    $errors[] = 'Кадр должен быть статичным эмодзи';
                }
            }
            if (!is_numeric($dur) || (int)$dur < 50 || (int)$dur > 2000) {
                $errors[] = 'Длительность кадра: 50–2000 мс.';
            } else {
                $total += (int)$dur;
            }
        }
        if ($total > 10000) {
            $errors[] = 'Суммарная длительность больше 10 000 мс.';
        }
        return array_values(array_unique($errors));
    }

    /** @return list<string> */
    private function validateLoopMode(string $loopMode): array
    {
        return in_array($loopMode, self::LOOP_MODES, true)
            ? []
            : ['Режим цикла: loop, once или ping_pong.'];
    }

    private function bumpCounterTo(int $idNum): void
    {
        // Счётчик монотонный: next = номер последнего назначенного id.
        $this->store->mutate(self::COUNTER_FILE, static function (mixed &$c) use ($idNum): bool {
            $next = (int)(is_array($c) ? ($c['next'] ?? 0) : 0);
            if ($idNum <= $next) {
                return false; // уже не меньше — не пишем
            }
            $c = ['next' => $idNum];
            return true;
        });
    }

    /**
     * Откат резервации счётчика при сбое записи файла/JSON (ТЗ §3.6).
     * Уменьшает next только если он ровно равен откатываемому номеру —
     * параллельная успешная загрузка не должна быть перезаписана.
     */
    private function rollbackCounter(int $idNum): void
    {
        $this->store->mutate(self::COUNTER_FILE, static function (mixed &$c) use ($idNum): bool {
            $next = (int)(is_array($c) ? ($c['next'] ?? 0) : 0);
            if ($next !== $idNum) {
                return false; // не сохранять: счётчик ушёл дальше
            }
            $c = ['next' => $idNum - 1];
            return true;
        });
    }
}
