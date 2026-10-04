<?php

declare(strict_types=1);

namespace BenTools\UrlPattern;

/**
 * The match result of a single URL component.
 */
final readonly class URLPatternComponentResult
{
    /**
     * @internal results are only created by {@see URLPattern::exec()}
     *
     * @param string $input the component value that was matched
     * @param array<int|string, ?string> $groups captured groups, by name. Anonymous groups are numbered
     *                                           from 0 (and therefore exposed with integer keys); a group
     *                                           that did not participate in the match is null.
     */
    public function __construct(
        public string $input,
        public array $groups,
    ) {
    }
}
