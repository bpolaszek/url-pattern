<?php

declare(strict_types=1);

namespace BenTools\UrlPattern\Internal\Parser;

use function str_contains;
use function strlen;

/**
 * Implements "generate a regular expression and name list".
 *
 * The generated expression uses the ECMAScript syntax; it is translated to PCRE afterwards.
 *
 * @see https://urlpattern.spec.whatwg.org/#generate-a-regular-expression-and-name-list
 *
 * @internal
 */
final class RegexpGenerator
{
    private const REGEXP_SPECIAL_CHARACTERS = '.+*?^${}()[]|/\\';

    /**
     * @param list<Part> $partList
     *
     * @return array{string, list<string>}
     */
    public static function generate(array $partList, Options $options): array
    {
        $result = '^';
        $nameList = [];

        foreach ($partList as $part) {
            if (PartType::FixedText === $part->type) {
                if (PartModifier::None === $part->modifier) {
                    $result .= self::escapeRegexpString($part->value);
                } else {
                    $result .= '(?:' . self::escapeRegexpString($part->value) . ')' . $part->modifier->value;
                }
                continue;
            }

            $nameList[] = $part->name;

            $regexpValue = match ($part->type) {
                PartType::SegmentWildcard => PatternParser::generateSegmentWildcardRegexp($options),
                PartType::FullWildcard => PatternParser::FULL_WILDCARD_REGEXP_VALUE,
                default => $part->value,
            };

            $isRepeating = PartModifier::ZeroOrMore === $part->modifier || PartModifier::OneOrMore === $part->modifier;

            if ('' === $part->prefix && '' === $part->suffix) {
                $result .= $isRepeating
                    ? '((?:' . $regexpValue . ')' . $part->modifier->value . ')'
                    : '(' . $regexpValue . ')' . $part->modifier->value;
                continue;
            }

            $prefix = self::escapeRegexpString($part->prefix);
            $suffix = self::escapeRegexpString($part->suffix);

            if (!$isRepeating) {
                $result .= '(?:' . $prefix . '(' . $regexpValue . ')' . $suffix . ')' . $part->modifier->value;
                continue;
            }

            $result .= '(?:' . $prefix
                . '((?:' . $regexpValue . ')(?:' . $suffix . $prefix . '(?:' . $regexpValue . '))*)'
                . $suffix . ')';
            if (PartModifier::ZeroOrMore === $part->modifier) {
                $result .= '?';
            }
        }

        return [$result . '$', $nameList];
    }

    public static function escapeRegexpString(string $input): string
    {
        $result = '';
        $length = strlen($input);
        for ($index = 0; $index < $length; ++$index) {
            $c = $input[$index];
            if (str_contains(self::REGEXP_SPECIAL_CHARACTERS, $c)) {
                $result .= '\\';
            }
            $result .= $c;
        }

        return $result;
    }
}
