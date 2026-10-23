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
        // Для PNG допускаем ancillary-чанки после IHDR (tEXt/iTXt/zTXt, pHYs, sRGB, gAMA, cHRM, tRNS):
        // они законны по спецификации и будут отброшены ре-энкодом; размеры берём из IHDR.
        $info = @getimagesize($path);
        if ($info === false || empty($info[0]) || empty($info[1])) {
            if ($mime === 'image/png') {
                $info = self::pngHeaderInfo($path);
            }
            if ($info === false || empty($info[0]) || empty($info[1])) {
                throw new UploadException('Не удалось прочитать изображение');
            }
        }
        $width = (int)$info[0];
        $height = (int)$info[1];
        if ($width < self::MIN_DIM || $height < self::MIN_DIM
            || $width > self::MAX_DIM || $height > self::MAX_DIM) {
            throw new UploadException("Размеры {$width}x{$height}: допустимо 8×8 … 256×256");
        }

        // Для PNG с ancillary-чанками (tEXt и т.п.) после IHDR libpng может вернуть false;
        // в этом случае удаляем ancillary-чанки (ре-энкод их всё равно отбрасывает) и читаем заново.
        $img = false;
        if ($mime === 'image/gif') {
            $img = @imagecreatefromgif($path); // первый кадр
        } else {
            $img = @imagecreatefrompng($path);
            if ($img === false) {
                $cleaned = self::pngStripAncillary($path);
                if ($cleaned !== null) {
                    $img = @imagecreatefromstring($cleaned);
                }
            }
        }
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

    /**
     * Размеры PNG из заголовка IHDR (без декодирования чанков).
     * Возвращает массив в формате getimagesize() или false.
     */
    private static function pngHeaderInfo(string $path): array|false
    {
        $hdr = @file_get_contents($path, false, null, 0, 24);
        if ($hdr === false || strlen($hdr) < 24) {
            return false;
        }
        if (substr($hdr, 0, 8) !== "\x89PNG\r\n\x1a\n" || substr($hdr, 12, 4) !== 'IHDR') {
            return false;
        }
        $dims = unpack('Nw/Nh', substr($hdr, 16, 8));
        if ($dims === false) {
            return false;
        }
        return [
            0 => $dims['w'],
            1 => $dims['h'],
            'bits' => 8,
            'channels' => 3,
            'mime' => 'image/png',
        ];
    }

    /**
     * Удаляет из PNG все ancillary-чанки, оставляя сигнатуру, IHDR, IDAT (+tRNS), IEND.
     * Используется как fallback: GD/libpng отказывает из-за метаданных, которые
     * ре-энкод всё равно отбрасывает. Возвращает байты или null, если формат не распознан.
     */
    private static function pngStripAncillary(string $path): ?string
    {
        $data = @file_get_contents($path);
        if ($data === false || strlen($data) < 8 || substr($data, 0, 8) !== "\x89PNG\r\n\x1a\n") {
            return null;
        }
        $keep = ['IHDR', 'PLTE', 'IDAT', 'IEND', 'tRNS'];
        $out = substr($data, 0, 8);
        $pos = 8;
        $len = strlen($data);
        while ($pos + 8 <= $len) {
            $chunkLenBin = substr($data, $pos, 4);
            $type = substr($data, $pos + 4, 4);
            $chunkLenArr = unpack('N', $chunkLenBin);
            if ($chunkLenArr === false) {
                return null;
            }
            $chunkLen = $chunkLenArr[1];
            if ($chunkLen > $len - $pos - 8) {
                return null; // обрезанный/битый файл
            }
            if (in_array($type, $keep, true)) {
                $out .= substr($data, $pos, 8 + $chunkLen + 4);
            }
            if ($type === 'IEND') {
                break;
            }
            $pos += 8 + $chunkLen + 4;
        }
        return $out;
    }
}
