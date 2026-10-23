<?php

declare(strict_types=1);

namespace App;

use App\Audit\Logger;
use App\Storage\FileStore;
use App\Validation\GameValidator;

/**
 * Карточка игры: CRUD + обложка (ТЗ блок 7).
 * Хранение: data/games/{game-id}.json — по одному файлу на игру.
 */
final class GameService
{
    private const DIR = 'games';

    public function __construct(
        private readonly FileStore $store,
        private readonly Logger $audit,
        private readonly GenreService $genres,
        private readonly GameValidator $validator,
        private readonly string $mediaDir,
    ) {
    }

    /** @return list<array<string,mixed>> отсортировано по updated_at desc */
    public function all(): array
    {
        $files = glob($this->store->getRootDir() . '/' . self::DIR . '/*.json') ?: [];
        $out = [];
        foreach ($files as $f) {
            $g = json_decode((string)@file_get_contents($f), true);
            if (is_array($g)) {
                $out[] = $g;
            }
        }
        usort($out, static fn ($a, $b) => strcmp((string)($b['updated_at'] ?? ''), (string)($a['updated_at'] ?? '')));
        return $out;
    }

    public function find(string $id): ?array
    {
        if (preg_match('/^game-\d{3,}$/', $id) !== 1) {
            return null;
        }
        $g = $this->store->read(self::DIR . '/' . $id . '.json', null);
        return is_array($g) ? $g : null;
    }

    /**
     * Следующий свободный id: game-001, game-002… (максимум в существующих + 1).
     */
    public function nextId(): string
    {
        $max = 0;
        foreach ($this->all() as $g) {
            if (preg_match('/^game-(\d+)$/', (string)($g['id'] ?? ''), $m)) {
                $max = max($max, (int)$m[1]);
            }
        }
        $id = sprintf('game-%03d', $max + 1);
        while ($this->find($id) !== null) {
            $max++;
            $id = sprintf('game-%03d', $max + 1);
        }
        return $id;
    }

