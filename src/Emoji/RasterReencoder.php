<?php

declare(strict_types=1);

namespace App\Emoji;

/**
 * Ре-энкод растровых эмодзи PNG/GIF через GD (ТЗ блок 6 §3.3–3.4, §8.1).
 *
 * Правила:
 *  - MIME проверяется через finfo: только image/png / image/gif;
 *  - размер файла <= 200 KB;
 *  - getimagesize() ДО создания GD-ресурса, размеры 8x8 ... 256x256
 *    (защита от decompression bomb);
 *  - GIF -> imagecreatefromgif() возвращает первый кадр;
 *  - ре-энкод БЕЗ изменения размеров (только очистка метаданных/полиглотов);
 *  - прозрачность сохраняется (imagealphablending(false), imagesavealpha(true));
 *  - оригинал не сохраняется.
 */
final class RasterReencoder
{
    public const MAX_FILE_BYTES = 200 * 1024;
    public const MIN_DIM = 8;
    public const MAX_DIM = 256;

    /**
     * Валидирует файл и возвращает результат ре-энкода в PNG.
     *
     * @return array{png: string, width: int, height: int} байты PNG + размеры исходника
     * @throws UploadException при любом несоответствии требованиям
     */
    public function reencodeToPng(string $path): array
    {
        if (!is_file($path)) {
            throw new UploadException('Файл не найден');
        }
        $size = filesize($path);
        if ($size === false || $size > self::MAX_FILE_BYTES) {
            throw new UploadException('Файл больше 200 KB');
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($path);
        if ($mime !== 'image/png' && $mime !== 'image/gif') {
            throw new UploadException('Допустимы только PNG, GIF, SVG');
        }

        // getimagesize до создания ресурса GD (§8.1, защита от bomb).
        $info = @getimagesize($path);
        if ($info === false || empty($info[0]) || empty($info[1])) {
            throw new UploadException('Не удалось прочитать изображение');
        }
        $width = (int)$info[0];
        $height = (int)$info[1];
        if ($width < self::MIN_DIM || $height < self::MIN_DIM
            || $width > self::MAX_DIM || $height > self::MAX_DIM) {
            throw new UploadException("Размеры {$width}x{$height}: допустимо 8×8 … 256×256");
        }

        $img = match ($mime) {
            'image/png' => @imagecreatefrompng($path),
            'image/gif' => @imagecreatefromgif($path), // первый кадр
            default => false,
        };
        if ($img === false) {
            throw new UploadException('Изображение повреждено или не поддерживается');
        }

        try {
            // Прозрачность сохраняем, смешивание отключаем — чистый побайтовый ре-энкод.
            imagealphablending($img, false);
            imagesavealpha($img, true);

            $tmp = tempnam(sys_get_temp_dir(), 'emoji-renc-');
            if ($tmp === false) {
                throw new UploadException('Ошибка временного файла');
            }
            $ok = @imagepng($img, $tmp);
            $bytes = $ok ? file_get_contents($tmp) : false;
            @unlink($tmp);
            if (!$ok || $bytes === false) {
                throw new UploadException('Ошибка ре-энкода в PNG');
            }
        } finally {
            imagedestroy($img);
        }

        return ['png' => $bytes, 'width' => $width, 'height' => $height];
    }
}
