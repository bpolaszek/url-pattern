<?php

declare(strict_types=1);

namespace BenTools\UrlPattern;

/**
 * The result of a successful URLPattern::exec() call.
 */
final readonly class URLPatternResult
{
    /**
     * @internal results are only created by {@see URLPattern::exec()}
     *
     * @param list<string|array<string, string>> $inputs the arguments the pattern was matched against,
     *                                                    converted like WebIDL USVStrings
     */
    public function __construct(
        public array $inputs,
        public URLPatternComponentResult $protocol,
        public URLPatternComponentResult $username,
        public URLPatternComponentResult $password,
        public URLPatternComponentResult $hostname,
        public URLPatternComponentResult $port,
        public URLPatternComponentResult $pathname,
        public URLPatternComponentResult $search,
        public URLPatternComponentResult $hash,
    ) {
    }
}