    /**
     * Создание игры: наследует controls/sounds из жанра, engine_overrides={}, legend={}, levels=[].
     * @param array{title:string,slug:string,genre_id:string,description?:string,category_ids?:list<string>} $input
     * @return array{ok:bool,errors:list<string>,game:?array<string,mixed>}
     */
    public function create(array $input): array
    {
        $genre = $this->genres->find((string)($input['genre_id'] ?? ''));
        if ($genre === null) {
            return ['ok' => false, 'errors' => ['Жанр не выбран или не существует.'], 'game' => null];
        }
        $id = $this->nextId();
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $game = [
            'id' => $id,
            'slug' => (string)($input['slug'] ?? ''),
            'title' => (string)($input['title'] ?? ''),
            'genre_id' => (string)$genre['id'],
            'description' => (string)($input['description'] ?? ''),
            'cover' => null,
            'category_ids' => array_values(array_unique(array_map('strval', (array)($input['category_ids'] ?? [])))),
            'status' => 'draft',
            'controls' => (array)($genre['engine_config']['default_controls'] ?? []),
            'sounds' => (array)($genre['engine_config']['default_sounds'] ?? []),
            'engine_overrides' => new \stdClass(),
            'legend' => new \stdClass(),
            'levels' => [],
            'meta' => ['seo_title' => '', 'seo_description' => ''],
            'created_at' => $now,
            'updated_at' => $now,
        ];
        $errors = $this->validator->validate($game, false);
        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors, 'game' => null];
        }
        $this->store->write(self::DIR . '/' . $id . '.json', $game);
        $this->audit->log('game.create', $id, ['title' => $game['title'], 'genre_id' => $game['genre_id']]);
        return ['ok' => true, 'errors' => [], 'game' => $game];
    }

    /**
     * Атомарное сохранение всей карточки. Поля, запрещённые к подмене из формы, уже исключены вызывающим.
     * @param array<string,mixed> $game
     * @return list<string> ошибки (пусто — сохранено)
     */
    public function save(array $game, bool $forPublish): array
    {
        $old = $this->find((string)($game['id'] ?? ''));
        $errors = $this->validator->validate($game, $forPublish);
        if ($errors !== []) {
            return $errors;
        }
        $game['updated_at'] = gmdate('Y-m-d\TH:i:s\Z');
        $this->store->write(self::DIR . '/' . $game['id'] . '.json', $game);

        // Аудит изменений
        if ($old !== null) {
            $changed = [];
            foreach (array_keys($game) as $k) {
                if (!in_array($k, ['updated_at'], true) && json_encode($old[$k] ?? null) !== json_encode($game[$k])) {
                    $changed[] = (string)$k;
                }
            }
            if (($old['status'] ?? '') !== $game['status']) {
                $this->audit->log('game.status_change', (string)$game['id'], ['old' => $old['status'] ?? '', 'new' => $game['status']]);
            }
            if ($changed !== []) {
                $this->audit->log('game.update', (string)$game['id'], ['changed' => $changed]);
            }
        }
        return [];
    }

    /**
     * Смена жанра после сохранения: controls/sounds перезаписываются дефолтами жанра,
     * engine_overrides очищается (ТЗ блок 7 §4.4).
     * @return list<string> ошибки
     */
    public function changeGenre(string $id, string $newGenreId): array
    {
        $game = $this->find($id);
        if ($game === null) {
            return ['Игра не найдена.'];
        }
        $genre = $this->genres->find($newGenreId);
        if ($genre === null) {
            return ['Жанр не существует.'];
        }
        $old = (string)$game['genre_id'];
        $game['genre_id'] = $newGenreId;
        $game['controls'] = (array)($genre['engine_config']['default_controls'] ?? []);
        $game['sounds'] = (array)($genre['engine_config']['default_sounds'] ?? []);
        $game['engine_overrides'] = new \stdClass();
        $game['updated_at'] = gmdate('Y-m-d\TH:i:s\Z');
        $errors = $this->validator->validate($game, false);
        if ($errors !== []) {
            return $errors;
        }
        $this->store->write(self::DIR . '/' . $id . '.json', $game);
        $this->audit->log('game.genre_change', $id, [
            'old' => $old, 'new' => $newGenreId,
            'controls_reset' => true, 'sounds_reset' => true, 'overrides_cleared' => true,
        ]);
        return [];
    }

    /**
     * Загрузка обложки: re-encode в WebP 640×480 letterbox (#000022), q=80.
     * Старая версия -> cover-prev.webp. Транзакция: сначала файл, затем JSON.
     * @param array{name:string,tmp_name:string,size:int,error:int} $file из $_FILES
     * @return list<string> ошибки
     */
    public function uploadCover(string $id, array $file): array
    {
        $game = $this->find($id);
        if ($game === null) {
            return ['Игра не найдена.'];
        }
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return ['Файл не загружен.'];
        }
        if ($file['size'] > 5 * 1024 * 1024) {
            return ['Обложка: максимум 5 MB.'];
        }
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = (string)$finfo->file($file['tmp_name']);
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true)) {
            return ['Обложка: допустимы JPEG, PNG, WebP, GIF.'];
        }
        $size = @getimagesize($file['tmp_name']);
        if ($size === false) {
            return ['Обложка: файл не является изображением.'];
        }
        [$w, $h] = $size;
        if ($w < 200 || $h < 150 || $w > 4000 || $h > 3000) {
            return [sprintf('Обложка: размеры %d×%d вне диапазона 200×150 … 4000×3000.', $w, $h)];
        }
        $src = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($file['tmp_name']),
            'image/png' => @imagecreatefrompng($file['tmp_name']),
            'image/webp' => @imagecreatefromwebp($file['tmp_name']),
            'image/gif' => @imagecreatefromgif($file['tmp_name']),
        };
        if ($src === false) {
            return ['Обложка: не удалось прочитать изображение.'];
        }

        // Letterbox 640×480, фон #000022
        $dstW = 640;
        $dstH = 480;
        $scale = min($dstW / $w, $dstH / $h);
        $newW = (int)round($w * $scale);
        $newH = (int)round($h * $scale);
        $canvas = imagecreatetruecolor($dstW, $dstH);
        imagefill($canvas, 0, 0, (int)hexdec('000022'));
        imagecopyresampled($canvas, $src, (int)(($dstW - $newW) / 2), (int)(($dstH - $newH) / 2), 0, 0, $newW, $newH, $w, $h);
        imagedestroy($src);

        $dir = $this->mediaDir . '/' . $id;
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            imagedestroy($canvas);
            return ['Не удалось создать каталог media.'];
        }
        $target = $dir . '/cover.webp';
        if (is_file($target)) {
            copy($target, $dir . '/cover-prev.webp'); // типовое решение: prev-копия перед перезаписью
        }
        $ok = imagewebp($canvas, $target, 80);
        imagedestroy($canvas);
        if (!$ok) {
            return ['Обложка: ошибка записи WebP.'];
        }

        $game['cover'] = [
            'path' => 'media/' . $id . '/cover.webp',
            'width' => 640,
            'height' => 480,
            'alt' => 'Обложка: ' . $game['title'],
            'uploaded_at' => gmdate('Y-m-d\TH:i:s\Z'),
        ];
        $game['updated_at'] = gmdate('Y-m-d\TH:i:s\Z');
        $this->store->write(self::DIR . '/' . $id . '.json', $game);
        $this->audit->log('game.cover_update', $id, ['bytes' => (int)filesize($target), 'mime' => $mime]);
        return [];
    }

    /**
     * Удаление игры: JSON + каталог media/{id}/ (ТЗ §4.6).
     */
    public function delete(string $id): array
    {
        $game = $this->find($id);
        if ($game === null) {
            return ['Игра не найдена.'];
        }
        $this->store->delete(self::DIR . '/' . $id . '.json');
        $dir = $this->mediaDir . '/' . $id;
        if (is_dir($dir)) {
            foreach (glob($dir . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($dir);
        }
        $this->audit->log('game.delete', $id, ['title' => (string)($game['title'] ?? '')]);
        return [];
    }

    /** Эффективная легенда = {...genre.default_legend, ...game.legend} (ТЗ §3.1) */
    public function effectiveLegend(array $game): array
    {
        $genre = $this->genres->find((string)($game['genre_id'] ?? ''));
        return array_merge(
            (array)($genre['default_legend'] ?? []),
            (array)($game['legend'] ?? [])
        );
    }

    /** Игры, использующие жанр (для ссылочной целостности блока 4) */
    public function countByGenre(string $genreId): int
    {
        $n = 0;
        foreach ($this->all() as $g) {
            if (($g['genre_id'] ?? '') === $genreId) {
                $n++;
            }
        }
        return $n;
    }
}
