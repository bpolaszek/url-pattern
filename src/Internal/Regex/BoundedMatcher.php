<?php

declare(strict_types=1);

namespace BenTools\UrlPattern\Internal\Regex;

use Closure;

use function ini_get;
use function ini_set;
use function preg_match;

use const PREG_UNMATCHED_AS_NULL;

/**
 * Runs PCRE matches with a bounded amount of work, so that a hostile pattern cannot freeze
 * the process (ReDoS). The limit is applied to `pcre.backtrack_limit` (PCRE2's match limit,
 * which is also enforced by the JIT) for the duration of a callback, then restored.
 *
 * @internal
 */
final class BoundedMatcher
{
    public const DEFAULT_BACKTRACK_LIMIT = 100_000;

    /**
     * @template T
     *
     * @param Closure(): T $callback
     *
     * @return T
     */
    public static function withBacktrackLimit(int $backtrackLimit, Closure $callback): mixed
    {
        $previousLimit = ini_get('pcre.backtrack_limit');
        // When ini_set() is disabled, the process-wide limit applies.
        ini_set('pcre.backtrack_limit', (string) $backtrackLimit);
        try {
            return $callback();
        } finally {
            if (false !== $previousLimit) {
                ini_set('pcre.backtrack_limit', $previousLimit);
            }
        }
    }

    /**
     * @return array<int|string, ?string>|null the captures, or null on no match or on any PCRE error
     */
    public static function match(string $regex, string $subject): ?array
    {
        if (1 !== preg_match($regex, $subject, $matches, PREG_UNMATCHED_AS_NULL)) {
            return null;
        }

        return $matches;
    }
}
