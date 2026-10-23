<?php

declare(strict_types=1);

namespace App\Emoji;

/**
 * Санитизация SVG до геометрии + градиентов (ТЗ блок 6 §4).
 *
 * Белый список элементов/атрибутов, отсечение XXE, проверка url(#id),
 * требование «есть хотя бы одна фигура», лимит viewBox/width/height <= 512.
 */
final class GeometrySvgSanitizer
{
    /** @var list<string> */
    private const ALLOWED_ELEMENTS = [
        'svg', 'g', 'defs',
        'linearGradient', 'radialGradient', 'stop',
        'rect', 'circle', 'ellipse', 'line', 'polyline', 'polygon', 'path',
    ];

    /** @var list<string> Элементы-фигуры (для проверки «есть геометрия»). */
    private const SHAPE_ELEMENTS = ['rect', 'circle', 'ellipse', 'line', 'polyline', 'polygon', 'path'];

    /** Атрибуты, разрешённые на любом элементе. */
    private const GLOBAL_ATTRS = ['id'];

    /** @var array<string,list<string>> Специфичные атрибуты по элементам (ТЗ §4.2). */
    private const ELEMENT_ATTRS = [
        'svg' => ['viewBox', 'width', 'height', 'xmlns'],
        'g' => [],
        'defs' => [],
        'linearGradient' => ['x1', 'y1', 'x2', 'y2', 'gradientUnits', 'gradientTransform', 'spreadMethod'],
        'radialGradient' => ['cx', 'cy', 'r', 'fx', 'fy', 'gradientUnits', 'gradientTransform', 'spreadMethod'],
        'stop' => ['offset', 'stop-color', 'stop-opacity'],
        'rect' => ['x', 'y', 'width', 'height', 'rx', 'ry'],
        'circle' => ['cx', 'cy', 'r'],
        'ellipse' => ['cx', 'cy', 'rx', 'ry'],
        'line' => ['x1', 'y1', 'x2', 'y2'],
        'polyline' => ['points'],
        'polygon' => ['points'],
        'path' => ['d'],
    ];

    /** Presentational-атрибуты, разрешённые на геометрии/группах. */
    private const PRESENTATION_ATTRS = [
        'transform',
        'fill', 'stroke',
        'opacity', 'fill-opacity', 'stroke-opacity',
        'stroke-width', 'stroke-linecap', 'stroke-linejoin',
        'stroke-dasharray', 'stroke-dashoffset',
        'fill-rule', 'clip-rule',
    ];

    private const TRANSFORM_FUNC_RE = '/^(matrix|translate|scale|rotate|skewX|skewY)\(\s*[-+0-9eE.,\s]*\)$/';

    private const COLOR_RE = '/^(none|#[0-9a-fA-F]{3,8}|rgb\(\s*[-0-9.%\s,]+\)|rgba\(\s*[-0-9.%\s,]+\)|hsl\(\s*[-0-9.%\s,()]+\)|hsla\(\s*[-0-9.%\s,()]+\)|[a-zA-Z]+|url\(#[a-zA-Z_][a-zA-Z0-9_-]{0,63}\))$/';

    private const ID_RE = '/^[a-zA-Z_][a-zA-Z0-9_-]{0,63}$/';

