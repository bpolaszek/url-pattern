<?php

declare(strict_types=1);

namespace BenTools\UrlPattern\Internal\Parser;

/**
 * @internal
 */
enum PartType
{
    case FixedText;
    case Regexp;
    case SegmentWildcard;
    case FullWildcard;
}
