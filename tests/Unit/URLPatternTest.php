<?php

declare(strict_types=1);

use BenTools\UrlPattern\Exception\InvalidPatternException;
use BenTools\UrlPattern\Tests\Support\Untyped;
use BenTools\UrlPattern\URLPattern;
use BenTools\UrlPattern\URLPatternComponentResult;
use BenTools\UrlPattern\URLPatternResult;

describe('URLPattern construction', function (): void {
    it('compiles a constructor string', function (): void {
        $pattern = new URLPattern('https://example.com/books/:id');

        expect($pattern->protocol)->toBe('https')
            ->and($pattern->username)->toBe('*')
            ->and($pattern->password)->toBe('*')
            ->and($pattern->hostname)->toBe('example.com')
            ->and($pattern->port)->toBe('')
            ->and($pattern->pathname)->toBe('/books/:id')
            ->and($pattern->search)->toBe('*')
            ->and($pattern->hash)->toBe('*');
    });

    it('compiles a constructor string relative to a base URL', function (): void {
        $pattern = new URLPattern('/books/:id#top', 'https://example.com:8080/a/b');

        expect($pattern->protocol)->toBe('https')
            ->and($pattern->hostname)->toBe('example.com')
            ->and($pattern->port)->toBe('8080')
            ->and($pattern->pathname)->toBe('/books/:id')
            ->and($pattern->search)->toBe('')
            ->and($pattern->hash)->toBe('top');
    });

    it('compiles an init array', function (): void {
        $pattern = new URLPattern(['protocol' => 'https', 'hostname' => '*.example.com', 'pathname' => '/a/*']);

        expect($pattern->protocol)->toBe('https')
            ->and($pattern->hostname)->toBe('*.example.com')
            ->and($pattern->port)->toBe('*')
            ->and($pattern->pathname)->toBe('/a/*');
    });

    it('matches everything by default', function (): void {
        $pattern = new URLPattern();

        expect($pattern->pathname)->toBe('*')
            ->and($pattern->test('https://example.com/anything?q#h'))->toBeTrue();
    });

    it('drops the default port of the protocol', function (): void {
        expect((new URLPattern('https://example.com:443/'))->port)->toBe('')
            ->and((new URLPattern('http://example.com:443/'))->port)->toBe('443')
            ->and((new URLPattern(['protocol' => 'http', 'port' => '80']))->port)->toBe('');
    });

    it('uses opaque path canonicalization for non-special protocols', function (): void {
        expect((new URLPattern(['protocol' => 'foo', 'pathname' => 'a b']))->pathname)->toBe('a b')
            ->and((new URLPattern(['protocol' => 'https', 'pathname' => '/a b']))->pathname)->toBe('/a%20b');
    });

    it('detects IPv6 hostname patterns', function (string $hostname, string $expected): void {
        expect((new URLPattern(['hostname' => $hostname]))->hostname)->toBe($expected);
    })->with([
        // The IPv6 callback only lowercases: an uncompressed address is not normalized.
        'brackets' => ['[0\\:0\\:0\\:0\\:0\\:0\\:0\\:AB]', '[0\\:0\\:0\\:0\\:0\\:0\\:0\\:ab]'],
        'group around brackets' => ['{[0\\:0\\:0\\:0\\:0\\:0\\:0\\:AB]}', '[0\\:0\\:0\\:0\\:0\\:0\\:0\\:ab]'],
        'escaped bracket' => ['\\[0\\:0\\:0\\:0\\:0\\:0\\:0\\:AB]', '[0\\:0\\:0\\:0\\:0\\:0\\:0\\:ab]'],
        'regular hostname' => ['EXAMPLE.com', 'example.com'],
        'single character' => ['a', 'a'],
    ]);

    it('rejects invalid patterns', function (callable $factory, string $message): void {
        expect($factory)->toThrow(InvalidPatternException::class, $message);
    })->with([
        'relative string without base URL' => [
            fn () => new URLPattern('/books/:id'),
            'requires a base URL',
        ],
        'init array with a base URL' => [
            fn () => new URLPattern(['pathname' => '/x'], 'https://example.com'),
            'base URL cannot be passed',
        ],
        'invalid base URL' => [
            fn () => new URLPattern('/x', 'http://['),
            'Invalid base URL',
        ],
        'invalid regexp' => [
            fn () => new URLPattern(['pathname' => '/(\\m)']),
            'Invalid regular expression',
        ],
        'duplicate group names' => [
            fn () => new URLPattern(['pathname' => '/:a/:a']),
            'duplicate group name',
        ],
        'invalid protocol' => [
            fn () => new URLPattern(['protocol' => '1a']),
            'Invalid protocol',
        ],
        'invalid port' => [
            fn () => new URLPattern(['protocol' => 'https', 'port' => '99999']),
            'Invalid port',
        ],
    ]);

    it('rejects regexps that the translator accepts but PCRE cannot compile', function (): void {
        // PCRE only supports bounded lookbehinds. Its compilation warning is silenced by the library,
        // but PHPUnit would still report it: swallow it here.
        set_error_handler(static fn (): bool => true);

        try {
            expect(fn () => new URLPattern(['pathname' => '/((?<=a*)b)']))
                ->toThrow(InvalidPatternException::class, 'Invalid regular expression');
        } finally {
            restore_error_handler();
        }
    });

    it('rejects non-string init values', function (mixed $value): void {
        expect(fn () => Untyped::instantiate(URLPattern::class, ['pathname' => $value]))
            ->toThrow(InvalidPatternException::class, 'must be a string');
    })->with([
        'int' => [1],
        'null' => [null],
        'array' => [['x']],
        'bool' => [true],
    ]);

    it('scrubs invalid UTF-8 from init values', function (): void {
        // The invalid byte becomes U+FFFD, like a WebIDL USVString.
        $pattern = new URLPattern(['pathname' => "/:a\xFF"]);

        expect($pattern->pathname)->toBe('/:a%EF%BF%BD');
    });

    it('scrubs invalid UTF-8 from constructor strings', function (): void {
        $pattern = new URLPattern("https://example.com/\xFF*");

        expect($pattern->pathname)->toBe('/%EF%BF%BD*');
    });

    it('is a readonly class', function (): void {
        expect((new ReflectionClass(URLPattern::class))->isReadOnly())->toBeTrue()
            ->and((new ReflectionClass(URLPatternResult::class))->isReadOnly())->toBeTrue()
            ->and((new ReflectionClass(URLPatternComponentResult::class))->isReadOnly())->toBeTrue();
    });

    it('reports regexp groups', function (string $input, ?string $baseURL, bool $expected): void {
        expect((new URLPattern($input, $baseURL))->hasRegExpGroups)->toBe($expected);
    })->with([
        'no groups' => ['https://example.com/a', null, false],
        'named group' => ['https://example.com/a/:b', null, false],
        'wildcard' => ['https://example.com/a/*', null, false],
        'regexp group in the pathname' => ['https://example.com/a/(\\d+)', null, true],
        'regexp group relative to a base' => ['/a/(\\d+)', 'https://example.com', true],
        'regexp group in the hostname' => ['https://(a|b).example.com/', null, true],
        'named regexp group in the hash' => ['https://example.com/#:h(\\d+)', null, true],
        'segment wildcard written explicitly is not a regexp group' => [
            'https://example.com/:a([^\\/]+?)',
            null,
            false,
        ],
    ]);

    it('honors the ignoreCase option', function (): void {
        $sensitive = new URLPattern(['pathname' => '/ABC']);
        $insensitive = new URLPattern(['pathname' => '/ABC'], null, ['ignoreCase' => true]);

        expect($sensitive->test(['pathname' => '/abc']))->toBeFalse()
            ->and($insensitive->test(['pathname' => '/abc']))->toBeTrue()
            ->and($insensitive->test('https://example.com/Abc'))->toBeTrue();
    });
});

