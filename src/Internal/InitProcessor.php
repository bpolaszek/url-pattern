<?php

declare(strict_types=1);

namespace BenTools\UrlPattern\Internal;

use BenTools\UrlPattern\Exception\InvalidPatternException;
use BenTools\UrlPattern\Internal\Parser\PatternStringGenerator;
use Uri\WhatWg\Url;

use function array_key_exists;
use function is_string;
use function str_ends_with;
use function str_starts_with;
use function strlen;
use function strrpos;
use function substr;

/**
 * Implements "process a URLPatternInit".
 *
 * @see https://urlpattern.spec.whatwg.org/#process-a-urlpatterninit
 *
 * @internal
 */
final class InitProcessor
{
    public const COMPONENTS = ['protocol', 'username', 'password', 'hostname', 'port', 'pathname', 'search', 'hash'];
    private const INIT_KEYS = [...self::COMPONENTS, 'baseURL'];

    /**
     * @param array<mixed> $init
     * @param bool $patternMode true for the "pattern" type, false for the "url" type
     * @param array<string, string> $defaults
     *
     * @return array<string, string>
     *
     * @throws InvalidPatternException
     */
    public static function process(array $init, bool $patternMode, array $defaults = []): array
    {
        $init = self::normalize($init);
        $result = $defaults;

        $baseURL = null;
        if (isset($init['baseURL'])) {
            $baseURL = Url::parse($init['baseURL']);
            if (null === $baseURL) {
                throw new InvalidPatternException('Invalid base URL "' . $init['baseURL'] . '".');
            }

            $has = static fn (string ...$keys): bool => [] !== \array_intersect($keys, \array_keys($init));

            if (!$has('protocol')) {
                $result['protocol'] = self::processBaseURLString($baseURL->getScheme(), $patternMode);
            }
            if (!$patternMode && !$has('protocol', 'hostname', 'port', 'username')) {
                $result['username'] = self::processBaseURLString((string) $baseURL->getUsername(), $patternMode);
            }
            if (!$patternMode && !$has('protocol', 'hostname', 'port', 'username', 'password')) {
                $result['password'] = self::processBaseURLString((string) $baseURL->getPassword(), $patternMode);
            }
            if (!$has('protocol', 'hostname')) {
                $result['hostname'] = self::processBaseURLString(Canonicalizer::serializeHost($baseURL), $patternMode);
            }
            if (!$has('protocol', 'hostname', 'port')) {
                $result['port'] = (string) $baseURL->getPort();
            }
            if (!$has('protocol', 'hostname', 'port', 'pathname')) {
                $result['pathname'] = self::processBaseURLString(Canonicalizer::serializePath($baseURL), $patternMode);
            }
            if (!$has('protocol', 'hostname', 'port', 'pathname', 'search')) {
                $result['search'] = self::processBaseURLString((string) $baseURL->getQuery(), $patternMode);
            }
            if (!$has('protocol', 'hostname', 'port', 'pathname', 'search', 'hash')) {
                $result['hash'] = self::processBaseURLString((string) $baseURL->getFragment(), $patternMode);
            }
        }

        if (isset($init['protocol'])) {
            $protocol = str_ends_with($init['protocol'], ':') ? substr($init['protocol'], 0, -1) : $init['protocol'];
            $result['protocol'] = $patternMode ? $protocol : Canonicalizer::protocol($protocol);
        }
        if (isset($init['username'])) {
            $result['username'] = $patternMode ? $init['username'] : Canonicalizer::username($init['username']);
        }
        if (isset($init['password'])) {
            $result['password'] = $patternMode ? $init['password'] : Canonicalizer::password($init['password']);
        }
        if (isset($init['hostname'])) {
            $result['hostname'] = $patternMode ? $init['hostname'] : Canonicalizer::hostname($init['hostname']);
        }

        $resultProtocolString = $result['protocol'] ?? '';

        if (isset($init['port'])) {
            $result['port'] = $patternMode
                ? $init['port']
                : Canonicalizer::port($init['port'], $resultProtocolString);
        }

        if (isset($init['pathname'])) {
            $pathname = $init['pathname'];
            if (
                null !== $baseURL
                && !self::hasOpaquePath($baseURL)
                && !self::isAbsolutePathname($pathname, $patternMode)
            ) {
                $baseURLPath = self::processBaseURLString(Canonicalizer::serializePath($baseURL), $patternMode);
                $slashIndex = strrpos($baseURLPath, '/');
                if (false !== $slashIndex) {
                    $pathname = substr($baseURLPath, 0, $slashIndex + 1) . $pathname;
                }
            }
            $result['pathname'] = match (true) {
                $patternMode => $pathname,
                '' === $resultProtocolString || SpecialScheme::isSpecial($resultProtocolString)
                    => Canonicalizer::pathname($pathname),
                default => Canonicalizer::opaquePathname($pathname),
            };
        }

        if (isset($init['search'])) {
            $search = str_starts_with($init['search'], '?') ? substr($init['search'], 1) : $init['search'];
            $result['search'] = $patternMode ? $search : Canonicalizer::search($search);
        }

        if (isset($init['hash'])) {
            $hash = str_starts_with($init['hash'], '#') ? substr($init['hash'], 1) : $init['hash'];
            $result['hash'] = $patternMode ? $hash : Canonicalizer::hash($hash);
        }

        return $result;
    }

    /**
     * Converts a URLPatternInit dictionary like WebIDL: unknown keys are dropped, and values must be
     * strings, converted like USVStrings.
     *
     * @param array<mixed> $init
     *
     * @return array<string, string>
     *
     * @throws InvalidPatternException
     */
    public static function normalize(array $init): array
    {
        $normalized = [];
        foreach (self::INIT_KEYS as $key) {
            if (!array_key_exists($key, $init)) {
                continue;
            }
            if (!is_string($init[$key])) {
                throw new InvalidPatternException('URLPatternInit member "' . $key . '" must be a string.');
            }
            $normalized[$key] = CodePoints::scrub($init[$key]);
        }

        return $normalized;
    }

    private static function processBaseURLString(string $input, bool $patternMode): string
    {
        return $patternMode ? PatternStringGenerator::escapePatternString($input) : $input;
    }

    private static function isAbsolutePathname(string $input, bool $patternMode): bool
    {
        if ('' === $input) {
            return false;
        }
        if ('/' === $input[0]) {
            return true;
        }
        if (!$patternMode || strlen($input) < 2) {
            return false;
        }

        return ('\\' === $input[0] || '{' === $input[0]) && '/' === $input[1];
    }

    /**
     * A URL has an opaque path when its serialization has no "/" right after the scheme.
     */
    public static function hasOpaquePath(Url $url): bool
    {
        return !str_starts_with(substr($url->toAsciiString(), strlen($url->getScheme()) + 1), '/');
    }
}
