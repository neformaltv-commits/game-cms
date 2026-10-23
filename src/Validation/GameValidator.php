<?php

declare(strict_types=1);

namespace App\Validation;

use App\GenreService;
use App\CategoryService;
use App\Storage\FileStore;

/**
 * Валидация карточки игры (ТЗ блок 7 §2.1, §8.1).
 * Возвращает list<string> ошибок; пусто — данные валидны.
 */
final class GameValidator
{
    public function __construct(
        private readonly GenreService $genres,
        private readonly CategoryService $categories,
        private readonly LegendValidator $legendValidator,
        private readonly FileStore $store,
        private readonly string $mediaDir,
    ) {
    }

    /**
     * @param array<string,mixed> $game полный JSON игры
     * @param bool $forPublish требует ли проверок публикации (cover, уровни)
     * @return list<string>
     */
    public function validate(array $game, bool $forPublish): array
    {
        $errors = [];

        $id = (string)($game['id'] ?? '');
        if (preg_match('/^game-\d{3,}$/', $id) !== 1) {
            $errors[] = 'id должен соответствовать ^game-\d{3,}$.';
        }

        $slug = (string)($game['slug'] ?? '');
        if (preg_match('/^[a-z0-9][a-z0-9-]{1,78}[a-z0-9]$/', $slug) !== 1) {
            $errors[] = 'slug должен соответствовать ^[a-z0-9][a-z0-9-]{1,78}[a-z0-9]$.';
        } elseif ($this->slugTaken($slug, $id)) {
            $errors[] = sprintf('slug «%s» уже занят другой игрой.', $slug);
        }

        $title = trim((string)($game['title'] ?? ''));
        $tl = mb_strlen($title);
        if ($tl < 1 || $tl > 120) {
            $errors[] = 'title: 1–120 символов.';
        }

        $genreId = (string)($game['genre_id'] ?? '');
        $genre = $this->genres->find($genreId);
        if ($genre === null) {
            $errors[] = sprintf('Жанр «%s» не существует.', $genreId);
        }

        $desc = trim((string)($game['description'] ?? ''));
        $dl = mb_strlen($desc);
        if ($dl < 20 || $dl > 1000) {
            $errors[] = 'description: 20–1000 символов.';
        }

        $status = (string)($game['status'] ?? '');
        if (!in_array($status, ['draft', 'published'], true)) {
            $errors[] = 'status ∈ {draft, published}.';
        }

        // category_ids: существующие, без дубликатов
        $catIds = (array)($game['category_ids'] ?? []);
        $seen = [];
        foreach ($catIds as $cid) {
            $cid = (string)$cid;
            if (isset($seen[$cid])) {
                $errors[] = sprintf('category_ids: дубликат «%s».', $cid);
            }
            $seen[$cid] = true;
            if ($this->categories->find($cid) === null) {
                $errors[] = sprintf('Категория «%s» не существует.', $cid);
            }
        }

        // meta
        $meta = (array)($game['meta'] ?? []);
        if (mb_strlen((string)($meta['seo_title'] ?? '')) > 120) {
            $errors[] = 'meta.seo_title: максимум 120 символов.';
        }
        if (mb_strlen((string)($meta['seo_description'] ?? '')) > 300) {
            $errors[] = 'meta.seo_description: максимум 300 символов.';
        }

        // cover: путь генерируется сервером media/{game-id}/cover.webp
        $cover = $game['cover'] ?? null;
        $coverPath = is_array($cover) ? (string)($cover['path'] ?? '') : '';
        $coverOk = $coverPath === 'media/' . $id . '/cover.webp'
            && is_file($this->mediaDir . '/' . $id . '/cover.webp');

        // Легенда — проверяется эффективная легенда (жанр + игра)
        $engine = is_array($genre) ? (string)($genre['engine'] ?? '') : '';
        $legend = (array)($game['legend'] ?? []);
        if ($engine !== '') {
            $effective = array_merge((array)(is_array($genre) ? $genre['default_legend'] ?? [] : []), $legend);
            foreach ($this->legendValidator->validate($effective, $engine) as $e) {
                $errors[] = 'Легенда: ' . $e;
            }
        }

        // Публикация
        if ($forPublish) {
            if (!$coverOk) {
                $errors[] = 'Публикация невозможна: обложка cover.webp не загружена.';
            }
            if (count((array)($game['levels'] ?? [])) < 1) {
                $errors[] = 'Публикация невозможна: нужен хотя бы один уровень.';
            }
            $required = self::requiredActions($engine);
            $controls = (array)($game['controls'] ?? []);
            foreach ($required as $act) {
                if ((array)($controls[$act] ?? []) === []) {
                    $errors[] = sprintf('Публикация невозможна: действие «%s» не назначено ни на одну клавишу.', $act);
                }
            }
        }

        return $errors;
    }

    /** Slug занят другой игрой? (перебор games/*.json — типовое flat-file решение) */
    public function slugTaken(string $slug, string $ownId): bool
    {
        foreach ($this->gamesDir() as $file) {
            $g = json_decode((string)@file_get_contents($file), true);
            if (is_array($g) && ($g['id'] ?? '') !== $ownId && ($g['slug'] ?? '') === $slug) {
                return true;
            }
        }
        return false;
    }

    /**
     * Обязательные действия управления по движку (используются также блоком 9).
     * @return list<string>
     */
    public static function requiredActions(string $engine): array
    {
        $base = ['left', 'right', 'pause', 'start'];
        return match ($engine) {
            'static-screen' => [...$base, 'up', 'down'],
            'horizontal-scroll' => [...$base, 'up', 'down', 'jump'],
            'vertical-scroll' => [...$base, 'fire'],
            default => $base,
        };
    }

    /** @return list<string> пути games/*.json внутри data-хранилища */
    private function gamesDir(): array
    {
        $dir = $this->store->getRootDir() . '/games';
        return is_dir($dir) ? (glob($dir . '/*.json') ?: []) : [];
    }
}
