<?php

declare(strict_types=1);

use BenTools\UrlPattern\Exception\InvalidPatternException;
use BenTools\UrlPattern\Internal\Regex\EcmaScriptTranslator;
use BenTools\UrlPattern\Tests\Support\Pcre;

describe('EcmaScriptTranslator literals and escapes', function (): void {
    it('translates literals as hexadecimal escapes, except ASCII alphanumerics', function (): void {
        expect(EcmaScriptTranslator::translate('abc'))->toBe('abc')
            ->and(EcmaScriptTranslator::translate('a_b'))->toBe('a\\x{5F}b')
            ->and(EcmaScriptTranslator::translate('😀'))->toBe('\\x{1F600}')
            ->and(EcmaScriptTranslator::translate('é'))->toBe('\\x{E9}')
            ->and(EcmaScriptTranslator::translate('#'))->toBe('\\x{23}');
    });

    it('translates the dot, honoring line terminators', function (): void {
        expect(EcmaScriptTranslator::translate('a.b'))->toBe('a[^\\n\\r\\x{2028}\\x{2029}]b');
        Pcre::assertMatching('a.b', ['axb', 'a.b', 'a😀b'], ["a\nb", "a\rb", "a\u{2028}b", "a\u{2029}b", 'ab']);
    });

    it('translates class escapes', function (): void {
        Pcre::assertMatching('\\d\\w\\s\\S\\D\\W', ['1a b!#'], ['1a  !#', 'aa b!#', '1a b1#', '1a b!a']);
        Pcre::assertMatching('\\d', ['0', '9'], ['a', '']);
        Pcre::assertMatching('\\w', ['a', 'Z', '_', '5'], ['-', ' ']);
        Pcre::assertMatching('\\D', ['a'], ['1']);
        Pcre::assertMatching('\\W', ['-'], ['a']);
    });

    it('treats the ECMAScript white space and line terminators as \\s', function (): void {
        Pcre::assertMatching('\\s', [' ', "\t", "\n", "\u{A0}", "\u{FEFF}", "\u{2028}", "\u{3000}"], ['x', '']);
        Pcre::assertMatching('\\S', ['x'], [' ', "\u{A0}", "\u{FEFF}"]);
    });

    it('translates unicode properties', function (): void {
        Pcre::assertMatching('\\p{L}', ['a', 'é', 'α'], ['1', '-']);
        Pcre::assertMatching('\\P{L}', ['1', '-'], ['a', 'é']);
        Pcre::assertMatching('\\p{Lu}', ['A'], ['a']);
        Pcre::assertMatching('\\p{General_Category=Lu}', ['A'], ['a']);
        Pcre::assertMatching('\\p{gc=Lu}', ['A'], ['a']);
        Pcre::assertMatching('\\p{Script=Greek}', ['α', 'Ω'], ['a']);
        Pcre::assertMatching('\\p{sc=Latn}', ['a'], ['α']);
        Pcre::assertMatching('\\p{Script_Extensions=Latn}', ['a'], ['α']);
        Pcre::assertMatching('\\p{scx=Latn}', ['a'], ['α']);
        Pcre::assertMatching('\\p{ASCII}', ['a'], ['é']);
        expect(EcmaScriptTranslator::translate('\\p{Script=Greek}'))->toBe('[\\p{sc:Greek}]')
            ->and(EcmaScriptTranslator::translate('\\P{L}'))->toBe('[\\P{L}]');
    });

    it('translates control and character escapes', function (): void {
        expect(EcmaScriptTranslator::translate('\\f\\n\\r\\t\\v'))->toBe('\\x{C}\\x{A}\\x{D}\\x{9}\\x{B}');
        Pcre::assertMatching('\\cJ', ["\n"], ['J', 'j']);
        Pcre::assertMatching('\\cj', ["\n"], ['J']);
        Pcre::assertMatching('\\0', ["\0"], ['0']);
        Pcre::assertMatching('\\x41', ['A'], ['a']);
        Pcre::assertMatching('\\u0041', ['A'], ['a']);
        Pcre::assertMatching('\\u{1F600}', ['😀'], ['a']);
        Pcre::assertMatching('\\u{41}', ['A'], ['a']);
    });

    it('combines surrogate pairs into a single code point', function (): void {
        expect(EcmaScriptTranslator::translate('\\uD83D\\uDE00'))->toBe('\\x{1F600}');
        Pcre::assertMatching('\\uD83D\\uDE00', ['😀'], ['a']);
        Pcre::assertMatching('😀', ['😀'], ['a']);
        Pcre::assertMatching('😀+', ['😀😀'], ['']);
    });

    it('accepts identity escapes of syntax characters and slash', function (): void {
        Pcre::assertMatching('\\/', ['/'], ['a']);
        Pcre::assertMatching('\\.', ['.'], ['a']);
        Pcre::assertMatching('\\^\\$\\\\\\.\\*\\+\\?\\(\\)\\[\\]\\{\\}\\|', ['^$\\.*+?()[]{}|'], ['']);
    });

    it('rejects invalid escapes', function (string $pattern): void {
        expect(fn () => EcmaScriptTranslator::translate($pattern))->toThrow(InvalidPatternException::class);
    })->with([
        'unknown identity escape m' => ['\\m'],
        'unknown identity escape H' => ['\\H'],
        'unknown identity escape a' => ['\\a'],
        'escaped dash outside a class' => ['\\-'],
        'lone backslash' => ['\\'],
        'control letter missing' => ['\\c'],
        'control with a digit' => ['\\c1'],
        'decimal escape 00' => ['\\00'],
        'decimal escape 01' => ['\\01'],
        'short hexadecimal escape' => ['\\x4'],
        'invalid hexadecimal escape' => ['\\xZZ'],
        'short unicode escape' => ['\\u00'],
        'code point above U+10FFFF' => ['\\u{110000}'],
        'empty braced unicode escape' => ['\\u{}'],
        'unterminated braced unicode escape' => ['\\u{41'],
        'invalid braced unicode escape' => ['\\u{xyz}'],
        'lone high surrogate' => ['\\uD83D'],
        'lone low surrogate' => ['\\uDE00'],
        'high surrogate followed by text' => ['\\uD83Dx'],
        'high surrogate followed by a non-surrogate escape' => ['\\uD83D\\u0041'],
        'empty property' => ['\\p{}'],
        'unterminated property' => ['\\p{L'],
        'property without braces' => ['\\p'],
        'unknown property name with a value' => ['\\p{Foo=Bar}'],
        'property with an empty value' => ['\\p{Script=}'],
        'invalid property character' => ['\\p{L-}'],
        'backreference by number' => ['\\1'],
        'backreference by name' => ['\\k<x>'],
        'lone \\k' => ['\\k'],
    ]);

    it('rejects properties of strings', function (string $pattern): void {
        expect(fn () => EcmaScriptTranslator::translate($pattern))
            ->toThrow(InvalidPatternException::class, 'Properties of strings');
    })->with([['\\p{RGI_Emoji}'], ['\\p{Basic_Emoji}'], ['[\\p{RGI_Emoji}]']]);

    it('explains that backreferences are not supported', function (): void {
        expect(fn () => EcmaScriptTranslator::translate('(a)\\1'))
            ->toThrow(InvalidPatternException::class, 'Backreferences are not supported');
    });

    it('explains that lone surrogates are not supported', function (): void {
        expect(fn () => EcmaScriptTranslator::translate('\\uD83D'))
            ->toThrow(InvalidPatternException::class, 'Lone surrogates are not supported');
    });
});

