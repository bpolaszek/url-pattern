<?php

declare(strict_types=1);

namespace BenTools\UrlPattern\Internal\Parser;

/**
 * @see https://urlpattern.spec.whatwg.org/#options-header
 *
 * @internal
 */
final readonly class Options
{
    public function __construct(
        public string $delimiterCodePoint = '',
        public string $prefixCodePoint = '',
        public bool $ignoreCase = false,
    ) {
    }

    public static function default(bool $ignoreCase = false): self
    {
        return new self('', '', $ignoreCase);
    }

    public static function hostname(): self
    {
        return new self('.');
    }

    public static function pathname(bool $ignoreCase): self
    {
        return new self('/', '/', $ignoreCase);
    }
}
