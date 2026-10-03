<?php

declare(strict_types=1);

use BenTools\UrlPattern\Exception\InvalidPatternException;
use BenTools\UrlPattern\Internal\Regex\BoundedMatcher;
use BenTools\UrlPattern\URLPattern;

describe('ReDoS protection', function (): void {
    it('rejects regexp groups with nested unbounded quantifiers', function (string $pathname): void {
        expect(fn () => new URLPattern(['pathname' => $pathname]))
            ->toThrow(InvalidPatternException::class, 'linear-time guard');
    })->with([
        'URLPattern + modifier on a repeating regexp' => ['/(a+)+'],
        'URLPattern * modifier on a repeating regexp' => ['/(a*)*'],
        'URLPattern * modifier on a one-or-more regexp' => ['/(a+)*'],
        'nested non-capturing groups' => ['/((?:a*)*)'],
        'quantified group with an open upper bound' => ['/((?:a+){2,})'],
        'quantified group with a lower bound' => ['/((?:a+){2})'],
    ]);

    it('accepts safe regexp groups', function (string $pathname): void {
        expect(new URLPattern(['pathname' => $pathname]))->toBeInstanceOf(URLPattern::class);
    })->with([
        'single repeating regexp' => ['/(a+)'],
        'optional repeating regexp' => ['/(a+)?'],
        'named optional regexp' => ['/:x(a*)?'],
        'bounded repetition' => ['/((?:ab){1,3})'],
        'full wildcard with a URLPattern modifier' => ['/(.*)*'],
        'segment wildcards' => ['/:a*/:b+'],
    ]);

    it('stops catastrophic backtracking at the default limit', function (): void {
        // The subject contains the required "b", so PCRE has to backtrack through the wildcards.
        $input = ['pathname' => '/' . str_repeat('a', 30) . 'bc'];
        $pattern = new URLPattern(['pathname' => '/*a*a*a*a*b']);

        expect($pattern->test($input))->toBeFalse()
            ->and(preg_last_error())->toBe(PREG_BACKTRACK_LIMIT_ERROR)
            ->and($pattern->exec($input))->toBeNull()
            ->and(preg_last_error())->toBe(PREG_BACKTRACK_LIMIT_ERROR);

        // A plain no match under a high enough limit, which proves the default limit was the one exhausted.
        $relaxed = new URLPattern(['pathname' => '/*a*a*a*a*b'], null, ['backtrackLimit' => 100_000_000]);
        expect($relaxed->test($input))->toBeFalse()
            ->and(preg_last_error())->toBe(PREG_NO_ERROR);
    });

    it('restores pcre.backtrack_limit after matching', function (): void {
        $before = ini_get('pcre.backtrack_limit');
        $pattern = new URLPattern(['pathname' => '/*a*a*a'], null, ['backtrackLimit' => 10]);

        $pattern->test(['pathname' => '/' . str_repeat('a', 50)]);
        expect(ini_get('pcre.backtrack_limit'))->toBe($before);

        // Also when the pattern is compiled (the special scheme check matches under a bounded limit).
        new URLPattern('https://example.com/*');
        expect(ini_get('pcre.backtrack_limit'))->toBe($before);
    });

    it('restores pcre.backtrack_limit when the callback throws', function (): void {
        $before = ini_get('pcre.backtrack_limit');

        expect(fn () => BoundedMatcher::withBacktrackLimit(5, static fn () => throw new RuntimeException('boom')))
            ->toThrow(RuntimeException::class, 'boom');
        expect(ini_get('pcre.backtrack_limit'))->toBe($before);
    });

    it('applies the limit while the callback runs', function (): void {
        expect(BoundedMatcher::withBacktrackLimit(5, static fn () => ini_get('pcre.backtrack_limit')))->toBe('5');
    });

    it('treats a PCRE error as no match', function (): void {
        $limited = BoundedMatcher::withBacktrackLimit(
            1,
            // The subject contains the required "b", so the match cannot fail before backtracking.
            static fn (): array => [
                BoundedMatcher::match('#^(?:a+)+b$#uD', str_repeat('a', 30) . 'b!'),
                preg_last_error(),
            ],
        );

        expect($limited)->toBe([null, PREG_BACKTRACK_LIMIT_ERROR])
            ->and(BoundedMatcher::match('#^(a)(b)?$#uD', 'a'))->toBe(['a', 'a', null]);
    });

    it('honors the backtrackLimit option', function (): void {
        $input = ['pathname' => '/' . str_repeat('a', 50)];

        $unlimited = new URLPattern(['pathname' => '/*a*a*a']);
        $limited = new URLPattern(['pathname' => '/*a*a*a'], null, ['backtrackLimit' => 10]);

        expect($unlimited->test($input))->toBeTrue()
            ->and($limited->test($input))->toBeFalse();
    });

    it('validates the backtrackLimit option', function (mixed $limit): void {
        expect(fn () => new URLPattern([], null, ['backtrackLimit' => $limit]))
            ->toThrow(InvalidPatternException::class, 'backtrackLimit');
    })->with([
        'zero' => [0],
        'negative' => [-1],
        'string' => ['100'],
        'float' => [1.5],
        'bool' => [true],
    ]);

    it('accepts a positive integer backtrackLimit', function (): void {
        expect(new URLPattern([], null, ['backtrackLimit' => 1]))->toBeInstanceOf(URLPattern::class);
    });

    it('validates the ignoreCase option', function (mixed $ignoreCase): void {
        expect(fn () => new URLPattern([], null, ['ignoreCase' => $ignoreCase]))
            ->toThrow(InvalidPatternException::class, 'ignoreCase');
    })->with([
        'string' => ['yes'],
        'int' => [1],
        'array' => [[]],
    ]);

    it('accepts a boolean ignoreCase', function (bool $ignoreCase): void {
        expect(new URLPattern([], null, ['ignoreCase' => $ignoreCase]))->toBeInstanceOf(URLPattern::class);
    })->with([true, false]);
});
