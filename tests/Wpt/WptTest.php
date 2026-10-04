<?php

declare(strict_types=1);

use BenTools\UrlPattern\Exception\InvalidPatternException;
use BenTools\UrlPattern\Tests\Wpt\WptFixtures;
use BenTools\UrlPattern\URLPattern;
use BenTools\UrlPattern\URLPatternComponentResult;

dataset('wpt', function (): iterable {
    foreach (WptFixtures::load() as $index => $case) {
        yield sprintf('#%d %s', $index, json_encode($case['pattern'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))
            => [$case];
    }
});

it('passes the WPT urlpattern case', function (array $case): void {
    /** @var list<mixed> $patternArgs */
    $patternArgs = $case['pattern'];
    [$input, $baseURL, $options] = WptFixtures::constructorArgs($patternArgs);

    if ('error' === ($case['expected_obj'] ?? null)) {
        expect(fn () => new URLPattern($input, $baseURL, $options))->toThrow(InvalidPatternException::class);

        return;
    }

    $pattern = new URLPattern($input, $baseURL, $options);
    foreach (WptFixtures::COMPONENTS as $component) {
        expect($pattern->{$component})->toBe(
            WptFixtures::expectedComponent($case, $component),
            "compiled pattern property '{$component}'",
        );
    }

    /** @var list<string|array<string, string>> $inputs */
    $inputs = $case['inputs'] ?? [];
    $matchInput = $inputs[0] ?? [];
    $matchBaseURL = isset($inputs[1]) && is_string($inputs[1]) ? $inputs[1] : null;

    if ('error' === ($case['expected_match'] ?? null)) {
        expect(fn () => $pattern->test($matchInput, $matchBaseURL))->toThrow(InvalidArgumentException::class)
            ->and(fn () => $pattern->exec($matchInput, $matchBaseURL))->toThrow(InvalidArgumentException::class);

        return;
    }

    $expectedMatch = $case['expected_match'] ?? null;
    expect($pattern->test($matchInput, $matchBaseURL))->toBe(null !== $expectedMatch, 'test() result');

    $result = $pattern->exec($matchInput, $matchBaseURL);
    if (!is_array($expectedMatch)) {
        expect($result)->toBeNull();

        return;
    }
    expect($result)->not->toBeNull();
    assert(null !== $result);

    /** @var list<string|array<string, string>> $expectedInputs */
    $expectedInputs = $expectedMatch['inputs'] ?? $inputs;
    expect($result->inputs)->toHaveCount(count($expectedInputs));
    foreach ($result->inputs as $i => $resultInput) {
        $expectedInput = $expectedInputs[$i];
        if (is_string($resultInput)) {
            expect($resultInput)->toBe($expectedInput);
            continue;
        }
        // Key order is irrelevant for an init dictionary.
        expect($resultInput)->toEqual($expectedInput);
    }

    foreach (WptFixtures::COMPONENTS as $component) {
        /** @var URLPatternComponentResult $componentResult */
        $componentResult = $result->{$component};
        expect([
            'input' => $componentResult->input,
            'groups' => WptFixtures::normalizeGroups($componentResult->groups),
        ])->toBe(
            WptFixtures::expectedComponentResult($case, $expectedMatch, $component),
            "exec() result for {$component}",
        );
    }
})->with('wpt');
