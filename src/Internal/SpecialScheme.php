<?php

declare(strict_types=1);

namespace BenTools\UrlPattern\Internal;

use function array_key_exists;

/**
 * @see https://url.spec.whatwg.org/#special-scheme
 *
 * @internal
 */
final class SpecialScheme
{
    /**
     * Special schemes and their default port (null when the scheme has none).
     */
    public const DEFAULT_PORTS = [
        'ftp' => 21,
        'file' => null,
        'http' => 80,
        'https' => 443,
        'ws' => 80,
        'wss' => 443,
    ];

    public static function isSpecial(string $scheme): bool
    {
        return array_key_exists($scheme, self::DEFAULT_PORTS);
    }

    public static function defaultPort(string $scheme): ?int
    {
        return self::DEFAULT_PORTS[$scheme] ?? null;
    }
}