describe('EcmaScriptTranslator groups', function (): void {
    it('translates non-capturing groups', function (): void {
        expect(EcmaScriptTranslator::translate('(?:a)'))->toBe('(?:a)');
        Pcre::assertMatching('(?:ab)+', ['ab', 'abab'], ['', 'aba']);
    });

    it('translates named groups into plain capturing groups', function (): void {
        expect(EcmaScriptTranslator::translate('(?<n>a)'))->toBe('(a)')
            ->and(EcmaScriptTranslator::translate('(?<é>a)(?<_b>b)'))->toBe('(a)(b)');
        preg_match('#^' . EcmaScriptTranslator::translate('(?<first>a)(b)(?<third>c)') . '$#uD', 'abc', $matches);
        expect($matches)->toBe(['abc', 'a', 'b', 'c']);
    });

    it('translates capturing groups and alternations', function (): void {
        expect(EcmaScriptTranslator::translate('(a)|(b)'))->toBe('(a)|(b)')
            ->and(EcmaScriptTranslator::translate('()'))->toBe('()');
        Pcre::assertMatching('a|b', ['a', 'b'], ['', 'ab']);
        Pcre::assertMatching('a||b', ['a', 'b', ''], ['ab']);
    });

    it('rejects duplicate and invalid group names', function (string $pattern): void {
        expect(fn () => EcmaScriptTranslator::translate($pattern))->toThrow(InvalidPatternException::class);
    })->with([
        'duplicate name' => ['(?<n>a)(?<n>b)'],
        'name starting with a digit' => ['(?<1a>a)'],
        'empty name' => ['(?<>a)'],
        'unterminated name' => ['(?<n'],
        'name with an invalid character' => ['(?<a-b>a)'],
    ]);

    it('translates pattern modifiers', function (): void {
        expect(EcmaScriptTranslator::translate('(?i:a)'))->toBe('(?i:a)')
            ->and(EcmaScriptTranslator::translate('(?is:a)'))->toBe('(?is:a)')
            ->and(EcmaScriptTranslator::translate('(?i-s:a)'))->toBe('(?i-s:a)')
            ->and(EcmaScriptTranslator::translate('(?i-:a)'))->toBe('(?i:a)');
        Pcre::assertMatching('(?i:a)b', ['ab', 'Ab'], ['AB', 'aB']);
        Pcre::assertMatching('(?i-m:a)', ['A'], ['b']);
    });

    it('tracks the dotAll modifier', function (): void {
        Pcre::assertMatching('.', ['a'], ["\n"]);
        Pcre::assertMatching('(?s:.)', ['a', "\n", "\u{2028}"], []);
        Pcre::assertMatching('(?-s:.)', ['a'], ["\n"]);
        Pcre::assertMatching('(?s:.(?-s:.))', ["\na", 'ab'], ["\n\n"]);
        Pcre::assertMatching('(?s:(?:.))', ["\n"], []);
        Pcre::assertMatching('(?s:.).', ["\na"], ["\n\n"]);
    });

    it('rejects invalid groups and modifiers', function (string $pattern): void {
        expect(fn () => EcmaScriptTranslator::translate($pattern))->toThrow(InvalidPatternException::class);
    })->with([
        'unknown modifier' => ['(?x:a)'],
        'repeated modifier' => ['(?ii:a)'],
        'empty modifiers with a dash' => ['(?-:a)'],
        'modifier both added and removed' => ['(?i-i:a)'],
        'repeated removed modifier' => ['(?-ii:a)'],
        'unterminated modifiers' => ['(?i'],
        'recursion' => ['(?R)'],
        'lone question mark' => ['(?'],
        'unterminated group' => ['(a'],
        'unmatched closing parenthesis' => ['a)'],
        'closing parenthesis only' => [')'],
    ]);

    it('translates lookarounds', function (): void {
        expect(EcmaScriptTranslator::translate('(?=a)'))->toBe('(?=a)')
            ->and(EcmaScriptTranslator::translate('(?!a)'))->toBe('(?!a)')
            ->and(EcmaScriptTranslator::translate('(?<=a)'))->toBe('(?<=a)')
            ->and(EcmaScriptTranslator::translate('(?<!a)'))->toBe('(?<!a)');
        Pcre::assertMatching('a(?=b)b', ['ab'], ['a']);
        Pcre::assertMatching('a(?!b)c', ['ac'], ['ab']);
        Pcre::assertMatching('a(?<=a)b', ['ab'], []);
        Pcre::assertMatching('a(?<!a)b', [], ['ab']);
        Pcre::assertMatching('a(?<!x)b', ['ab'], []);
    });

    it('rejects quantified lookarounds and assertions', function (string $pattern): void {
        expect(fn () => EcmaScriptTranslator::translate($pattern))
            ->toThrow(InvalidPatternException::class, 'nothing to repeat');
    })->with([
        'lookahead *' => ['(?=a)*'],
        'lookahead +' => ['(?=a)+'],
        'negative lookahead {n}' => ['(?!a){2}'],
        'lookbehind ?' => ['(?<=a)?'],
        'caret' => ['^*'],
        'dollar' => ['$+'],
        'word boundary' => ['\\b*'],
        'non word boundary' => ['\\B?'],
    ]);

    it('translates assertions', function (): void {
        expect(EcmaScriptTranslator::translate('^a$'))->toBe('^a$');
        Pcre::assertMatching('^a$', ['a'], ['b']);
        Pcre::assertMatching('\\ba', ['a'], []);
    });

    it('keeps \\d, \\w and \\b ASCII-only, like ECMAScript (PHP enables PCRE2_UCP with /u)', function (): void {
        Pcre::assertMatching('^\\d$', ['7'], ["\u{663}"]);
        Pcre::assertMatching('^\\D$', ["\u{663}"], ['7']);
        Pcre::assertMatching('^\\w$', ['a', '_'], ['é']);
        Pcre::assertMatching('^\\W$', ['é'], ['a']);
        Pcre::assertMatching('^[\\d\\w]$', ['7', 'a'], ["\u{663}", 'é']);
        Pcre::assertMatching('.*\\bfoo\\b.*', ['éfooé', 'a foo'], ['afoo']);
        Pcre::assertMatching('a\\B.*', ['ab'], ['a', 'a-']);
    });
});

