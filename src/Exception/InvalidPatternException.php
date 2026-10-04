<?php

declare(strict_types=1);

namespace BenTools\UrlPattern\Exception;

use InvalidArgumentException;

/**
 * Thrown when a URL pattern cannot be compiled.
 *
 * This is the equivalent of the `TypeError` thrown by the JavaScript `URLPattern` constructor.
 */
final class InvalidPatternException extends InvalidArgumentException
{
}
