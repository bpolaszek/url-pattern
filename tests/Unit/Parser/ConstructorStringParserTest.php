<?php

declare(strict_types=1);

use BenTools\UrlPattern\Internal\Parser\ConstructorStringParser;

describe('ConstructorStringParser', function (): void {
    it('splits a constructor string into URLPatternInit components', function (string $input, array $expected): void {
        expect(ConstructorStringParser::parse($input))->toBe($expected);
    })->with([
        'full URL' => [
            'https://example.com/books/:id#frag',
            [
                'protocol' => 'https',
                'hostname' => 'example.com',
                'pathname' => '/books/:id',
                'search' => '',
                'hash' => 'frag',
                'port' => '',
            ],
        ],
        'full URL with a search' => [
            'https://a.com/p?x#y',
            [
                'protocol' => 'https',
                'hostname' => 'a.com',
                'pathname' => '/p',
                'search' => 'x',
                'hash' => 'y',
                'port' => '',
            ],
        ],
        'full URL without a pathname' => [
            'https://example.com',
            ['protocol' => 'https', 'hostname' => 'example.com', 'port' => ''],
        ],
        'search directly after the hostname gets a default pathname' => [
            'https://a.com?x',
            ['protocol' => 'https', 'hostname' => 'a.com', 'pathname' => '/', 'search' => 'x', 'port' => ''],
        ],
        'hash directly after the hostname gets a default pathname and search' => [
            'https://a.com#x',
            [
                'protocol' => 'https',
                'hostname' => 'a.com',
                'pathname' => '/',
                'search' => '',
                'hash' => 'x',
                'port' => '',
            ],
        ],
        'empty search and hash markers' => [
            'https://a.com/p?#',
            [
                'protocol' => 'https',
                'hostname' => 'a.com',
                'pathname' => '/p',
                'search' => '',
                'hash' => '',
                'port' => '',
            ],
        ],
        'relative pathname' => ['/books/:id', ['pathname' => '/books/:id']],
        'relative pathname without leading slash' => ['example.com', ['pathname' => 'example.com']],
        'empty string' => ['', ['pathname' => '']],
        'relative search' => ['?q=:x', ['search' => 'q=:x']],
        'relative hash' => ['#frag', ['hash' => 'frag']],
        'hash containing a question mark' => ['#a?b', ['hash' => 'a?b']],
        'search then hash' => ['?a#b', ['search' => 'a', 'hash' => 'b']],
        'pathname then search' => ['/a?b', ['pathname' => '/a', 'search' => 'b']],
        'pathname then hash' => ['/a#b', ['pathname' => '/a', 'search' => '', 'hash' => 'b']],
        'a question mark after a group is a modifier' => ['/p/:a?q', ['pathname' => '/p/:a?q']],
        'a question mark after a regexp group is a modifier' => [
            'https://example.com/p(a?b)',
            ['protocol' => 'https', 'hostname' => 'example.com', 'pathname' => '/p(a?b)', 'port' => ''],
        ],
        'a hash inside a regexp group is not a delimiter' => [
            'https://example.com/(a#b)',
            ['protocol' => 'https', 'hostname' => 'example.com', 'pathname' => '/(a#b)', 'port' => ''],
        ],
        'escaped search delimiter' => [
            'https://a.com/\\?x',
            ['protocol' => 'https', 'hostname' => 'a.com', 'pathname' => '/', 'search' => 'x', 'port' => ''],
        ],
        'protocol only' => ['https:', ['protocol' => 'https', 'hostname' => '', 'port' => '']],
        'protocol wildcard' => ['*://*/*', ['protocol' => '*', 'hostname' => '*', 'pathname' => '/*', 'port' => '']],
        'protocol without slashes is a pathname' => ['data:text/plain,hello', ['pathname' => 'data:text/plain,hello']],
        'non-special protocol with an authority' => [
            'blob:https://a.com/x',
            ['protocol' => 'blob:https', 'hostname' => 'a.com', 'pathname' => '/x', 'port' => ''],
        ],
        'file URL with an empty hostname' => [
            'file:///x',
            ['protocol' => 'file', 'hostname' => '', 'pathname' => '/x', 'port' => ''],
        ],
        'username' => [
            'https://alice@host',
            ['protocol' => 'https', 'username' => 'alice', 'hostname' => 'host', 'port' => ''],
        ],
        'empty username' => [
            'https://@a.com',
            ['protocol' => 'https', 'username' => '', 'hostname' => 'a.com', 'port' => ''],
        ],
        'username and password' => [
            'https://alice\\:secret@host',
            ['protocol' => 'https', 'username' => 'alice', 'password' => 'secret', 'hostname' => 'host', 'port' => ''],
        ],
        'username and empty password' => [
            'https://alice:@host',
            ['protocol' => 'https', 'username' => 'alice', 'password' => '', 'hostname' => 'host', 'port' => ''],
        ],
        'named groups as username and password' => [
            'https://:user\\:x@host',
            ['protocol' => 'https', 'username' => ':user', 'password' => 'x', 'hostname' => 'host', 'port' => ''],
        ],
        'port' => [
            'https://example.com:8080/x',
            ['protocol' => 'https', 'hostname' => 'example.com', 'port' => '8080', 'pathname' => '/x'],
        ],
        'port wildcard' => [
            'https://example.com:*',
            ['protocol' => 'https', 'hostname' => 'example.com', 'port' => '*'],
        ],
        'port in a group' => [
            'https://example.com:{80}/',
            ['protocol' => 'https', 'hostname' => 'example.com', 'port' => '{80}', 'pathname' => '/'],
        ],
        'IPv6 hostname' => [
            'https://[::1]/x',
            ['protocol' => 'https', 'hostname' => '[::1]', 'pathname' => '/x', 'port' => ''],
        ],
        'IPv6 hostname and port' => [
            'https://[::1]:8080/',
            ['protocol' => 'https', 'hostname' => '[::1]', 'port' => '8080', 'pathname' => '/'],
        ],
        'IPv6 hostname as a pattern' => [
            'https://[:a]',
            ['protocol' => 'https', 'hostname' => '[:a]', 'port' => ''],
        ],
        'group spanning a hostname delimiter' => [
            'https://{a.com/}b',
            ['protocol' => 'https', 'hostname' => '{a.com/}b', 'port' => ''],
        ],
        'group spanning the pathname delimiter' => [
            'https://example.com/{:a/}?x',
            ['protocol' => 'https', 'hostname' => 'example.com', 'pathname' => '/{:a/}?x', 'port' => ''],
        ],
        'group around the whole URL' => ['{https://a.com}/x', ['pathname' => '{https://a.com}/x']],
        'unclosed group' => [
            'https://a.com/x{y',
            ['protocol' => 'https', 'hostname' => 'a.com', 'pathname' => '/x{y', 'port' => ''],
        ],
        'regexp in a hostname' => [
            'https://ex(a.b)mple.com/',
            ['protocol' => 'https', 'hostname' => 'ex(a.b)mple.com', 'pathname' => '/', 'port' => ''],
        ],
    ]);

    it('defaults the port to an empty string when a hostname is present', function (): void {
        expect(ConstructorStringParser::parse('https://example.com/x'))->toHaveKey('port', '');
        expect(ConstructorStringParser::parse('/x'))->not->toHaveKey('port');
    });
});
