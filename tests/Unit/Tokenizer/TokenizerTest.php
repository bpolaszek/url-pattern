<?php

declare(strict_types=1);

use BenTools\UrlPattern\Exception\InvalidPatternException;
use BenTools\UrlPattern\Internal\Tokenizer\Tokenizer;
use BenTools\UrlPattern\Internal\Tokenizer\TokenizePolicy;
use BenTools\UrlPattern\Tests\Support\Dump;

describe('Tokenizer', function (): void {
    it('tokenizes a representative pattern', function (): void {
        expect(Dump::tokens('/:foo(bar)?*{x}\\.'))->toBe([
            'Char@0=/',
            'Name@1=foo',
            'Regexp@5=bar',
            'OtherModifier@10=?',
            'Asterisk@11=*',
            'Open@12={',
            'Char@13=x',
            'Close@14=}',
            'EscapedChar@15=.',
            'End@17=',
        ]);
    });

    it('always ends with an End token', function (): void {
        expect(Dump::tokens(''))->toBe(['End@0=']);
    });

    it('tokenizes the "+" modifier like the "?" modifier', function (): void {
        expect(Dump::tokens('a+'))->toBe(['Char@0=a', 'OtherModifier@1=+', 'End@2=']);
    });

    it('indexes tokens by code point, not by byte', function (): void {
        expect(Dump::tokens('é:x'))->toBe(['Char@0=é', 'Name@1=x', 'End@3=']);
    });

    it('accepts non-ASCII code points in names', function (): void {
        expect(Dump::tokens(':é'))->toBe(['Name@0=é', 'End@2=']);
    });

    it('keeps nested non-capturing groups inside a regexp token', function (): void {
        expect(Dump::tokens('(a(?:b))'))->toBe(['Regexp@0=a(?:b)', 'End@8=']);
    });

    it('keeps escaped parentheses inside a regexp token', function (): void {
        expect(Dump::tokens('(a\\))'))->toBe(['Regexp@0=a\\)', 'End@5=']);
    });

    it('rejects invalid patterns with the strict policy', function (string $input): void {
        expect(fn () => Tokenizer::tokenize($input, TokenizePolicy::Strict))
            ->toThrow(InvalidPatternException::class);
    })->with([
        'trailing backslash' => ['\\'],
        'colon without name' => [':'],
        'colon followed by a digit' => [':1'],
        'non-ASCII in regexp' => ['(é)'],
        'regexp starting with ?' => ['(?a)'],
        'unbalanced regexp' => ['(a'],
        'empty regexp' => ['()'],
        'capturing group in regexp' => ['((a))'],
        'trailing backslash in regexp' => ['(a\\'],
        'escaped non-ASCII in regexp' => ['(a\\é'],
        'open parenthesis at the very end of a regexp' => ['(a('],
    ]);

    it('reports the faulty index in the exception message', function (): void {
        expect(fn () => Tokenizer::tokenize('ab:', TokenizePolicy::Strict))
            ->toThrow(InvalidPatternException::class, 'index 2');
    });

    it('emits InvalidChar tokens with the lenient policy', function (string $input, array $expected): void {
        expect(Dump::tokens($input, TokenizePolicy::Lenient))->toBe($expected);
    })->with([
        'trailing backslash' => ['\\', ['InvalidChar@0=\\', 'End@1=']],
        'colon without name' => [':', ['InvalidChar@0=:', 'End@1=']],
        'colon followed by a digit' => [':1', ['InvalidChar@0=:', 'Char@1=1', 'End@2=']],
        'non-ASCII in regexp' => ['(é)', ['InvalidChar@0=(', 'Char@1=é', 'Char@2=)', 'End@3=']],
        'regexp starting with ?' => [
            '(?a)',
            ['InvalidChar@0=(', 'OtherModifier@1=?', 'Char@2=a', 'Char@3=)', 'End@4='],
        ],
        'unbalanced regexp' => ['(a', ['InvalidChar@0=(', 'Char@1=a', 'End@2=']],
        'empty regexp' => ['()', ['InvalidChar@0=(', 'Char@1=)', 'End@2=']],
        'capturing group in regexp' => ['((a))', ['InvalidChar@0=(', 'Regexp@1=a', 'Char@4=)', 'End@5=']],
        'trailing backslash in regexp' => ['(a\\', ['InvalidChar@0=(', 'Char@1=a', 'InvalidChar@2=\\', 'End@3=']],
        'escaped non-ASCII in regexp' => ['(a\\é', ['InvalidChar@0=(', 'Char@1=a', 'EscapedChar@2=é', 'End@4=']],
        'open parenthesis at the very end of a regexp' => [
            '(a(',
            ['InvalidChar@0=(', 'Char@1=a', 'InvalidChar@2=(', 'End@3='],
        ],
    ]);
});
