<?php

declare(strict_types=1);

use BenTools\UrlPattern\Exception\InvalidPatternException;
use BenTools\UrlPattern\Internal\Parser\Options;
use BenTools\UrlPattern\Internal\Parser\PatternParser;
use BenTools\UrlPattern\Tests\Support\Dump;

$identity = static fn (string $value): string => $value;

describe('PatternParser', function () use ($identity): void {
    it('parses a pattern string into parts', function (
        string $input,
        bool $pathname,
        array $expected,
    ) use ($identity): void {
        $options = $pathname ? Options::pathname(false) : Options::default();

        expect(Dump::parts(PatternParser::parse($input, $options, $identity)))->toBe($expected);
    })->with([
        // "Type:name:value:modifier:prefix:suffix"
        'named group with the pathname prefix' => ['/:foo', true, ['SegmentWildcard:foo::None:/:']],
        'named group without prefix' => [':foo', false, ['SegmentWildcard:foo::None::']],
        'wildcard' => ['/*', true, ['FullWildcard:0::None:/:']],
        'regexp group' => ['/(\\d+)', true, ['Regexp:0:\\d+:None:/:']],
        'regexp group named' => ['/:id(\\d+)?', true, ['Regexp:id:\\d+:Optional:/:']],
        'regexp equal to the full wildcard' => ['/:a(.*)', true, ['FullWildcard:a::None:/:']],
        'regexp equal to the segment wildcard' => [':a([^]+?)', false, ['SegmentWildcard:a::None::']],
        'optional modifier' => ['/:id?', true, ['SegmentWildcard:id::Optional:/:']],
        'zero or more modifier' => ['/:id*', true, ['SegmentWildcard:id::ZeroOrMore:/:']],
        'one or more modifier' => ['/:id+', true, ['SegmentWildcard:id::OneOrMore:/:']],
        'fixed text only' => ['/foo/bar', true, ['FixedText::/foo/bar:None::']],
        'escaped character' => ['foo\\:bar', false, ['FixedText::foo:bar:None::']],
        'fixed text then group' => [
            '/books{/:id}?',
            true,
            ['FixedText::/books:None::', 'SegmentWildcard:id::Optional:/:'],
        ],
        'group with only fixed text and a modifier' => ['{ab}?', false, ['FixedText::ab:Optional::']],
        'group with only fixed text' => ['{ab}', false, ['FixedText::ab:None::']],
        'group with a suffix' => ['{:a-}*', false, ['SegmentWildcard:a::ZeroOrMore::-']],
        'group with a prefix and a name' => ['{x:a}', false, ['SegmentWildcard:a::None:x:']],
        'group with a prefix and no name' => ['{-:a}', false, ['SegmentWildcard:a::None:-:']],
        'empty group' => ['{}?', false, []],
        'group with a lone prefix and a modifier' => ['{/}?', true, ['FixedText::/:Optional::']],
        'wildcard in a group' => ['/{*}', true, ['FixedText::/:None::', 'FullWildcard:0::None::']],
        'numbered names are sequential' => ['(a)(b)', false, ['Regexp:0:a:None::', 'Regexp:1:b:None::']],
        'prefix other than the prefix code point is fixed text' => [
            '/a-:b',
            true,
            ['FixedText::/a-:None::', 'SegmentWildcard:b::None::'],
        ],
        'adjacent groups' => ['/:a(x)(y)', true, ['Regexp:a:x:None:/:', 'Regexp:0:y:None::']],
    ]);

    it('applies the encoding callback to fixed text, prefixes and suffixes', function (): void {
        $upper = static fn (string $value): string => strtoupper($value);

        expect(Dump::parts(PatternParser::parse('/a{/b:c-}?', Options::pathname(false), $upper)))->toBe([
            'FixedText::/A:None::',
            'SegmentWildcard:c::Optional:/B:-',
        ]);
    });

    it('rejects invalid pattern strings', function (string $input): void {
        expect(fn () => PatternParser::parse($input, Options::default(), static fn (string $v): string => $v))
            ->toThrow(InvalidPatternException::class);
    })->with([
        'duplicate group names' => [':a:a'],
        'duplicate group names with regexps' => [':a(x):a(y)'],
        'missing close brace' => ['{a'],
        'unexpected close brace' => ['a}'],
        'tokenizer error' => ['(a)(?:b)'],
        'missing close brace after a group' => ['{:a'],
    ]);

    it('parses patterns with many groups in linear time', function () use ($identity): void {
        // Patterns may come from untrusted clients: a quadratic duplicate name check took ~3 s here.
        $start = hrtime(true);
        $parts = PatternParser::parse(str_repeat('/*', 16_000), Options::pathname(false), $identity);
        $elapsedMilliseconds = (hrtime(true) - $start) / 1_000_000;

        expect($parts)->toHaveCount(16_000)
            ->and($elapsedMilliseconds)->toBeLessThan(1_000.0);
    });
});
