<?php

declare(strict_types=1);

namespace BenTools\UrlPattern\Internal;

use BenTools\UrlPattern\Exception\InvalidPatternException;
use Uri\WhatWg\Url;

use function array_pop;
use function ctype_digit;
use function ctype_xdigit;
use function implode;
use function in_array;
use function ltrim;
use function str_contains;
use function str_replace;
use function strlen;
use function strtolower;
use function substr;

/**
 * The URLPattern encoding callbacks.
 *
 * `Uri\WhatWg\Url` does not expose the "state override" entry points of the basic URL parser,
 * so the states involved are reproduced here, and the URL parser is only used for what
 * requires it (scheme validation, host parsing with IDNA / IPv4 / IPv6 support).
 *
 * @see https://urlpattern.spec.whatwg.org/#canon-encoding-callbacks
 *
 * @internal
 */
final class Canonicalizer
{
    private const ASCII_TAB_OR_NEWLINE = ["\t", "\n", "\r"];
    private const HOSTNAME_STATE_TERMINATORS = ['/', '?', '#', '\\'];
    private const PATH_SEPARATORS = ['/', '\\'];
    private const SINGLE_DOT_SEGMENTS = ['.', '%2e'];
    private const DOUBLE_DOT_SEGMENTS = ['..', '.%2e', '%2e.', '%2e%2e'];
    private const MAX_PORT = 65535;

    public static function protocol(string $value): string
    {
        if ('' === $value) {
            return $value;
        }
        $url = Url::parse($value . '://dummy.invalid/');
        if (null === $url) {
            throw new InvalidPatternException('Invalid protocol "' . $value . '".');
        }

        return $url->getScheme();
    }

    public static function username(string $value): string
    {
        return PercentEncoder::encode($value, PercentEncoder::USERINFO_SET);
    }

    public static function password(string $value): string
    {
        return PercentEncoder::encode($value, PercentEncoder::USERINFO_SET);
    }

    /**
     * Runs the host state of the basic URL parser with a "hostname state" override on a special URL.
     */
    public static function hostname(string $value): string
    {
        if ('' === $value) {
            return $value;
        }

        $buffer = '';
        $insideBrackets = false;
        foreach (CodePoints::split(self::removeTabsAndNewlines($value)) as $c) {
            if (':' === $c && !$insideBrackets) {
                throw new InvalidPatternException('Invalid hostname "' . $value . '".');
            }
            if (in_array($c, self::HOSTNAME_STATE_TERMINATORS, true)) {
                break;
            }
            if ('[' === $c) {
                $insideBrackets = true;
            } elseif (']' === $c) {
                $insideBrackets = false;
            }
            $buffer .= $c;
        }

        // "@" is a forbidden host code point; it is rejected upfront because the URL parser
        // used below would otherwise interpret it as a credentials delimiter.
        $url = '' === $buffer || str_contains($buffer, '@') ? null : Url::parse('https://' . $buffer . '/');
        if (null === $url) {
            throw new InvalidPatternException('Invalid hostname "' . $value . '".');
        }

        return self::serializeHost($url);
    }

    /**
     * Serializes the host of a URL (the empty string when it has none).
     *
     * Hosts of special URLs are always lowercase per the specification (UTS #46 mapping), but the
     * native parser of PHP 8.5 does not lowercase percent-decoded code points (e.g. "%41.com"), hence
     * the explicit lowercasing. Hosts of non-special URLs are opaque and keep their case.
     */
    public static function serializeHost(Url $url): string
    {
        $host = (string) $url->getAsciiHost();

        return SpecialScheme::isSpecial($url->getScheme()) ? strtolower($host) : $host;
    }

    /**
     * Serializes the path of a URL.
     *
     * The path percent-encode set includes "^", but the native parser of PHP 8.5 leaves it as is
     * in non-opaque paths (opaque paths only use the C0 control percent-encode set), hence the
     * explicit encoding, consistent with {@see self::pathname()}.
     */
    public static function serializePath(Url $url): string
    {
        $path = $url->getPath();

        return InitProcessor::hasOpaquePath($url) ? $path : str_replace('^', '%5E', $path);
    }

