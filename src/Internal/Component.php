<?php

declare(strict_types=1);

namespace BenTools\UrlPattern\Internal;

use BenTools\UrlPattern\Exception\InvalidPatternException;
use BenTools\UrlPattern\Internal\Parser\Options;
use BenTools\UrlPattern\Internal\Parser\PartModifier;
use BenTools\UrlPattern\Internal\Parser\PartType;
use BenTools\UrlPattern\Internal\Parser\PatternParser;
use BenTools\UrlPattern\Internal\Parser\PatternStringGenerator;
use BenTools\UrlPattern\Internal\Parser\RegexpGenerator;
use BenTools\UrlPattern\Internal\Regex\BoundedMatcher;
use BenTools\UrlPattern\Internal\Regex\EcmaScriptTranslator;
use Closure;

use function array_keys;
use function preg_last_error_msg;
use function preg_match;

/**
 * A compiled URL pattern component.
 *
 * @see https://urlpattern.spec.whatwg.org/#component
 *
 * @internal
 */
final readonly class Component
{
    /**
     * @param list<string> $groupNameList
     * @param list<bool> $undefinedWhenEmpty for each group, whether an empty capture means "undefined"
     */
    private function __construct(
        public string $patternString,
        public string $regularExpression,
        public array $groupNameList,
        public bool $hasRegExpGroups,
        private array $undefinedWhenEmpty,
    ) {
    }

    /**
     * @param Closure(string): string $encodingCallback
     *
     * @throws InvalidPatternException
     */
    public static function compile(string $input, Closure $encodingCallback, Options $options): self
    {
        $partList = PatternParser::parse($input, $options, $encodingCallback);
        [$regularExpressionString, $nameList] = RegexpGenerator::generate($partList, $options);

        $hasRegExpGroups = false;
        $undefinedWhenEmpty = [];
        foreach ($partList as $part) {
            if (PartType::FixedText === $part->type) {
                continue;
            }
            // ECMAScript rejects an empty iteration of a quantified group, leaving its capture undefined,
            // whereas PCRE captures an empty string. Only the "(<regexp>)?" form is affected.
            $undefinedWhenEmpty[] = PartModifier::Optional === $part->modifier
                && '' === $part->prefix
                && '' === $part->suffix;
            if (PartType::Regexp === $part->type) {
                $hasRegExpGroups = true;
                // Only user-provided expressions go through the linear-time guard. The URLPattern
                // modifier is included, so that "(a+)+" is caught like the regexp "(?:a+)+".
                EcmaScriptTranslator::translate(
                    '(?:' . $part->value . ')' . $part->modifier->value,
                    linearTimeGuard: true,
                );
            }
        }

        $regularExpression = '#' . EcmaScriptTranslator::translate($regularExpressionString) . '#uD'
            . ($options->ignoreCase ? 'i' : '');
        if (false === @preg_match($regularExpression, '')) {
            throw new InvalidPatternException('Invalid regular expression: ' . preg_last_error_msg() . '.');
        }

        return new self(
            PatternStringGenerator::generate($partList, $options),
            $regularExpression,
            $nameList,
            $hasRegExpGroups,
            $undefinedWhenEmpty,
        );
    }

    /**
     * Must be called within BoundedMatcher::withBacktrackLimit().
     *
     * @return array<int|string, ?string>|null the groups, or null when the input does not match
     */
    public function match(string $input): ?array
    {
        $matches = BoundedMatcher::match($this->regularExpression, $input);
        if (null === $matches) {
            return null;
        }

        $groups = [];
        foreach ($this->groupNameList as $index => $name) {
            $value = $matches[$index + 1] ?? null;
            $groups[$name] = '' === $value && $this->undefinedWhenEmpty[$index] ? null : $value;
        }

        return $groups;
    }

    /**
     * @see https://urlpattern.spec.whatwg.org/#protocol-component-matches-a-special-scheme
     */
    public function matchesSpecialScheme(): bool
    {
        return BoundedMatcher::withBacktrackLimit(
            BoundedMatcher::DEFAULT_BACKTRACK_LIMIT,
            function (): bool {
                foreach (array_keys(SpecialScheme::DEFAULT_PORTS) as $scheme) {
                    if (null !== $this->match($scheme)) {
                        return true;
                    }
                }

                return false;
            },
        );
    }
}
