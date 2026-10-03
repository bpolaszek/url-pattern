<?php

declare(strict_types=1);

use BenTools\UrlPattern\Exception\InvalidPatternException;
use BenTools\UrlPattern\Internal\Canonicalizer;

describe('Canonicalizer::protocol', function (): void {
    it('canonicalizes valid schemes', function (string $input, string $expected): void {
        expect(Canonicalizer::protocol($input))->toBe($expected);
    })->with([
        'lowercase' => ['http', 'http'],
        'uppercase is lowercased' => ['HTTPS', 'https'],
        'special characters' => ['a+b', 'a+b'],
        'dots and dashes' => ['x-y.z', 'x-y.z'],
        'empty' => ['', ''],
    ]);

    it('rejects invalid schemes', function (string $input): void {
        expect(fn () => Canonicalizer::protocol($input))->toThrow(InvalidPatternException::class);
    })->with(['leading digit' => ['1a'], 'space' => ['a b']]);
});

describe('Canonicalizer::username and password', function (): void {
    it('percent-encodes with the userinfo set', function (string $input, string $expected): void {
        expect(Canonicalizer::username($input))->toBe($expected)
            ->and(Canonicalizer::password($input))->toBe($expected);
    })->with([
        'delimiters' => ['a b:c@/', 'a%20b%3Ac%40%2F'],
        'non-ASCII' => ['é', '%C3%A9'],
        'control characters' => ["ü\t", '%C3%BC%09'],
        'plain' => ['alice', 'alice'],
        'empty' => ['', ''],
    ]);
});

describe('Canonicalizer::hostname', function (): void {
    it('canonicalizes hostnames', function (string $input, string $expected): void {
        expect(Canonicalizer::hostname($input))->toBe($expected);
    })->with([
        'empty' => ['', ''],
        'lowercasing' => ['EXAMPLE.com', 'example.com'],
        'IDNA' => ['münchen.de', 'xn--mnchen-3ya.de'],
        'already punycode' => ['xn--mnchen-3ya.de', 'xn--mnchen-3ya.de'],
        'IPv4' => ['127.0.0.1', '127.0.0.1'],
        'IPv4 in hexadecimal' => ['0x7f.1', '127.0.0.1'],
        'percent-decoding' => ['a%41.com', 'aa.com'],
        'percent-decoding to uppercase only' => ['%41.com', 'a.com'],
        'tab and newline removal' => ["ex\tample.com", 'example.com'],
        'IPv6 in brackets' => ['[::1]', '[::1]'],
        'truncated at a slash' => ['exa/mple', 'exa'],
        'truncated at a question mark' => ['ex?a', 'ex'],
        'truncated at a hash' => ['ex#a', 'ex'],
        'truncated at a backslash' => ['ex\\a', 'ex'],
    ]);

    it('rejects invalid hostnames', function (string $input): void {
        expect(fn () => Canonicalizer::hostname($input))->toThrow(InvalidPatternException::class);
    })->with([
        'colon' => ['a:b'],
        'colon after IPv6' => ['[::1]:80'],
        'at sign' => ['a@b'],
        'forbidden host code point' => ['a b'],
        'only a terminator' => ['/x'],
        'unclosed IPv6' => ['[::1'],
        'stray closing bracket' => ['a]b'],
        'invalid IPv6' => ['[a:b]'],
    ]);
});

describe('Canonicalizer::ipv6Hostname', function (): void {
    it('lowercases valid IPv6 hostnames', function (string $input, string $expected): void {
        expect(Canonicalizer::ipv6Hostname($input))->toBe($expected);
    })->with([
        'simple' => ['[::1]', '[::1]'],
        'uppercase hexadecimal digits' => ['[ABCD::1]', '[abcd::1]'],
        'without brackets' => ['::1', '::1'],
        'empty' => ['', ''],
    ]);

    it('rejects characters that cannot belong to an IPv6 address', function (string $input): void {
        expect(fn () => Canonicalizer::ipv6Hostname($input))->toThrow(InvalidPatternException::class);
    })->with([
        'non-hexadecimal digit' => ['[::g]'],
        'trailing garbage' => ['[::1]x'],
    ]);
});

describe('Canonicalizer::port', function (): void {
    it('canonicalizes ports', function (string $input, ?string $protocol, string $expected): void {
        expect(Canonicalizer::port($input, $protocol))->toBe($expected);
    })->with([
        'empty' => ['', null, ''],
        'digits' => ['8080', null, '8080'],
        'maximum' => ['65535', null, '65535'],
        'zero' => ['0', null, '0'],
        'only zeros' => ['00000', null, '0'],
        'leading zeros' => ['008080', null, '8080'],
        'trailing non-digits are ignored' => ['80abc', null, '80'],
        'tab and newline removal' => ["8\t0", null, '80'],
        'default port of http is removed' => ['80', 'http', ''],
        'default port of https is removed' => ['443', 'https', ''],
        'default port of ws is removed' => ['80', 'ws', ''],
        'default port of wss is removed' => ['443', 'wss', ''],
        'default port of ftp is removed' => ['21', 'ftp', ''],
        'default port with leading zeros is removed' => ['0080', 'http', ''],
        'port that is the default of another scheme is kept' => ['80', 'https', '80'],
        'default port of an unknown scheme does not exist' => ['80', 'foo', '80'],
        'scheme without default port' => ['80', 'file', '80'],
    ]);

    it('rejects invalid ports', function (string $input): void {
        expect(fn () => Canonicalizer::port($input))->toThrow(InvalidPatternException::class);
    })->with([
        'above 65535' => ['65536'],
        'too many digits' => ['123456'],
        'non-digit' => ['abc'],
        'negative' => ['-1'],
    ]);
});

