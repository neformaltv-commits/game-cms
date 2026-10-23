<?php

declare(strict_types=1);

namespace App\Emoji;

/** Результат санитизации SVG. */
final class SanitizedSvg
{
    /** @param list<string> $removedElements */
    public function __construct(
        public readonly string $xml,
        public readonly string $hash,
        public readonly array $removedElements,
        public readonly ?int $sourceWidth,
        public readonly ?int $sourceHeight,
    ) {
    }
}
