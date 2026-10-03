<?php

declare(strict_types=1);

namespace BenTools\UrlPattern\Internal\Parser;

/**
 * @internal
 */
final readonly class Part
{
    public function __construct(
        public PartType $type,
        public string $value,
        public PartModifier $modifier,
        public string $name = '',
        public string $prefix = '',
        public string $suffix = '',
    ) {
    }
}
