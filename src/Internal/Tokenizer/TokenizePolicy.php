<?php

declare(strict_types=1);

namespace BenTools\UrlPattern\Internal\Tokenizer;

/**
 * @internal
 */
enum TokenizePolicy
{
    case Strict;
    case Lenient;
}
