<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Генерация slug (ТЗ блок 3, §5.3): транслитерация кириллицы, lowercase,
 * замена всего кроме [a-z0-9-] на «-», схлопывание повторных «-», trim.
 */
final class Slug
{
    private const TRANSLIT = [
        'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd', 'е' => 'e',
        'ё' => 'e', 'ж' => 'zh', 'з' => 'z', 'и' => 'i', 'й' => 'y', 'к' => 'k',
        'л' => 'l', 'м' => 'm', 'н' => 'n', 'о' => 'o', 'п' => 'p', 'р' => 'r',
        'с' => 's', 'т' => 't', 'у' => 'u', 'ф' => 'f', 'х' => 'h', 'ц' => 'ts',
        'ч' => 'ch', 'ш' => 'sh', 'щ' => 'sch', 'ъ' => '', 'ы' => 'y', 'ь' => '',
        'э' => 'e', 'ю' => 'yu', 'я' => 'ya',
    ];

    /**
     * @return string|null null, если slug получить не удалось.
     */
    public static function generate(string $title): ?string
    {
        $s = mb_strtolower(trim($title), 'UTF-8');

        $out = '';
        $len = mb_strlen($s, 'UTF-8');
        for ($i = 0; $i < $len; $i++) {
            $ch = mb_substr($s, $i, 1, 'UTF-8');
            if (isset(self::TRANSLIT[$ch])) {
                $out .= self::TRANSLIT[$ch];
            } else {
                $out .= $ch;
            }
        }

        // Всё, что не [a-z0-9], заменяем на дефис.
        $out = preg_replace('/[^a-z0-9]+/u', '-', $out) ?? '';
        $out = preg_replace('/-+/', '-', $out) ?? '';
        $out = trim($out, '-');

        if ($out === '' || !preg_match('/^[a-z0-9][a-z0-9-]{0,78}[a-z0-9]$|^[a-z0-9]$/', $out)) {
            return null;
        }
        // Ограничение длины slug жанра/категории: 2..40 — срезаем до 40 по границам слов.
        if (strlen($out) > 40) {
            $cut = substr($out, 0, 40);
            $lastDash = strrpos($cut, '-');
            if ($lastDash !== false && $lastDash > 0) {
                $cut = substr($cut, 0, $lastDash);
            }
            $out = rtrim($cut, '-');
        }
        return $out === '' ? null : $out;
    }

    /**
     * Разрешение коллизий: суффиксы -2, -3, … (ТЗ 5.3).
     *
     * @param callable(string):bool $exists
     */
    public static function unique(string $slug, callable $exists): string
    {
        if (!$exists($slug)) {
            return $slug;
        }
        for ($i = 2; $i < 1000; $i++) {
            $candidate = $slug . '-' . $i;
            if (!$exists($candidate)) {
                return $candidate;
            }
        }
        throw new \RuntimeException("Не удалось подобрать уникальный slug для: {$slug}");
    }
}