    public static function ipv6Hostname(string $value): string
    {
        $length = strlen($value);
        for ($index = 0; $index < $length; ++$index) {
            $c = $value[$index];
            if (!ctype_xdigit($c) && '[' !== $c && ']' !== $c && ':' !== $c) {
                throw new InvalidPatternException('Invalid IPv6 hostname "' . $value . '".');
            }
        }

        return strtolower($value);
    }

    /**
     * Runs the port state of the basic URL parser with a state override.
     */
    public static function port(string $portValue, ?string $protocolValue = null): string
    {
        if ('' === $portValue) {
            return $portValue;
        }

        $input = self::removeTabsAndNewlines($portValue);
        $length = strlen($input);
        $digits = 0;
        while ($digits < $length && ctype_digit($input[$digits])) {
            ++$digits;
        }
        if (0 === $digits) {
            throw new InvalidPatternException('Invalid port "' . $portValue . '".');
        }

        $port = ltrim(substr($input, 0, $digits), '0');
        if (strlen($port) > 5 || (int) $port > self::MAX_PORT) {
            throw new InvalidPatternException('Invalid port "' . $portValue . '".');
        }

        if (null !== $protocolValue && SpecialScheme::defaultPort($protocolValue) === (int) $port) {
            return '';
        }

        return (string) (int) $port;
    }

    /**
     * Runs the path start state of the basic URL parser with a state override on a special URL.
     */
    public static function pathname(string $value): string
    {
        if ('' === $value) {
            return $value;
        }

        $leadingSlash = '/' === $value[0];
        $modifiedValue = self::removeTabsAndNewlines(($leadingSlash ? '' : '/-') . $value);

        $segments = [];
        $buffer = '';
        // The path start state consumes the leading "/" (or "\") without producing a segment.
        $codePoints = CodePoints::split(substr($modifiedValue, 1));
        $codePoints[] = null;
        foreach ($codePoints as $c) {
            if (null !== $c && !in_array($c, self::PATH_SEPARATORS, true)) {
                $buffer .= PercentEncoder::encode($c, PercentEncoder::PATH_SET);
                continue;
            }
            $lowerBuffer = strtolower($buffer);
            if (in_array($lowerBuffer, self::DOUBLE_DOT_SEGMENTS, true)) {
                array_pop($segments);
                if (null === $c) {
                    $segments[] = '';
                }
            } elseif (in_array($lowerBuffer, self::SINGLE_DOT_SEGMENTS, true)) {
                if (null === $c) {
                    $segments[] = '';
                }
            } else {
                $segments[] = $buffer;
            }
            $buffer = '';
        }

        $result = [] === $segments ? '' : '/' . implode('/', $segments);

        return $leadingSlash ? $result : substr($result, 2);
    }

    /**
     * Runs the opaque path state of the basic URL parser with a state override.
     */
    public static function opaquePathname(string $value): string
    {
        if ('' === $value) {
            return $value;
        }

        $result = '';
        $codePoints = CodePoints::split(self::removeTabsAndNewlines($value));
        foreach ($codePoints as $index => $c) {
            if ('?' === $c || '#' === $c) {
                break;
            }
            if (' ' === $c) {
                $next = $codePoints[$index + 1] ?? null;
                $result .= '?' === $next || '#' === $next ? '%20' : ' ';
                continue;
            }
            $result .= PercentEncoder::encode($c);
        }

        return $result;
    }

    public static function search(string $value): string
    {
        return PercentEncoder::encode(self::removeTabsAndNewlines($value), PercentEncoder::SPECIAL_QUERY_SET);
    }

    public static function hash(string $value): string
    {
        return PercentEncoder::encode(self::removeTabsAndNewlines($value), PercentEncoder::FRAGMENT_SET);
    }

    private static function removeTabsAndNewlines(string $value): string
    {
        return str_replace(self::ASCII_TAB_OR_NEWLINE, '', $value);
    }
}
