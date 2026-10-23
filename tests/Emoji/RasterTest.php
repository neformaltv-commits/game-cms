<?php

declare(strict_types=1);

namespace Tests\Emoji;

use App\Audit\Logger;
use App\Emoji\EmojiBankService;
use App\Emoji\RasterReencoder;
use App\Emoji\UploadException;
use App\Storage\FileStore;

require_once __DIR__ . '/../TestCase.php';

/**
 * Тесты блока 6 ТЗ: загрузка PNG/GIF, PUA-кодпоинты, ре-энкод без изменения размеров.
 */
final class RasterTest extends \TestCase
{
    private string $tmp = '';
    private EmojiBankService $bank;
    private FileStore $store;
    private string $mediaRoot;

    public function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/emojitest-' . bin2hex(random_bytes(6));
        mkdir($this->tmp . '/data', 0777, true);
        mkdir($this->tmp . '/media', 0777, true);
        $this->store = new FileStore($this->tmp . '/data');
        $audit = new Logger($this->store, 'audit.log', 14);
        $this->mediaRoot = $this->tmp . '/media';
        $this->bank = new EmojiBankService($this->store, $audit, $this->mediaRoot, 'admin');
    }

    public function tearDown(): void
    {
        if ($this->tmp !== '') {
            exec('rm -rf ' . escapeshellarg($this->tmp));
            $this->tmp = '';
        }
        parent::tearDown();
    }

    /** Делает PNG заданного размера во временном файле, возвращает путь. */
    private function makePng(int $w, int $h, bool $withMetadata = false): string
    {
        $img = imagecreatetruecolor($w, $h);
        imagealphablending($img, false);
        imagesavealpha($img, true);
        $transparent = imagecolorallocatealpha($img, 0, 0, 0, 127);
        imagefill($img, 0, 0, $transparent);
        $red = imagecolorallocate($img, 255, 0, 0);
        imagesetpixel($img, (int)($w / 2), (int)($h / 2), $red);
        $path = $this->tmp . '/src.png';
        imagepng($img, $path);
        imagedestroy($img);
        if ($withMetadata) {
            // Вставляем корректный tEXt-чанк (метаданные) перед IEND:
            // length(4) + "tEXt" + keyword + \0 + text + CRC32(type+data).
            $png = file_get_contents($path);
            $data = 'tEXt' . "Comment\x00made-by-test";
            $chunk = pack('N', strlen($data)) . $data . pack('N', crc32($data));
            $iendPos = strpos($png, 'IEND');
            $this->assertTrue($iendPos !== false);
            $png = substr($png, 0, $iendPos - 4) . $chunk . substr($png, $iendPos - 4);
            file_put_contents($path, $png);
        }
        return $path;
    }

    private function makeGif(int $w, int $h): string
    {
        $img = imagecreatetruecolor($w, $h);
        $blue = imagecolorallocate($img, 0, 0, 255);
        imagefilledrectangle($img, 0, 0, $w - 1, $h - 1, $blue);
        $path = $this->tmp . '/src.gif';
        imagegif($img, $path);
        imagedestroy($img);
        return $path;
    }

    // Критерий 1: PNG 32×32 -> e001.png 32×32, codepoint U+E000, source_size 32×32.
    public function testUploadPng32(): void
    {
        $re = new RasterReencoder();
        $res = $re->reencodeToPng($this->makePng(32, 32));
        $this->assertSame(32, $res['width']);
        $this->assertSame(32, $res['height']);

        $rec = $this->bank->addStatic('png', $res['png'], 'Пиксельный рыцарь', 'персонажи',
            ['width' => $res['width'], 'height' => $res['height']]);
        $this->assertSame('e001', $rec['id']);
        $this->assertSame('U+E000', $rec['codepoint']);
        $this->assertSame(32, $rec['source_size']['width']);
        $this->assertTrue(is_file($this->mediaRoot . '/emoji-bank/e001.png'));
        // сохранённый файл — валидный PNG того же размера
        $info = getimagesize($this->mediaRoot . '/emoji-bank/e001.png');
        $this->assertSame(32, $info[0]);
        $this->assertSame(32, $info[1]);
    }

    // Критерий 2: PNG 128×128 сохраняется 128×128.
    public function testUploadPng128KeepsSize(): void
    {
        $re = new RasterReencoder();
        $res = $re->reencodeToPng($this->makePng(128, 128));
        $this->assertSame(128, $res['width']);
        $this->assertSame(128, $res['height']);
    }

    // Критерий 3: >256×256 — ошибка.
    public function testRejectsTooLarge(): void
    {
        $re = new RasterReencoder();
        $this->assertThrows(UploadException::class, fn() => $re->reencodeToPng($this->makePng(512, 512)));
    }

    // Критерий 4: <8×8 — ошибка.
    public function testRejectsTooSmall(): void
    {
        $re = new RasterReencoder();
        $this->assertThrows(UploadException::class, fn() => $re->reencodeToPng($this->makePng(4, 4)));
    }

    // Критерий 5: >200 KB — ошибка.
    public function testRejectsOver200Kb(): void
    {
        $path = $this->tmp . '/big.png';
        $bytes = file_get_contents($this->makePng(200, 200));
        // раздуваем полиглотом после IEND до >200KB
        $bytes .= str_repeat('A', 210 * 1024);
        file_put_contents($path, $bytes);
        $re = new RasterReencoder();
        $this->assertThrows(UploadException::class, fn() => $re->reencodeToPng($path));
    }

    // Критерий 6: PDF — «Допустимы только PNG, GIF, SVG».
    public function testRejectsPdf(): void
    {
        $path = $this->tmp . '/doc.pdf';
        file_put_contents($path, "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF");
        $re = new RasterReencoder();
        try {
            $re->reencodeToPng($path);
            $this->assertTrue(false, 'ожидался отказ');
        } catch (UploadException $e) {
            $this->assertContains('Допустимы только PNG, GIF, SVG', $e->getMessage());
        }
    }

    // Критерий 7: метаданные вычищаются ре-энкодом, размеры сохраняются.
    public function testReencodeStripsMetadata(): void
    {
        $src = $this->makePng(64, 64, true);
        $this->assertContains('Comment', (string)file_get_contents($src));
        $re = new RasterReencoder();
        $res = $re->reencodeToPng($src);
        $this->assertSame(64, $res['width']);
        $this->assertFalse(str_contains($res['png'], 'Comment'), 'метаданные должны быть удалены');
        // выходной PNG корректен и заканчивается на IEND сразу
        $this->assertContains("\x49\x45\x4E\x44\xAE\x42\x60\x82", $res['png']);
    }

    // Критерии 8–9: GIF -> первый кадр в PNG без изменения размеров.
    public function testGifFirstFrameToPng(): void
    {
        $re = new RasterReencoder();
        $res = $re->reencodeToPng($this->makeGif(24, 24));
        $this->assertSame(24, $res['width']);
        $this->assertSame(24, $res['height']);
        $rec = $this->bank->addStatic('png', $res['png'], 'Флаг', 'символы',
            ['width' => $res['width'], 'height' => $res['height']], true);
        $this->assertSame('gif', $rec['source_format']);
        $this->assertSame('png', $rec['stored_format']);
        $this->assertSame('U+E000', $rec['codepoint']);
    }

    // Критерий 16: последовательные кодпоинты без пропусков.
    public function testSequentialCodepoints(): void
    {
        $res = (new RasterReencoder())->reencodeToPng($this->makePng(16, 16));
        $a = $this->bank->addStatic('png', $res['png'], 'Первый', 'другое', ['width' => 16, 'height' => 16]);
        $b = $this->bank->addStatic('png', $res['png'], 'Второй', 'другое', ['width' => 16, 'height' => 16]);
        $c = $this->bank->addStatic('png', $res['png'], 'Третий', 'другое', ['width' => 16, 'height' => 16]);
        $this->assertSame('U+E000', $a['codepoint']);
        $this->assertSame('U+E001', $b['codepoint']);
        $this->assertSame('U+E002', $c['codepoint']);
    }

    // Критерий 17: после удаления третьего следующий получает U+E003, а не U+E002.
    public function testCodepointNotReusedAfterDelete(): void
    {
        $res = (new RasterReencoder())->reencodeToPng($this->makePng(16, 16));
        $this->bank->addStatic('png', $res['png'], 'Первый', 'другое', ['width' => 16, 'height' => 16]);
        $this->bank->addStatic('png', $res['png'], 'Второй', 'другое', ['width' => 16, 'height' => 16]);
        $third = $this->bank->addStatic('png', $res['png'], 'Третий', 'другое', ['width' => 16, 'height' => 16]);
        $errors = $this->bank->delete($third['id']);
        $this->assertSame([], $errors);
        $fourth = $this->bank->addStatic('png', $res['png'], 'Четвёртый', 'другое', ['width' => 16, 'height' => 16]);
        $this->assertSame('U+E003', $fourth['codepoint']);
        $this->assertSame('e004', $fourth['id']);
        $this->assertSame(4, $this->bank->counterNext());
    }

    // Ошибки метаданных: name/category.
    public function testMetaValidation(): void
    {
        $res = (new RasterReencoder())->reencodeToPng($this->makePng(16, 16));
        $this->assertThrows(UploadException::class, fn() =>
            $this->bank->addStatic('png', $res['png'], 'А', 'другое', ['width' => 16, 'height' => 16]));
        $this->assertThrows(UploadException::class, fn() =>
            $this->bank->addStatic('png', $res['png'], 'Норм имя', 'несуществующая', ['width' => 16, 'height' => 16]));
        // счётчик не вырос при неудачных попытках
        $this->assertSame(0, $this->bank->counterNext());
    }
}