describe('Canonicalizer::pathname', function (): void {
    it('canonicalizes pathnames', function (string $input, string $expected): void {
        expect(Canonicalizer::pathname($input))->toBe($expected);
    })->with([
        'empty' => ['', ''],
        'root' => ['/', '/'],
        'plain' => ['/a/b', '/a/b'],
        'single dot segment' => ['/a/./b', '/a/b'],
        'encoded single dot segment' => ['/a/%2e/b', '/a/b'],
        'trailing single dot' => ['/a/b/.', '/a/b/'],
        'double dot segment' => ['/a/../b', '/b'],
        'encoded double dot segments, mixed case' => ['/a/%2E%2e/b', '/b'],
        'half-encoded double dot' => ['/a/.%2e', '/'],
        'trailing double dot' => ['/a/b/..', '/a/'],
        'double dot above the root' => ['/..', '/'],
        'double dot followed by a slash' => ['/a/../', '/'],
        'dot segment between segments' => ['/a/b/%2e%2E/c', '/a/c'],
        'backslash is a separator' => ['/a\\b', '/a/b'],
        'empty segments are kept' => ['/a//b', '/a//b'],
        'encoded dots inside a segment are kept' => ['/a%2eb', '/a%2eb'],
        'space' => ['/a b', '/a%20b'],
        'non-ASCII' => ['/é', '/%C3%A9'],
        'question mark' => ['/a?b', '/a%3Fb'],
        'hash' => ['/a#b', '/a%23b'],
        'braces' => ['/{a}', '/%7Ba%7D'],
        'caret and backtick' => ['/a^`', '/a%5E%60'],
        'tab removal' => ["/a\tb", '/ab'],
        'relative value without leading slash' => ['a/b', 'a/b'],
        'relative single segment' => ['a', 'a'],
        'relative dot segment is kept' => ['./a', './a'],
        'relative double dot segment is kept' => ['../a', '../a'],
        'relative value collapsing to nothing' => ['a/../../b', ''],
    ]);
});

describe('Canonicalizer::opaquePathname', function (): void {
    it('canonicalizes opaque pathnames', function (string $input, string $expected): void {
        expect(Canonicalizer::opaquePathname($input))->toBe($expected);
    })->with([
        'empty' => ['', ''],
        'plain' => ['a b', 'a b'],
        'non-ASCII' => ['/é', '/%C3%A9'],
        'truncated at a question mark' => ['a?b', 'a'],
        'truncated at a hash' => ['a#b', 'a'],
        'space before a question mark' => ['a ?b', 'a%20'],
        'space before a hash' => ['a #b', 'a%20'],
        'only the last space before a delimiter is encoded' => ['a  ?', 'a %20'],
        'trailing space is kept' => ['a b ', 'a b '],
        'lone space' => [' ', ' '],
        'tab and newline removal' => ["a\nb", 'ab'],
        'braces are kept' => ['x{y}', 'x{y}'],
        'percent sequences are kept' => ['%2e', '%2e'],
    ]);
});

describe('Canonicalizer::search and hash', function (): void {
    it('encodes search with the special query set', function (string $input, string $expected): void {
        expect(Canonicalizer::search($input))->toBe($expected);
    })->with([
        'space' => ['a b', 'a%20b'],
        'quote' => ["a'b", 'a%27b'],
        'double quote' => ['a"b', 'a%22b'],
        'hash' => ['a#b', 'a%23b'],
        'angle brackets' => ['a<b>', 'a%3Cb%3E'],
        'backtick is kept' => ['a`b', 'a`b'],
        'question mark is kept' => ['a?b', 'a?b'],
        'non-ASCII' => ['é', '%C3%A9'],
        'tab removal' => ["a\tb", 'ab'],
    ]);

    it('encodes hash with the fragment set', function (string $input, string $expected): void {
        expect(Canonicalizer::hash($input))->toBe($expected);
    })->with([
        'space' => ['a b', 'a%20b'],
        'quote is kept' => ["a'b", "a'b"],
        'double quote' => ['a"b', 'a%22b'],
        'hash is kept' => ['a#b', 'a#b'],
        'angle brackets' => ['a<b>', 'a%3Cb%3E'],
        'backtick' => ['a`b', 'a%60b'],
        'non-ASCII' => ['é', '%C3%A9'],
        'newline removal' => ["a\nb", 'ab'],
    ]);
});