    public function sanitize(string $svg): SanitizedSvg
    {
        // §4.4 защита от XXE: явная проверка до парсинга.
        if (str_contains($svg, '<!DOCTYPE') || str_contains($svg, '<!ENTITY')) {
            throw new SvgSanitizeException('SVG содержит DOCTYPE/ENTITY и отклонён');
        }

        $prev = libxml_use_internal_errors(true);
        $doc = new \DOMDocument();
        // LIBXML_NONET — запрет сетевых сущностей; в PHP 8 external entities выключены по умолчанию.
        $ok = $doc->loadXML($svg, LIBXML_NONET | LIBXML_NOENT | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        if (!$ok || $doc->documentElement === null) {
            throw new SvgSanitizeException('Не удалось разобрать XML');
        }
        if ($doc->doctype !== null) {
            throw new SvgSanitizeException('SVG содержит DOCTYPE и отклонён');
        }

        $root = $doc->documentElement;
        if ($root->localName !== 'svg') {
            throw new SvgSanitizeException('Корневой элемент должен быть <svg>');
        }

        $this->checkDimensions($root);

        // libxml не даёт удалить xmlns:*-декларации через DOM API — убираем их регуляркой.
        // В санитизированном дереве не остаётся элементов/атрибутов с префиксами, поэтому безопасно.
        $root = new \DOMDocument();
        $root->loadXML(preg_replace('/\sxmlns:[a-zA-Z0-9_-]+="[^"]*"/', '', $doc->saveXML($root)) ?? $doc->saveXML($root));
        $svgRoot = $root->documentElement;

        $removed = [];
        $this->walk($svgRoot, $removed);

        // §4.5: есть хотя бы одна фигура.
        if ($this->countShapes($svgRoot) === 0) {
            throw new SvgSanitizeException('SVG не содержит геометрии');
        }

        // §4.3: связность url(#id).
        $gradientIds = $this->collectGradientIds($svgRoot);
        $this->fixUrlRefs($svgRoot, $gradientIds);

        // §4.6 сериализация: saveXML(root) без XML-декларации.
        $sanitized = $root->saveXML($svgRoot);
        if ($sanitized === false) {
            throw new SvgSanitizeException('Ошибка сериализации SVG');
        }

        return new SanitizedSvg(
            xml: $sanitized,
            hash: 'sha256:' . hash('sha256', $sanitized),
            removedElements: array_values(array_unique($removed)),
            sourceWidth: $this->dimension($svgRoot, 'width'),
            sourceHeight: $this->dimension($svgRoot, 'height'),
        );
    }

    private function checkDimensions(\DOMElement $root): void
    {
        foreach (['width', 'height'] as $attr) {
            if ($root->hasAttribute($attr)) {
                $v = $this->parsePx($root->getAttribute($attr));
                if ($v === null || $v > 512) {
                    throw new SvgSanitizeException('SVG: width/height должен быть числом <= 512');
                }
            }
        }
        if ($root->hasAttribute('viewBox')) {
            $parts = preg_split('/[\s,]+/', trim($root->getAttribute('viewBox'))) ?: [];
            $parts = array_values(array_filter($parts, static fn($p) => $p !== ''));
            if (count($parts) !== 4) {
                throw new SvgSanitizeException('SVG: некорректный viewBox');
            }
            foreach ($parts as $p) {
                if (!is_numeric($p)) {
                    throw new SvgSanitizeException('SVG: некорректный viewBox');
                }
            }
            $w = (float)$parts[2];
            $h = (float)$parts[3];
            if ($w > 512 || $h > 512) {
                throw new SvgSanitizeException('SVG: viewBox больше 512');
            }
        }
    }

    private function parsePx(string $v): ?float
    {
        $v = trim($v);
        if (preg_match('/^([0-9]+(?:\.[0-9]+)?)(px)?$/', $v, $m)) {
            return (float)$m[1];
        }
        return null;
    }

    /** @param list<string> $removed */
    private function walk(\DOMElement $el, array &$removed): void
    {
        $name = $el->localName;
        if (!in_array($name, self::ALLOWED_ELEMENTS, true)) {
            $removed[] = $name;
            $el->parentNode?->removeChild($el);
            return;
        }

        // Обход детей; удаление комментариев/текстовых узлов/CDATA/PI.
        foreach (iterator_to_array($el->childNodes) as $child) {
            if ($child instanceof \DOMElement) {
                $this->walk($child, $removed);
            } elseif ($child instanceof \DOMComment
                || $child instanceof \DOMCDATASection
                || $child instanceof \DOMProcessingInstruction
                || $child instanceof \DOMEntityReference
                || ($child instanceof \DOMText && trim($child->nodeValue ?? '') !== '')) {
                $el->removeChild($child);
            }
        }

        // Атрибуты.
        foreach (iterator_to_array($el->attributes) as $attr) {
            $qName = $attr->nodeName;
            // Запрет всего с namespace prefix (xlink:href, xml:base и т.п.), кроме декларации xmlns.
            if (str_contains($qName, ':') && !str_starts_with($qName, 'xmlns')) {
                $removed[] = "attr:$qName";
                $el->removeAttribute($qName);
                continue;
            }
            if ($qName === 'xmlns' || str_starts_with($qName, 'xmlns:')) {
                // Сохраняем только декларацию основного xmlns на корневом <svg>;
                // прочие xmlns:*-префиксы удаляем (иначе DOM сохранит их и xlink-ссылки «оживут»).
                $isRootXmlns = $qName === 'xmlns' && $el->isSameNode($el->ownerDocument->documentElement);
                if (!$isRootXmlns) {
                    $removed[] = "attr:$qName";
                }
                // removeAttribute по qualified name не срабатывает для xmlns:* — используем namespace-вариант.
                $el->removeAttributeNS($attr->namespaceURI ?? null, $attr->localName);
                continue;
            }
            if (!$this->isAttrAllowed($name, $qName)) {
                $removed[] = "attr:$qName";
                $el->removeAttribute($qName);
                continue;
            }
            if (!$this->isAttrValueValid($name, $qName, $attr->nodeValue ?? '')) {
                $removed[] = "attr:$qName";
                $el->removeAttribute($qName);
            }
        }
    }

    private function isAttrAllowed(string $element, string $attr): bool
    {
        if (in_array($attr, self::GLOBAL_ATTRS, true)) {
            return true;
        }
        $specific = self::ELEMENT_ATTRS[$element] ?? [];
        if (in_array($attr, $specific, true)) {
            return true;
        }
        return in_array($attr, self::PRESENTATION_ATTRS, true);
    }

    private function isAttrValueValid(string $element, string $attr, string $value): bool
    {
        if ($attr === 'id') {
            return (bool)preg_match(self::ID_RE, $value);
        }
        if ($attr === 'transform') {
            $parts = preg_split('/\)\s*,?\s*/', trim($value, '; ')) ?: [];
            foreach ($parts as $p) {
                $p = trim($p);
                if ($p === '') {
                    continue;
                }
                if (!preg_match(self::TRANSFORM_FUNC_RE, $p . ')')) {
                    return false;
                }
            }
            return true;
        }
        if (in_array($attr, ['fill', 'stroke', 'stop-color'], true)) {
            if (stripos($value, 'currentcolor') !== false) {
                return false;
            }
            return (bool)preg_match(self::COLOR_RE, trim($value));
        }
        if (in_array($attr, ['opacity', 'fill-opacity', 'stroke-opacity', 'stop-opacity'], true)) {
            $f = filter_var(trim($value), FILTER_VALIDATE_FLOAT);
            return $f !== false && $f >= 0 && $f <= 1;
        }
        if ($attr === 'stroke-linecap') {
            return in_array($value, ['butt', 'round', 'square'], true);
        }
        if ($attr === 'stroke-linejoin') {
            return in_array($value, ['miter', 'round', 'bevel'], true);
        }
        if ($attr === 'fill-rule' || $attr === 'clip-rule') {
            return in_array($value, ['nonzero', 'evenodd'], true);
        }
        if ($attr === 'gradientUnits') {
            return in_array($value, ['objectBoundingBox', 'userSpaceOnUse'], true);
        }
        if ($attr === 'spreadMethod') {
            return in_array($value, ['pad', 'reflect', 'repeat'], true);
        }
        if ($attr === 'stroke-width' || $attr === 'stroke-dashoffset') {
            return filter_var(trim($value), FILTER_VALIDATE_FLOAT) !== false;
        }
        if ($attr === 'stroke-dasharray') {
            return (bool)preg_match('/^\s*(?:[-+0-9.eE]+(?:\s*,?\s*[-+0-9.eE]+)*)?\s*$/', $value)
                || trim($value) === 'none';
        }
        if ($attr === 'offset') {
            $t = trim($value);
            if (str_ends_with($t, '%')) {
                return true;
            }
            $f = filter_var($t, FILTER_VALIDATE_FLOAT);
            return $f !== false && $f >= 0 && $f <= 1;
        }
        if ($attr === 'gradientTransform') {
            return $this->isAttrValueValid($element, 'transform', $value);
        }
        return true;
    }

    private function countShapes(\DOMElement $root): int
    {
        $count = 0;
        foreach ($root->getElementsByTagName('*') as $el) {
            /** @var \DOMElement $el */
            if (in_array($el->localName, self::SHAPE_ELEMENTS, true)) {
                $count++;
            }
        }
        return $count;
    }

    /** @return array<string,true> id градиентов */
    private function collectGradientIds(\DOMElement $root): array
    {
        $ids = [];
        foreach ($root->getElementsByTagName('*') as $el) {
            /** @var \DOMElement $el */
            if (in_array($el->localName, ['linearGradient', 'radialGradient'], true)) {
                $id = $el->getAttribute('id');
                if ($id !== '') {
                    $ids[$id] = true;
                }
            }
        }
        return $ids;
    }

    /** @param array<string,true> $gradientIds */
    private function fixUrlRefs(\DOMElement $root, array $gradientIds): void
    {
        foreach ($root->getElementsByTagName('*') as $el) {
            /** @var \DOMElement $el */
            foreach (['fill', 'stroke', 'stop-color'] as $attr) {
                if (!$el->hasAttribute($attr)) {
                    continue;
                }
                $v = trim($el->getAttribute($attr));
                if (preg_match('/^url\(#([^)]+)\)$/', $v, $m)) {
                    if (!isset($gradientIds[$m[1]])) {
                        $el->setAttribute($attr, 'none');
                    }
                }
            }
        }
    }

    private function dimension(\DOMElement $root, string $attr): ?int
    {
        if ($root->hasAttribute('viewBox')) {
            $parts = preg_split('/[\s,]+/', trim($root->getAttribute('viewBox'))) ?: [];
            $parts = array_values(array_filter($parts, static fn($p) => $p !== ''));
            if ($attr === 'width' && isset($parts[2])) {
                return (int)round((float)$parts[2]);
            }
            if ($attr === 'height' && isset($parts[3])) {
                return (int)round((float)$parts[3]);
            }
        }
        if ($root->hasAttribute($attr)) {
            $v = $this->parsePx($root->getAttribute($attr));
            if ($v !== null) {
                return (int)round($v);
            }
        }
        return null;
    }
}
