<?php

declare(strict_types=1);

namespace BenTools\UrlPattern\Internal\Tokenizer;

/**
 * @internal
 */
final readonly class Token
{
    /**
     * @param int $index position (in code points) of the first code point represented by the token
     */
    public function __construct(
        public TokenType $type,
        public int $index,
        public string $value,
    ) {
    }
}
