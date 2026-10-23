<?php

declare(strict_types=1);

namespace Tests\Svg;

use App\Emoji\GeometrySvgSanitizer;
use App\Emoji\SvgSanitizeException;

require_once __DIR__ . '/../TestCase.php';

final class SanitizerTest extends \TestCase
{
    private GeometrySvgSanitizer $s;

    public function setUp(): void
    {
        $this->s = new GeometrySvgSanitizer();
    }

    // Критерий 10: rect + path + linearGradient — геометрия и градиент остаются, hash заполнен.
    public function testKeepsGeometryAndGradient(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64">'
            . '<defs><linearGradient id="g1" x1="0" y1="0" x2="1" y2="1">'
            . '<stop offset="0%" stop-color="#ff0000"/><stop offset="100%" stop-color="blue"/>'
            . '</linearGradient></defs>'
            . '<rect x="1" y="1" width="10" height="10" fill="url(#g1)"/>'
            . '<path d="M0 0 L10 10" stroke="#0f0" fill="none"/>'
            . '</svg>';
        $res = $this->s->sanitize($svg);
        $this->assertContains('linearGradient', $res->xml);
        $this->assertContains('<rect', $res->xml);
        $this->assertContains('<path', $res->xml);
        $this->assertTrue(str_starts_with($res->hash, 'sha256:'));
        $this->assertSame(64, $res->sourceWidth);
        $this->assertSame(64, $res->sourceHeight);
        // без XML-декларации (§4.6)
        $this->assertTrue(!str_starts_with($res->xml, '<?xml'));
    }

    // Критерий 11: text и script удаляются, геометрия остаётся.
    public function testRemovesTextAndScript(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32">'
            . '<text x="0" y="0">evil</text>'
            . '<script>alert(1)</script>'
            . '<circle cx="5" cy="5" r="4" fill="red"/>'
            . '</svg>';
        $res = $this->s->sanitize($svg);
        $this->assertTrue(!str_contains($res->xml, 'text'));
        $this->assertTrue(!str_contains($res->xml, 'script'));
        $this->assertTrue(!str_contains($res->xml, 'alert'));
        $this->assertContains('<circle', $res->xml);
        $this->assertContains('text', $res->removedElements);
        $this->assertContains('script', $res->removedElements);
    }

    // Критерий 12: SVG без фигур → ошибка «SVG не содержит геометрии».
    public function testRejectsNoGeometry(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32"><g></g></svg>';
        $this->assertThrows(
            SvgSanitizeException::class,
            fn() => $this->s->sanitize($svg),
            'SVG не содержит геометрии'
        );
    }

    // Критерий 13: url(#missing) → fill становится none.
    public function testFixesMissingUrlRef(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32">'
            . '<rect x="0" y="0" width="4" height="4" fill="url(#missing)"/>'
            . '</svg>';
        $res = $this->s->sanitize($svg);
        $this->assertContains('fill="none"', $res->xml);
    }

    // url(#existing) сохраняется.
    public function testKeepsValidUrlRef(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32">'
            . '<defs><radialGradient id="ok" cx="0.5" cy="0.5" r="0.5">'
            . '<stop offset="0" stop-color="white"/></radialGradient></defs>'
            . '<rect x="0" y="0" width="4" height="4" fill="url(#ok)"/>'
            . '</svg>';
        $res = $this->s->sanitize($svg);
        $this->assertContains('url(#ok)', $res->xml);
    }

    // Критерий 14: <!DOCTYPE → отклонение.
    public function testRejectsDoctype(): void
    {
        $svg = '<!DOCTYPE svg [<!ENTITY x "y">]>'
            . '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32"><rect width="4" height="4"/></svg>';
        $this->assertThrows(
            SvgSanitizeException::class,
            fn() => $this->s->sanitize($svg),
            'DOCTYPE'
        );
    }

