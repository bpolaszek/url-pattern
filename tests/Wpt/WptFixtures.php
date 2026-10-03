<?php

declare(strict_types=1);

namespace BenTools\UrlPattern\Tests\Wpt;

use Uri\WhatWg\Url;

use function array_key_exists;
use function file_get_contents;
use function in_array;
use function is_array;
use function is_string;
use function json_decode;
use function preg_replace_callback;
use function strval;

use const JSON_THROW_ON_ERROR;

/**
 * Loads the WPT fixtures and reproduces the expectation-filling logic of
 * `urlpattern/resources/urlpatterntests.js`.
 */
final class WptFixtures
{
    public const COMPONENTS = ['protocol', 'username', 'password', 'hostname', 'port', 'pathname', 'search', 'hash'];

    /**
     * WPT cases knowingly not supported, indexed by position in the fixture file, with the reason.
     *
     * @var array<int, string>
     */
    public const SKIPPED_CASES = [];

    private const EARLIER_COMPONENTS = [
        'protocol' => [],
        'hostname' => ['protocol'],
        'port' => ['protocol', 'hostname'],
        'username' => [],
        'password' => [],
        'pathname' => ['protocol', 'hostname', 'port'],
        'search' => ['protocol', 'hostname', 'port', 'pathname'],
        'hash' => ['protocol', 'hostname', 'port', 'pathname', 'search'],
    ];

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function load(): array
    {
        $json = (string) file_get_contents(__DIR__ . '/../Fixtures/urlpatterntestdata.json');

        // USVString conversion: lone surrogates become U+FFFD, valid pairs are kept.
        $json = (string) preg_replace_callback(
            '/(\\\\u[dD][89abAB][0-9a-fA-F]{2}\\\\u[dD][c-fC-F][0-9a-fA-F]{2})|\\\\u[dD][89a-fA-F][0-9a-fA-F]{2}/',
            static fn (array $match): string => $match[1] ?? '\\uFFFD',
            $json,
        );

        /** @var array<int, array<string, mixed>> $cases */
        $cases = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        return $cases;
    }

    /**
     * @param array<int, string> $skippedCases
     */
    public static function skipReason(int $index, array $skippedCases = self::SKIPPED_CASES): ?string
    {
        return $skippedCases[$index] ?? null;
    }

    /**
     * Splits the constructor arguments of a fixture case into input, base URL and options.
     *
     * @param list<mixed> $args
     *
     * @return array{0: string|array<string, string>, 1: ?string, 2: array<string, mixed>}
     */
    public static function constructorArgs(array $args): array
    {
        /** @var string|array<string, string> $input */
        $input = $args[0] ?? [];
        $baseURL = null;
        $options = [];
        if (isset($args[1]) && is_string($args[1])) {
            $baseURL = $args[1];
            /** @var array<string, mixed> $options */
            $options = $args[2] ?? [];
        } elseif (isset($args[1]) && is_array($args[1])) {
            /** @var array<string, mixed> $options */
            $options = $args[1];
        }

        return [$input, $baseURL, $options];
    }

    /**
     * Computes the expected pattern string of a component when the fixture does not provide it.
     *
     * @param array<mixed> $case
     */
    public static function expectedComponent(array $case, string $component): string
    {
        $expectedObj = is_array($case['expected_obj'] ?? null) ? $case['expected_obj'] : [];
        if (isset($expectedObj[$component]) && is_string($expectedObj[$component])) {
            return $expectedObj[$component];
        }

        /** @var list<mixed> $pattern */
        $pattern = $case['pattern'];
        $init = $pattern[0] ?? null;
        $baseURL = null;
        if (is_array($init) && isset($init['baseURL']) && is_string($init['baseURL'])) {
            $baseURL = Url::parse($init['baseURL']);
        } elseif (isset($pattern[1]) && is_string($pattern[1])) {
            $baseURL = Url::parse($pattern[1]);
        }

        /** @var list<string> $emptyComponents */
        $emptyComponents = $case['exactly_empty_components'] ?? [];
        if (in_array($component, $emptyComponents, true)) {
            return '';
        }
        if (is_array($init) && isset($init[$component]) && is_string($init[$component]) && '' !== $init[$component]) {
            return $init[$component];
        }
        if (is_array($init)) {
            foreach (self::EARLIER_COMPONENTS[$component] as $earlier) {
                if (array_key_exists($earlier, $init)) {
                    return '*';
                }
            }
        }
        if (null !== $baseURL && 'username' !== $component && 'password' !== $component) {
            return match ($component) {
                'protocol' => $baseURL->getScheme(),
                'hostname' => (string) $baseURL->getAsciiHost(),
                'port' => null === $baseURL->getPort() ? '' : strval($baseURL->getPort()),
                'pathname' => $baseURL->getPath(),
                'search' => (string) $baseURL->getQuery(),
                default => (string) $baseURL->getFragment(),
            };
        }

        return '*';
    }

    /**
     * Computes the expected component result of a successful match.
     *
     * @param array<mixed> $case
     * @param array<mixed> $expectedMatch
     *
     * @return array{input: string, groups: array<int|string, ?string>}
     */
    public static function expectedComponentResult(array $case, array $expectedMatch, string $component): array
    {
        if (isset($expectedMatch[$component]) && is_array($expectedMatch[$component])) {
            /** @var array{input: string, groups: array<int|string, ?string>} $expected */
            $expected = $expectedMatch[$component];

            return ['input' => $expected['input'], 'groups' => self::normalizeGroups($expected['groups'])];
        }

        /** @var list<string> $emptyComponents */
        $emptyComponents = $case['exactly_empty_components'] ?? [];

        return [
            'input' => '',
            'groups' => in_array($component, $emptyComponents, true) ? [] : ['0' => ''],
        ];
    }

    /**
     * Stringifies keys (JS object keys are strings) and sorts them like a JS object would be compared.
     *
     * @param array<int|string, ?string> $groups
     *
     * @return array<int|string, ?string>
     */
    public static function normalizeGroups(array $groups): array
    {
        $normalized = [];
        foreach ($groups as $key => $value) {
            $normalized[(string) $key] = $value;
        }
        \ksort($normalized, \SORT_STRING);

        return $normalized;
    }
}