describe('URLPattern::exec()', function (): void {
    it('matches a URL string', function (): void {
        $result = (new URLPattern('https://example.com/books/:id'))->exec('https://example.com/books/42');

        expect($result)->toBeInstanceOf(URLPatternResult::class)
            ->and($result?->inputs)->toBe(['https://example.com/books/42'])
            ->and($result?->protocol->input)->toBe('https')
            ->and($result?->hostname->input)->toBe('example.com')
            ->and($result?->pathname->input)->toBe('/books/42')
            ->and($result?->pathname->groups)->toBe(['id' => '42']);
    });

    it('matches a URL string with a base URL', function (): void {
        $pattern = new URLPattern('/books/:id', 'https://example.com');
        $result = $pattern->exec('/books/7?x=1#f', 'https://example.com');

        expect($result?->inputs)->toBe(['/books/7?x=1#f', 'https://example.com'])
            ->and($result?->pathname->groups)->toBe(['id' => '7'])
            ->and($result?->search->input)->toBe('x=1')
            ->and($result?->hash->input)->toBe('f');
    });

    it('matches an init array', function (): void {
        $result = (new URLPattern(['pathname' => '/books/:id']))->exec(['pathname' => '/books/1']);

        expect($result?->inputs)->toBe([['pathname' => '/books/1']])
            ->and($result?->pathname->groups)->toBe(['id' => '1'])
            ->and($result?->hostname->input)->toBe('');
    });

    it('returns null when the input does not match', function (): void {
        $pattern = new URLPattern('https://example.com/books/:id');

        expect($pattern->exec('https://example.com/authors/1'))->toBeNull()
            ->and($pattern->exec('http://example.com/books/1'))->toBeNull()
            ->and($pattern->exec(['pathname' => '/books/']))->toBeNull();
    });

    it('exposes numbered groups with integer keys', function (): void {
        $pattern = new URLPattern(['protocol' => 'https', 'hostname' => 'example.com', 'pathname' => '/a/*/(\\d+)']);
        $result = $pattern->exec('https://example.com/a/b/c/12');

        expect($result?->pathname->groups)->toBe([0 => 'b/c', 1 => '12']);
    });

    it('exposes an empty group for components without a pattern group', function (): void {
        $result = (new URLPattern())->exec('https://example.com/p?q=1#h');

        expect($result?->username->groups)->toBe([0 => ''])
            ->and($result?->search->groups)->toBe([0 => 'q=1'])
            ->and($result?->hash->groups)->toBe([0 => 'h'])
            ->and($result?->protocol->groups)->toBe([0 => 'https']);
    });

    it('uses null for non-participating optional groups', function (): void {
        $pattern = new URLPattern(['pathname' => '/:a/:b?/(\\d+)?']);
        $result = $pattern->exec(['pathname' => '/x']);

        expect($result?->pathname->groups)->toBe(['a' => 'x', 'b' => null, 0 => null]);
    });

    it('fills participating optional groups', function (): void {
        $pattern = new URLPattern(['pathname' => '/:a/:b?']);

        expect($pattern->exec(['pathname' => '/x/y'])?->pathname->groups)->toBe(['a' => 'x', 'b' => 'y']);
    });

    it('normalizes the URL before matching', function (): void {
        $pattern = new URLPattern(['hostname' => 'example.com', 'pathname' => '/a/b']);
        $result = $pattern->exec('HTTPS://EXAMPLE.com/a/./c/../b');

        expect($result?->hostname->input)->toBe('example.com')
            ->and($result?->pathname->input)->toBe('/a/b');
    });

    it('scrubs invalid UTF-8 input instead of failing', function (): void {
        $pattern = new URLPattern('https://example.com/*');

        $result = $pattern->exec("https://example.com/a\xFFb");

        expect($result?->pathname->input)->toBe('/a%EF%BF%BDb');
    });

    it('scrubs invalid UTF-8 in init values', function (): void {
        $result = (new URLPattern(['pathname' => '/a*']))->exec(['pathname' => "/a\xFF"]);

        expect($result?->pathname->input)->toBe('/a%EF%BF%BD')
            ->and($result?->inputs)->toBe([['pathname' => "/a\u{FFFD}"]]);
    });

    it('scrubs invalid UTF-8 in the base URL', function (): void {
        $pattern = new URLPattern('/a', 'https://example.com');

        expect($pattern->exec('/a', "https://example.com/\xFF")?->inputs)->toBe(['/a', "https://example.com/\u{FFFD}"]);
    });

    it('throws when a base URL is passed along an init array', function (): void {
        $pattern = new URLPattern();

        expect(fn () => $pattern->exec(['pathname' => '/x'], 'https://example.com'))
            ->toThrow(InvalidArgumentException::class, 'base URL cannot be passed')
            ->and(fn () => $pattern->test(['pathname' => '/x'], 'https://example.com'))
            ->toThrow(InvalidArgumentException::class);
    });

    it('returns null for non-string init values', function (mixed $value): void {
        $pattern = new URLPattern();

        expect(Untyped::call($pattern, 'exec', ['pathname' => $value]))->toBeNull()
            ->and(Untyped::call($pattern, 'test', ['pathname' => $value]))->toBeFalse();
    })->with([
        'int' => [1],
        'null' => [null],
        'array' => [['x']],
    ]);

    it('returns null for an invalid init value', function (): void {
        $pattern = new URLPattern();

        expect($pattern->exec(['protocol' => '1a']))->toBeNull()
            ->and($pattern->exec(['port' => '99999']))->toBeNull()
            ->and($pattern->exec(['hostname' => 'a b']))->toBeNull();
    });
});