    // Критерий 15: viewBox 1024×1024 → ошибка.
    public function testRejectsLargeViewBox(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1024 1024"><rect width="4" height="4"/></svg>';
        $this->assertThrows(
            SvgSanitizeException::class,
            fn() => $this->s->sanitize($svg),
            'viewBox больше 512'
        );
    }

    public function testRejectsLargeWidthAttr(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="600" height="10"><rect width="4" height="4"/></svg>';
        $this->assertThrows(SvgSanitizeException::class, fn() => $this->s->sanitize($svg));
    }

    public function testRejectsNonSvgRoot(): void
    {
        $this->assertThrows(SvgSanitizeException::class, fn() => $this->s->sanitize('<html><body/></html>'));
    }

    // on*-атрибуты, href, class, currentColor удаляются (§4.2).
    public function testRemovesForbiddenAttributes(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32">'
            . '<rect x="0" y="0" width="4" height="4" onclick="alert(1)" class="c" fill="currentColor"/>'
            . '</svg>';
        $res = $this->s->sanitize($svg);
        $this->assertTrue(!str_contains($res->xml, 'onclick'));
        $this->assertTrue(!str_contains($res->xml, 'class'));
        $this->assertTrue(!str_contains($res->xml, 'currentColor'));
        // запрещённый fill удалён → атрибута нет вообще
        $this->assertTrue(!str_contains($res->xml, 'fill'));
    }

    public function testXlinkHrefRemoved(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" viewBox="0 0 32 32">'
            . '<image xlink:href="http://evil/x.png"/><rect width="4" height="4"/></svg>';
        $res = $this->s->sanitize($svg);
        $this->assertTrue(!str_contains($res->xml, 'image'));
        $this->assertTrue(!str_contains($res->xml, 'xlink'));
        $this->assertTrue(!str_contains($res->xml, 'evil'));
    }

    public function testStyleElementRemoved(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32">'
            . '<style>rect{fill:url(http://evil)}</style><rect width="4" height="4"/></svg>';
        $res = $this->s->sanitize($svg);
        $this->assertTrue(!str_contains($res->xml, 'style'));
        $this->assertTrue(!str_contains($res->xml, 'evil'));
    }

    public function testAnimateRemoved(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32">'
            . '<rect width="4" height="4"><animate attributeName="width" to="100"/></rect></svg>';
        $res = $this->s->sanitize($svg);
        $this->assertTrue(!str_contains($res->xml, 'animate'));
    }

    public function testTransformWhitelist(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32">'
            . '<rect width="4" height="4" transform="translate(2,3) rotate(45)"/>'
            . '</svg>';
        $res = $this->s->sanitize($svg);
        $this->assertContains('translate', $res->xml);

        $bad = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32">'
            . '<rect width="4" height="4" transform="url(#evil)"/>'
            . '</svg>';
        $res2 = $this->s->sanitize($bad);
        $this->assertTrue(!str_contains($res2->xml, 'transform'));
    }

    public function testOpacityRange(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32">'
            . '<rect width="4" height="4" opacity="0.5" fill-opacity="2"/>'
            . '</svg>';
        $res = $this->s->sanitize($svg);
        $this->assertContains('opacity="0.5"', $res->xml);
        $this->assertTrue(!str_contains($res->xml, 'fill-opacity'));
    }

    public function testIdValidation(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32">'
            . '<rect id="good_id-1" width="4" height="4"/>'
            . '<circle id="1bad" cx="1" cy="1" r="1"/>'
            . '</svg>';
        $res = $this->s->sanitize($svg);
        $this->assertContains('good_id-1', $res->xml);
        $this->assertTrue(!str_contains($res->xml, '1bad'));
    }

    public function testCommentsAndCDATARemoved(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32">'
            . '<!-- comment --><rect width="4" height="4"><![CDATA[abc]]></rect></svg>';
        $res = $this->s->sanitize($svg);
        $this->assertTrue(!str_contains($res->xml, 'comment'));
        $this->assertTrue(!str_contains($res->xml, 'CDATA'));
        $this->assertTrue(!str_contains($res->xml, 'abc'));
    }
}
