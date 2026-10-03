<?php

declare(strict_types=1);

use BenTools\UrlPattern\Internal\Parser\Options;
use BenTools\UrlPattern\Internal\Parser\PatternParser;
use BenTools\UrlPattern\Internal\Parser\RegexpGenerator;

describe('RegexpGenerator', function (): void {
    it('generates the ECMAScript regexp and the name list', function (
        string $input,
        bool $pathname,
        string $expectedRegexp,
        array $expectedNames,
    ): void {
        $options = $pathname ? Options::pathname(false) : Options::default();
        $parts = PatternParser::parse($input, $options, static fn (string $value): string => $value);

        expect(RegexpGenerator::generate($parts, $options))->toBe([$expectedRegexp, $expectedNames]);
    })->with([
        'empty' => ['', false, '^$', []],
        'fixed text is escaped' => ['/foo/bar', true, '^\\/foo\\/bar$', []],
        'fixed text with a modifier' => ['{ab}?', false, '^(?:ab)?$', []],
        'segment wildcard, no modifier' => ['/:foo', true, '^(?:\\/([^\\/]+?))$', ['foo']],
        'segment wildcard without prefix' => [':foo', false, '^([^]+?)$', ['foo']],
        'full wildcard' => ['*', false, '^(.*)$', ['0']],
        'regexp without prefix or suffix' => ['(\\d+)', false, '^(\\d+)$', ['0']],
        'optional without prefix or suffix' => [':a?', false, '^([^]+?)?$', ['a']],
        'repeating without prefix or suffix' => [':a+', false, '^((?:[^]+?)+)$', ['a']],
        'zero or more without prefix or suffix' => [':a*', false, '^((?:[^]+?)*)$', ['a']],
        'optional with a prefix' => ['/:id?', true, '^(?:\\/([^\\/]+?))?$', ['id']],
        'one or more with a prefix' => [
            '/:id+',
            true,
            '^(?:\\/((?:[^\\/]+?)(?:\\/(?:[^\\/]+?))*))$',
            ['id'],
        ],
        'zero or more with a prefix' => [
            '/:id*',
            true,
            '^(?:\\/((?:[^\\/]+?)(?:\\/(?:[^\\/]+?))*))?$',
            ['id'],
        ],
        'optional with a suffix' => ['{:a-}?', false, '^(?:([^]+?)-)?$', ['a']],
        'one or more with a suffix' => ['{:a-}+', false, '^(?:((?:[^]+?)(?:-(?:[^]+?))*)-)$', ['a']],
        'zero or more with a suffix' => ['{:a-}*', false, '^(?:((?:[^]+?)(?:-(?:[^]+?))*)-)?$', ['a']],
        'several groups keep their order' => [
            '/:foo/:bar',
            true,
            '^(?:\\/([^\\/]+?))(?:\\/([^\\/]+?))$',
            ['foo', 'bar'],
        ],
    ]);

    it('escapes the regexp special characters', function (): void {
        expect(RegexpGenerator::escapeRegexpString('.+*?^${}()[]|/\\a-'))
            ->toBe('\\.\\+\\*\\?\\^\\$\\{\\}\\(\\)\\[\\]\\|\\/\\\\a-');
    });
});
