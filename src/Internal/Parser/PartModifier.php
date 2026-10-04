<?php

declare(strict_types=1);

namespace BenTools\UrlPattern\Internal\Parser;

/**
 * @internal
 */
enum PartModifier: string
{
    case None = '';
    case Optional = '?';
    case ZeroOrMore = '*';
    case OneOrMore = '+';
}