describe('EcmaScriptTranslator quantifiers', function (): void {
    it('keeps quantifiers, greedy or lazy, unchanged', function (string $quantifier): void {
        expect(EcmaScriptTranslator::translate('a' . $quantifier))->toBe('a' . $quantifier);
    })->with(['*', '+', '?', '{2}', '{2,}', '{2,3}', '*?', '+?', '??', '{2}?', '{2,}?', '{2,3}?']);

    it('matches according to the quantifier', function (): void {
        Pcre::assertMatching('a*', ['', 'a', 'aaa'], ['b']);
        Pcre::assertMatching('a+', ['a', 'aaa'], ['', 'b']);
        Pcre::assertMatching('a?', ['', 'a'], ['aa']);
        Pcre::assertMatching('a{2}', ['aa'], ['a', 'aaa']);
        Pcre::assertMatching('a{2,}', ['aa', 'aaaa'], ['a']);
        Pcre::assertMatching('a{2,3}', ['aa', 'aaa'], ['a', 'aaaa']);
        Pcre::assertMatching('a{0}', [''], ['a']);
        Pcre::assertMatching('(?:ab){2}', ['abab'], ['ab']);
    });

    it('rejects invalid quantifiers', function (string $pattern, string $message): void {
        expect(fn () => EcmaScriptTranslator::translate($pattern))
            ->toThrow(InvalidPatternException::class, $message);
    })->with([
        'numbers out of order' => ['a{3,1}', 'numbers out of order'],
        'lone brace after an atom' => ['a{', 'lone quantifier brackets'],
        'non-numeric quantifier' => ['a{a}', 'lone quantifier brackets'],
        'missing minimum' => ['a{,1}', 'lone quantifier brackets'],
        'unterminated quantifier' => ['a{1', 'expected "}"'],
        'unterminated range quantifier' => ['a{1,', 'expected "}"'],
        'lone closing brace' => ['a}', 'lone "}"'],
        'lone closing bracket' => ['a]', 'lone "]"'],
        'brace alone' => ['{', 'lone "{"'],
        'closing brace alone' => ['}', 'lone "}"'],
        'closing bracket alone' => [']', 'lone "]"'],
        'quantifier first' => ['*a', 'nothing to repeat'],
        'plus first' => ['+a', 'nothing to repeat'],
        'question mark first' => ['?a', 'nothing to repeat'],
        'doubled star' => ['a**', 'nothing to repeat'],
        'star after plus' => ['a+*', 'nothing to repeat'],
        'lazy then plus' => ['a*?+', 'nothing to repeat'],
        'quantifier after an alternation' => ['a|*', 'nothing to repeat'],
    ]);
});

