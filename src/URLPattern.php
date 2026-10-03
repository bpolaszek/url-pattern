<?php

declare(strict_types=1);

namespace BenTools\UrlPattern;

use BenTools\UrlPattern\Exception\InvalidPatternException;
use BenTools\UrlPattern\Internal\Canonicalizer;
use BenTools\UrlPattern\Internal\CodePoints;
use BenTools\UrlPattern\Internal\Component;
use BenTools\UrlPattern\Internal\InitProcessor;
use BenTools\UrlPattern\Internal\Parser\ConstructorStringParser;
use BenTools\UrlPattern\Internal\Parser\Options;
use BenTools\UrlPattern\Internal\Regex\BoundedMatcher;
use BenTools\UrlPattern\Internal\SpecialScheme;
use InvalidArgumentException;
use Uri\WhatWg\Url;

use function array_fill_keys;
use function is_bool;
use function is_int;
use function is_string;

/**
 * A WHATWG URL pattern.
 *
 * Instances are immutable: the pattern is compiled once, in the constructor, and can be shared freely.
 *
 * @see https://urlpattern.spec.whatwg.org/
 */
final readonly class URLPattern
{
    public string $protocol;
    public string $username;
    public string $password;
    public string $hostname;
    public string $port;
    public string $pathname;
    public string $search;
    public string $hash;
    public bool $hasRegExpGroups;

    /** @var array<string, Component> */
    private array $components;
    private int $backtrackLimit;

    /**
     * @param string|array<string, string> $input a constructor string (e.g. "https://example.com/books/:id")
     *                                            or a URLPatternInit array of component patterns
     * @param string|null $baseURL a base URL, only allowed with a constructor string
     * @param array<string, mixed> $options supported keys:
     *                                      - ignoreCase (bool): case-insensitive matching, defaults to false
     *                                      - backtrackLimit (positive int): maximum PCRE work per match
     *                                        (ReDoS protection)
     *
     * @throws InvalidPatternException when the pattern is invalid
     */
    public function __construct(string|array $input = [], ?string $baseURL = null, array $options = [])
    {
        $ignoreCase = $options['ignoreCase'] ?? false;
        $backtrackLimit = $options['backtrackLimit'] ?? BoundedMatcher::DEFAULT_BACKTRACK_LIMIT;
        if (!is_bool($ignoreCase)) {
            throw new InvalidPatternException('The "ignoreCase" option must be a boolean.');
        }
        if (!is_int($backtrackLimit) || $backtrackLimit < 1) {
            throw new InvalidPatternException('The "backtrackLimit" option must be a positive integer.');
        }
        $this->backtrackLimit = $backtrackLimit;

        if (is_string($input)) {
            $init = ConstructorStringParser::parse(CodePoints::scrub($input));
            if (null === $baseURL && !isset($init['protocol'])) {
                throw new InvalidPatternException('A relative pattern string requires a base URL.');
            }
            if (null !== $baseURL) {
                $init['baseURL'] = $baseURL;
            }
        } else {
            if (null !== $baseURL) {
                throw new InvalidPatternException('A base URL cannot be passed along a URLPatternInit array.');
            }
            $init = $input;
        }

        $processedInit = InitProcessor::process($init, true) + array_fill_keys(InitProcessor::COMPONENTS, '*');

        $defaultPort = SpecialScheme::defaultPort($processedInit['protocol']);
        if (null !== $defaultPort && (string) $defaultPort === $processedInit['port']) {
            $processedInit['port'] = '';
        }

        $protocol = Component::compile($processedInit['protocol'], Canonicalizer::protocol(...), Options::default());
        $hostnameCallback = self::isIPv6Hostname($processedInit['hostname'])
            ? Canonicalizer::ipv6Hostname(...)
            : Canonicalizer::hostname(...);

        if ($protocol->matchesSpecialScheme()) {
            $pathnameComponent = Component::compile(
                $processedInit['pathname'],
                Canonicalizer::pathname(...),
                Options::pathname($ignoreCase),
            );
        } else {
            $pathnameComponent = Component::compile(
                $processedInit['pathname'],
                Canonicalizer::opaquePathname(...),
                Options::default($ignoreCase),
            );
        }

        $defaultOptions = Options::default();
        $caseOptions = Options::default($ignoreCase);

        $this->components = [
            'protocol' => $protocol,
            'username' => Component::compile($processedInit['username'], Canonicalizer::username(...), $defaultOptions),
            'password' => Component::compile($processedInit['password'], Canonicalizer::password(...), $defaultOptions),
            'hostname' => Component::compile($processedInit['hostname'], $hostnameCallback, Options::hostname()),
            'port' => Component::compile($processedInit['port'], Canonicalizer::port(...), $defaultOptions),
            'pathname' => $pathnameComponent,
            'search' => Component::compile($processedInit['search'], Canonicalizer::search(...), $caseOptions),
            'hash' => Component::compile($processedInit['hash'], Canonicalizer::hash(...), $caseOptions),
        ];

        $this->protocol = $this->components['protocol']->patternString;
        $this->username = $this->components['username']->patternString;
        $this->password = $this->components['password']->patternString;
        $this->hostname = $this->components['hostname']->patternString;
        $this->port = $this->components['port']->patternString;
        $this->pathname = $this->components['pathname']->patternString;
        $this->search = $this->components['search']->patternString;
        $this->hash = $this->components['hash']->patternString;

        $hasRegExpGroups = false;
        foreach ($this->components as $component) {
            $hasRegExpGroups = $hasRegExpGroups || $component->hasRegExpGroups;
        }
        $this->hasRegExpGroups = $hasRegExpGroups;
    }

    /**
     * Never throws on an invalid URL: it simply does not match.
     *
     * @param string|array<string, string> $input a URL string, or a URLPatternInit array of component values
     *
     * @throws InvalidArgumentException when a base URL is passed along a URLPatternInit array
     */
    public function test(string|array $input = [], ?string $baseURL = null): bool
    {
        return null !== $this->exec($input, $baseURL);
    }

    /**
     * Never throws on an invalid URL: it simply does not match.
     *
     * @param string|array<string, string> $input a URL string, or a URLPatternInit array of component values
     *
     * @throws InvalidArgumentException when a base URL is passed along a URLPatternInit array
     */
    public function exec(string|array $input = [], ?string $baseURL = null): ?URLPatternResult
    {
        // Inputs are converted like WebIDL USVStrings before anything else, so that the
        // "inputs" of the result echo the converted values, like in JavaScript.
        if (is_string($input)) {
            $input = CodePoints::scrub($input);
            $inputs = [$input];
            $base = null;
            if (null !== $baseURL) {
                $baseURL = CodePoints::scrub($baseURL);
                $base = Url::parse($baseURL);
                if (null === $base) {
                    return null;
                }
                $inputs[] = $baseURL;
            }
            $url = Url::parse($input, $base);
            if (null === $url) {
                return null;
            }
            $values = [
                'protocol' => $url->getScheme(),
                'username' => (string) $url->getUsername(),
                'password' => (string) $url->getPassword(),
                'hostname' => Canonicalizer::serializeHost($url),
                'port' => (string) $url->getPort(),
                'pathname' => Canonicalizer::serializePath($url),
                'search' => (string) $url->getQuery(),
                'hash' => (string) $url->getFragment(),
            ];
        } else {
            if (null !== $baseURL) {
                throw new InvalidArgumentException('A base URL cannot be passed along a URLPatternInit array.');
            }
            try {
                $input = InitProcessor::normalize($input);
                $values = InitProcessor::process($input, false, array_fill_keys(InitProcessor::COMPONENTS, ''));
            } catch (InvalidPatternException) {
                return null;
            }
            $inputs = [$input];
        }

        $groups = BoundedMatcher::withBacktrackLimit($this->backtrackLimit, function () use ($values): ?array {
            $groups = [];
            foreach ($this->components as $name => $component) {
                $componentGroups = $component->match($values[$name]);
                if (null === $componentGroups) {
                    return null;
                }
                $groups[$name] = $componentGroups;
            }

            return $groups;
        });
        if (null === $groups) {
            return null;
        }

        $results = [];
        foreach ($groups as $name => $componentGroups) {
            $results[$name] = new URLPatternComponentResult($values[$name], $componentGroups);
        }

        return new URLPatternResult($inputs, ...$results);
    }

    /**
     * @see https://urlpattern.spec.whatwg.org/#hostname-pattern-is-an-ipv6-address
     */
    private static function isIPv6Hostname(string $input): bool
    {
        return \strlen($input) >= 2 && ('[' === $input[0] || \in_array(\substr($input, 0, 2), ['{[', '\\['], true));
    }
}
