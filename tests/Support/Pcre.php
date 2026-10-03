<?php

declare(strict_types=1);

namespace BenTools\UrlPattern\Tests\Support;

use BenTools\UrlPattern\Internal\Regex\EcmaScriptTranslator;

use function expect;
use function json_encode;
use function preg_match;

final class Pcre
{
    /**
     * Asserts that each accepted subject fully matches the translated pattern and each rejected one does not.
     *
     * @param list<string> $accepted
     * @param list<string> $rejected
     */
    public static function assertMatching(string $pattern, array $accepted, array $rejected): void
    {
        foreach ($accepted as $subject) {
            expect(self::matches($pattern, $subject))
                ->toBeTrue("'{$pattern}' should match " . json_encode($subject));
        }
        foreach ($rejected as $subject) {
            expect(self::matches($pattern, $subject))
                ->toBeFalse("'{$pattern}' should not match " . json_encode($subject));
        }
    }

    /**
     * Translates an ECMAScript regexp, then tells whether the whole subject matches it.
     */
    public static function matches(string $ecmaScriptPattern, string $subject): bool
    {
        $pcre = EcmaScriptTranslator::translate($ecmaScriptPattern);

        return 1 === preg_match('#^(?:' . $pcre . ')$#uD', $subject);
    }
}