describe('EcmaScriptTranslator character classes', function (): void {
    it('translates plain classes and ranges', function (): void {
        expect(EcmaScriptTranslator::translate('[abc]'))->toBe('[abc]')
            ->and(EcmaScriptTranslator::translate('[a-z]'))->toBe('[a-z]')
            ->and(EcmaScriptTranslator::translate('[^abc]'))->toBe('[^abc]')
            ->and(EcmaScriptTranslator::translate('[\\x41-\\x5A]'))->toBe('[A-Z]')
            ->and(EcmaScriptTranslator::translate('[😀]'))->toBe('[\\x{1F600}]');
        Pcre::assertMatching('[abc]', ['a', 'c'], ['d', '']);
        Pcre::assertMatching('[a-c0-2]', ['b', '1'], ['d', '3']);
        Pcre::assertMatching('[^abc]', ['d', '😀'], ['a']);
        Pcre::assertMatching('[\\u{1F600}-\\u{1F64F}]', ['😀'], ['a']);
        Pcre::assertMatching('[a-c\\d_]', ['a', '5', '_'], ['-', 'd']);
        Pcre::assertMatching('[.*]', ['.', '*'], ['a']);
        Pcre::assertMatching('[a&b]', ['a', '&', 'b'], ['c']);
    });

    it('translates class escapes inside classes', function (): void {
        Pcre::assertMatching('[\\d\\s]', ['1', ' '], ['a']);
        Pcre::assertMatching('[\\S\\d]', ['a', '1'], [' ']);
        Pcre::assertMatching('[\\p{L}]', ['a'], ['1']);
        Pcre::assertMatching('[\\P{L}\\d]', ['1', '-'], ['a']);
        Pcre::assertMatching('[\\cJ]', ["\n"], ['J']);
        Pcre::assertMatching('[\\b]', ["\x08"], ['b']);
        Pcre::assertMatching('[\\-\\(\\&\\!]', ['-', '(', '&', '!'], ['a']);
    });

    it('translates empty classes', function (): void {
        expect(EcmaScriptTranslator::translate('[]'))->toBe('(?!)')
            ->and(EcmaScriptTranslator::translate('[^]'))->toBe('[\\s\\S]');
        Pcre::assertMatching('[]', [], ['', 'a']);
        Pcre::assertMatching('[^]', ['a', "\n", '😀'], ['']);
    });

    it('translates nested classes', function (): void {
        Pcre::assertMatching('[[a-c][x-z]]', ['a', 'y'], ['m']);
        Pcre::assertMatching('[[a-c]x]', ['a', 'x'], ['y']);
        Pcre::assertMatching('[[[a]]]', ['a'], ['b']);
        Pcre::assertMatching('[[^a]x]', ['b', 'x'], ['a']);
        Pcre::assertMatching('[^[a-c]x]', ['d'], ['a', 'x']);
        Pcre::assertMatching('[\\S[a-c]]', ['a', 'z'], [' ']);
        Pcre::assertMatching('[^\\S\\d]', [' '], ['a', '1']);
        Pcre::assertMatching('[^\\S]', [' ', "\t"], ['a']);
    });

    it('translates subtractions', function (): void {
        Pcre::assertMatching('[\\w--[aeiou]]', ['b', 'z', '1'], ['a', 'e', '-']);
        Pcre::assertMatching('[[a-z]--b--c]', ['a', 'd'], ['b', 'c', '1']);
        Pcre::assertMatching('[\\p{L}--[a-z]]', ['A', 'é'], ['a', '1']);
        Pcre::assertMatching('[\\s--\\n]', [' ', "\t"], ["\n"]);
        Pcre::assertMatching('[^\\w--[aeiou]]', ['a', '-'], ['b', '1']);
        Pcre::assertMatching('[[a-c]--[b]]', ['a', 'c'], ['b']);
        Pcre::assertMatching('[[^a]--[b]]', ['c'], ['a', 'b']);
    });

    it('translates intersections', function (): void {
        Pcre::assertMatching('[\\w&&[a-f]]', ['a', 'f'], ['g', '-']);
        Pcre::assertMatching('[\\w&&\\d]', ['1'], ['a']);
        Pcre::assertMatching('[\\w&&[a-f]&&[c-z]]', ['c', 'f'], ['a', 'g']);
        Pcre::assertMatching('[^\\w&&[a-f]]', ['g', '-'], ['a', 'f']);
        Pcre::assertMatching('[[\\S]&&a]', ['a'], ['b', ' ']);
    });

    it('translates class string disjunctions in unions', function (): void {
        expect(EcmaScriptTranslator::translate('[\\q{abc|d}]'))->toBe('(?:abc|[d])')
            ->and(EcmaScriptTranslator::translate('[\\q{a}]'))->toBe('[a]')
            ->and(EcmaScriptTranslator::translate('[\\q{ab|abc|a}z]'))->toBe('(?:abc|ab|[za])');
        Pcre::assertMatching('[\\q{abc|d}]', ['abc', 'd'], ['ab', 'a', '']);
        Pcre::assertMatching('[\\q{abc|d}x]', ['abc', 'd', 'x'], ['ab']);
        Pcre::assertMatching('[\\q{ab}\\q{cd}]', ['ab', 'cd'], ['abcd']);
        Pcre::assertMatching('[\\q{abc}\\d]', ['abc', '1'], ['a']);
        Pcre::assertMatching('[\\q{}]', [''], ['a']);
        Pcre::assertMatching('[\\q{|}]', [''], ['a']);
        Pcre::assertMatching('[\\q{a\\b}]', ["a\x08"], ['ab']);
        Pcre::assertMatching('[\\q{a\\|b}]', ['a|b'], ['a', 'b']);
        Pcre::assertMatching('[^\\q{a|b}]', ['c'], ['a', 'b']);
        Pcre::assertMatching('[\\q{ab|abc|a}z]', ['abc', 'ab', 'a', 'z'], ['b', 'abcz']);
    });

    it('prefers the longest string of a class string disjunction', function (): void {
        preg_match('#^' . EcmaScriptTranslator::translate('[\\q{a|ab|abc}]') . '#uD', 'abcd', $matches);
        expect($matches)->toBe(['abc']);
    });

    it('rejects invalid classes', function (string $pattern): void {
        expect(fn () => EcmaScriptTranslator::translate($pattern))->toThrow(InvalidPatternException::class);
    })->with([
        'reversed range' => ['[z-a]'],
        'range ending with a class escape' => ['[a-\\d]'],
        'range starting with a class escape' => ['[\\d-z]'],
        'dangling dash at the end' => ['[a-]'],
        'dangling dash at the start' => ['[-a]'],
        'chained ranges' => ['[a-b-c]'],
        'range subtraction' => ['[a-z--[aeiou]]'],
        'chained range subtraction' => ['[a-z--b--c]'],
        'negated range subtraction' => ['[^a-z--[aeiou]]'],
        'mixed intersection and subtraction' => ['[a-z&&b--c]'],
        'subtraction then intersection' => ['[a--b&&c]'],
        'intersection then subtraction' => ['[a&&b--c]'],
        'triple ampersand' => ['[a&&&b]'],
        'reserved double punctuator !!' => ['[!!]'],
        'reserved double punctuator inside a union' => ['[a!!b]'],
        'reserved double punctuator &&' => ['[&&]'],
        'dangling intersection' => ['[a-z&&]'],
        'dangling subtraction' => ['[a--]'],
        'leading subtraction' => ['[--a]'],
        'unescaped opening parenthesis' => ['[(]'],
        'unescaped opening parenthesis after a member' => ['[a(b]'],
        'unescaped closing parenthesis' => ['[)]'],
        'unescaped opening brace' => ['[{]'],
        'unescaped closing brace' => ['[}]'],
        'unescaped slash' => ['[/]'],
        'unescaped pipe' => ['[|]'],
        'unknown escape' => ['[\\m]'],
        'non word boundary in a class' => ['[\\B]'],
        'unterminated class' => ['[a'],
        'unterminated negated class' => ['[^'],
        'opening bracket alone' => ['['],
        'strings in a negated class' => ['[^\\q{abc}]'],
        'strings in an intersection (left)' => ['[\\q{abc}&&a]'],
        'strings in an intersection (right)' => ['[a&&\\q{abc}]'],
        'strings in a subtraction (left)' => ['[\\q{abc}--a]'],
        'strings in a subtraction (right)' => ['[a--\\q{abc}]'],
        'syntax character in a string disjunction' => ['[\\q{a(b}]'],
        'unterminated string disjunction' => ['[\\q{ab'],
        'property of strings in a class' => ['[\\p{RGI_Emoji}]'],
    ]);

    it('rejects strings in a negated class with an explicit message', function (): void {
        expect(fn () => EcmaScriptTranslator::translate('[^\\q{abc}]'))
            ->toThrow(InvalidPatternException::class, 'negated character class may contain strings');
    });

    it('explains that class strings only work in unions', function (string $pattern): void {
        expect(fn () => EcmaScriptTranslator::translate($pattern))
            ->toThrow(InvalidPatternException::class, 'only supported in character class unions');
    })->with([['[\\q{abc}&&a]'], ['[a&&\\q{abc}]'], ['[a--\\q{abc}]'], ['[\\q{abc}--a]']]);
});

