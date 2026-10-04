<?php

declare(strict_types=1);

use BenTools\UrlPattern\Internal\Parser\Options;
use BenTools\UrlPattern\Internal\Parser\PatternParser;
use BenTools\UrlPattern\Internal\Parser\PatternStringGenerator;

describe('PatternStringGenerator', function (): void {
    it('generates the normalized pattern string', function (string $input, bool $pathname, string $expected): void {
        $options = $pathname ? Options::pathname(false) : Options::default();
        $parts = PatternParser::parse($input, $options, static fn (string $value): string => $value);

        expect(PatternStringGenerator::generate($parts, $options))->toBe($expected);
    })->with([
        'empty' => ['', false, ''],
        'empty group' => ['{}?', false, ''],
        'named group' => ['/:foo', true, '/:foo'],
        'named group without prefix' => [':foo', false, ':foo'],
        'wildcard' => ['/*', true, '/*'],
        'wildcard without prefix' => ['*', false, '*'],
        'optional wildcard' => ['{*}?', false, '*?'],
        'regexp' => ['/(\\d+)', true, '/(\\d+)'],
        'named regexp with a modifier' => ['/:id(\\d+)?', true, '/:id(\\d+)?'],
        'regexp equal to the full wildcard, numbered, first' => ['/(.*)', true, '/*'],
        'regexp equal to the full wildcard, named' => ['/:a(.*)', true, '/:a(.*)'],
        'full wildcard preceded by a modified group' => ['/:a*(.*)', true, '/:a**'],
        'segment wildcard written as a regexp' => ['(:a)', false, '(:a)'],
        'modifiers' => ['/:a?/:b*/:c+', true, '/:a?/:b*/:c+'],
        'fixed text escapes special characters' => ['foo\\:bar', false, 'foo\\:bar'],
        'fixed text with a modifier' => ['{ab}?', false, '{ab}?'],
        'fixed text only' => ['/foo/bar', true, '/foo/bar'],
        'prefix handled by a group' => ['/books{/:id}?', true, '/books/:id?'],
        'prefix different from the prefix code point' => ['{x:a}', false, '{x:a}'],
        'suffix' => ['{:a-}*', false, '{:a-}*'],
        'suffix starting like a name needs escaping' => ['{:a\\b}', false, '{:a\\b}'],
        'segment wildcard followed by name-like text' => ['{:a}b', false, '{:a}b'],
        'segment wildcard followed by a digit' => ['{:a}1', false, '{:a}1'],
        'segment wildcard followed by another group' => ['{:a}(\\d+)', false, '{:a}(\\d+)'],
        'segment wildcard followed by a named group' => [':a:b', false, ':a:b'],
        'segment wildcard followed by a numbered group' => [':a(b)', false, ':a(b)'],
        'group needed after fixed text ending with the prefix' => ['/{:a}b', true, '/{:a}b'],
        'group needed after the prefix code point' => ['/:a{1}', true, '{/:a}1'],
        'wildcard after fixed text ending with the prefix' => ['/{*}', true, '/{*}'],
        'prefix and wildcard in a middle position' => ['/a/*/b', true, '/a/*/b'],
        'consecutive wildcards' => ['/*/*', true, '/*/*'],
    ]);

    it('produces a pattern string that parses back to the same string', function (string $input): void {
        $options = Options::pathname(false);
        $identity = static fn (string $value): string => $value;
        $generated = PatternStringGenerator::generate(PatternParser::parse($input, $options, $identity), $options);

        expect(PatternStringGenerator::generate(PatternParser::parse($generated, $options, $identity), $options))
            ->toBe($generated);
    })->with([
        ['/:a(x)(y)'],
        ['/books{/:id}*'],
        ['/:a*x'],
        ['/{:a}b'],
        ['/:a{1}'],
        ['{/:a-}?'],
        ['/*'],
        ['/:foo(.*)?'],
    ]);

    it('escapes the pattern special characters', function (): void {
        expect(PatternStringGenerator::escapePatternString('+*?:{}()\\a/'))
            ->toBe('\\+\\*\\?\\:\\{\\}\\(\\)\\\\a/');
    });
});
