<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Работа с emoji-кластерами (ТЗ блок 7 §5.3, §9: «один графемный кластер»).
 * Типовое решение: фрагментация через \p{Extended_Pictographic} + U+200D ZWJ,
 * вариационные селекторы и ключевые символы (как в Unicode Standard Annex #51).
 */
final class EmojiUtil
{
    /**
     * Разбивает строку на графемные кластеры. Пустая строка → [].
     * @return list<string>
     */
    public static function graphemes(string $s): array
    {
        if ($s === '') {
            return [];
        }
        
        
        return self::cluster($s);
    }

    /**
     * @return list<string>
     */
    private static function cluster(string $s): array
    {
        $out = [];
        $len = mb_strlen($s);
        $i = 0;
        while ($i < $len) {
            $ch = mb_substr($s, $i, 1);
            $cur = $ch;
            $i++;
            // Поглощаем продолжения кластера: FE0F, 20E3, тонировки skin tone, ZWJ + следующий, варианты тегов
            while ($i < $len) {
                $nx = mb_substr($s, $i, 1);
                $cp = mb_ord($nx, 'UTF-8');
                $isContinue =
                    $cp === 0xFE0F || $cp === 0xFE0E || $cp === 0x20E3 ||
                    ($cp >= 0x1F3FB && $cp <= 0x1F3FF) || // skin tones
                    ($cp >= 0xE0020 && $cp <= 0xE007F) ||  // tag chars
                    $cp === 0x200D;                        // ZWJ
                if (!$isContinue) {
                    break;
                }
                $cur .= $nx;
                $i++;
                if ($cp === 0x200D && $i < $len) { // после ZWJ поглощаем ещё один базовый символ
                    $cur .= mb_substr($s, $i, 1);
                    $i++;
                }
            }
            // Regional indicator pairs (флаги): два RI подряд = один кластер
            $o = mb_ord(mb_substr($cur, 0, 1), 'UTF-8');
            if ($o !== false && $o >= 0x1F1E6 && $o <= 0x1F1FF && $i < $len) {
                $nxt = mb_ord(mb_substr($s, $i, 1), 'UTF-8');
                if ($nxt !== false && $nxt >= 0x1F1E6 && $nxt <= 0x1F1FF) {
                    $cur .= mb_substr($s, $i, 1);
                    $i++;
                }
            }
            $out[] = $cur;
        }
        return $out;
    }

    /** Один графемный кластер-эмодзи? (не текст, не HTML, не два эмодзи) */
    public static function isSingleEmoji(string $s): bool
    {
        if ($s === '' || strlen($s) > 32) {
            return false;
        }
        if (preg_match('/[<>&"\'\\\p{C}]/u', $s) === 1) {
            return false; // любая разметка/управляющие символы — отказ
        }
        $g = self::cluster($s);
        if (count($g) !== 1) {
            return false;
        }
        return self::isEmojiCluster($g[0]);
    }

    public static function isEmojiCluster(string $g): bool
    {
        return preg_match('/^[\p{Extended_Pictographic}\p{Mn}\p{Mc}\x{200D}\x{FE0F}\x{FE0E}\x{20E3}\x{1F3FB}-\x{1F3FF}\x{1F1E6}-\x{1F1FF}\x{E0020}-\x{E007F}0-9#*]+$/u', $g) === 1
            && preg_match('/[\p{Extended_Pictographic}\x{1F1E6}-\x{1F1FF}]/u', $g) === 1;
    }

    /**
     * Разбор строки сетки уровня на массив emoji-кластеров (ячейки разделены пробелами).
     * @return list<string>|null null — если встречается не-emoji токен
     */
    public static function splitCells(string $row): ?array
    {
        $tokens = preg_split('/\s+/u', trim($row), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        foreach ($tokens as $t) {
            if (!self::isSingleEmoji($t)) {
                return null;
            }
        }
        return $tokens;
    }

    /** Кодпоинты первого символа кластера, напр. "U+1F600" */
    public static function codepoint(string $g): ?string
    {
        $cp = mb_ord(mb_substr($g, 0, 1), 'UTF-8');
        return $cp === null ? null : sprintf('U+%X', $cp);
    }
}