describe('URLPattern::test()', function (): void {
    it('is a boolean shortcut of exec()', function (): void {
        $pattern = new URLPattern('https://example.com/books/:id');

        expect($pattern->test('https://example.com/books/1'))->toBeTrue()
            ->and($pattern->test('https://example.com/books/'))->toBeFalse()
            ->and($pattern->test(['protocol' => 'https', 'hostname' => 'example.com', 'pathname' => '/books/1']))
            ->toBeTrue();
    });

    it('never throws on garbage input', function (string $garbage): void {
        $pattern = new URLPattern();

        expect($pattern->test($garbage))->toBeFalse()
            ->and($pattern->exec($garbage))->toBeNull();
    })->with([
        'relative string' => ['foo'],
        'empty string' => [''],
        'unclosed IPv6 host' => ['http://['],
        'empty host' => ['https://'],
        'invalid port' => ['https://example.com:99999/'],
    ]);

    it('never throws on an invalid base URL', function (): void {
        $pattern = new URLPattern();

        expect($pattern->test('/x', 'http://['))->toBeFalse()
            ->and($pattern->exec('/x', 'http://['))->toBeNull()
            ->and($pattern->test('/x', 'not a url'))->toBeFalse();
    });
});

it('converts invalid UTF-8 like a USVString, with U+FFFD', function (): void {
    $pattern = new URLPattern(['pathname' => "/a\xFFb"]);

    expect($pattern->pathname)->toBe('/a%EF%BF%BDb')
        ->and($pattern->test("https://example.com/a\xFFb"))->toBeTrue()
        ->and($pattern->test("https://example.com/a\u{FFFD}b"))->toBeTrue()
        ->and(mb_substitute_character())->toBe(63);
});