describe('EcmaScriptTranslator linear-time guard', function (): void {
    it('rejects nested unbounded quantifiers', function (string $pattern): void {
        expect(fn () => EcmaScriptTranslator::translate($pattern, linearTimeGuard: true))
            ->toThrow(InvalidPatternException::class, 'linear-time guard');
    })->with([
        ['(a+)+'],
        ['(?:a*)*'],
        ['(a+){2,}'],
        ['(.*)*'],
        ['(?:(?:a+)b)+'],
        ['(?:a+){2}'],
        ['(?:a+){1,}?'],
        ['(a*b)*'],
        ['[a-z]+(a*)*'],
    ]);

    it('accepts patterns without nested unbounded quantifiers', function (string $pattern): void {
        expect(EcmaScriptTranslator::translate($pattern, linearTimeGuard: true))->toBe(
            EcmaScriptTranslator::translate($pattern),
        );
    })->with([
        ['(a+)'],
        ['(a{1,3})+'],
        ['(a?)+'],
        ['(a|b)*'],
        ['(?:a+)?'],
        ['(?:a+){1}'],
        ['(?:a+){0,1}'],
        ['(?=a+)'],
        ['(?<=a*)b'],
    ]);

    it('only applies when requested', function (): void {
        expect(EcmaScriptTranslator::translate('(a+)+'))->toBe('(a+)+');
    });
});