it('lowercases percent-decoded hosts of special URLs, but not opaque hosts', function (): void {
    expect((new URLPattern(['hostname' => 'a.com']))->test('https://%41.com/'))->toBeTrue()
        ->and((new URLPattern(['protocol' => 'foo']))->exec('foo://A%41.com/')?->hostname->input)->toBe('A%41.com')
        ->and((new URLPattern(['pathname' => '/x', 'baseURL' => 'https://%41.com/']))->hostname)->toBe('a.com');
});

it('percent-encodes "^" in non-opaque paths, but not in opaque paths', function (): void {
    $pattern = new URLPattern('https://example.com/a^b');

    expect($pattern->pathname)->toBe('/a%5Eb')
        ->and($pattern->test('https://example.com/a^b'))->toBeTrue()
        ->and($pattern->test(['protocol' => 'https', 'hostname' => 'example.com', 'pathname' => '/a^b']))->toBeTrue()
        ->and((new URLPattern(['protocol' => 'foo']))->exec('foo://h/a^b')?->pathname->input)->toBe('/a%5Eb')
        ->and((new URLPattern(['protocol' => 'foo']))->exec('foo:a^b')?->pathname->input)->toBe('a^b')
        ->and((new URLPattern(['search' => 'x', 'baseURL' => 'https://example.com/a^b']))->pathname)->toBe('/a%5Eb')
        ->and((new URLPattern(['pathname' => 'c', 'baseURL' => 'https://example.com/a^b/']))->pathname)
        ->toBe('/a%5Eb/c');
});
