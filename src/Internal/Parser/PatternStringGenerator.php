<?php

declare(strict_types=1);

namespace BenTools\UrlPattern\Internal\Parser;

use BenTools\UrlPattern\Internal\CodePoints;

use function ctype_digit;
use function mb_substr;
use function str_contains;
use function str_ends_with;
use function strlen;

/**
 * Implements "generate a pattern string".
 *
 * @see https://urlpattern.spec.whatwg.org/#generate-a-pattern-string
 *
 * @internal
 */
final class PatternStringGenerator
{
    private const PATTERN_SPECIAL_CHARACTERS = '+*?:{}()\\';

    /**
     * @param list<Part> $partList
     */
    public static function generate(array $partList, Options $options): string
    {
        $result = '';

        foreach ($partList as $index => $part) {
            $previousPart = $partList[$index - 1] ?? null;
            $nextPart = $partList[$index + 1] ?? null;

            if (PartType::FixedText === $part->type) {
                if (PartModifier::None === $part->modifier) {
                    $result .= self::escapePatternString($part->value);
                    continue;
                }
                $result .= '{' . self::escapePatternString($part->value) . '}' . $part->modifier->value;
                continue;
            }

            $customName = !ctype_digit($part->name[0]);
            $needsGrouping = '' !== $part->suffix
                || ('' !== $part->prefix && $part->prefix !== $options->prefixCodePoint);

            if (
                !$needsGrouping
                && $customName
                && PartType::SegmentWildcard === $part->type
                && PartModifier::None === $part->modifier
                && null !== $nextPart
                && '' === $nextPart->prefix
                && '' === $nextPart->suffix
            ) {
                if (PartType::FixedText === $nextPart->type) {
                    $needsGrouping = CodePoints::isValidNameCodePoint(
                        mb_substr($nextPart->value, 0, 1, 'UTF-8'),
                        false,
                    );
                } else {
                    $needsGrouping = ctype_digit($nextPart->name[0]);
                }
            }

            if (
                !$needsGrouping
                && '' === $part->prefix
                && null !== $previousPart
                && PartType::FixedText === $previousPart->type
                && '' !== $options->prefixCodePoint
                && str_ends_with($previousPart->value, $options->prefixCodePoint)
            ) {
                $needsGrouping = true;
            }

            if ($needsGrouping) {
                $result .= '{';
            }

            $result .= self::escapePatternString($part->prefix);

            if ($customName) {
                $result .= ':' . $part->name;
            }

            if (PartType::Regexp === $part->type) {
                $result .= '(' . $part->value . ')';
            } elseif (PartType::SegmentWildcard === $part->type && !$customName) {
                $result .= '(' . PatternParser::generateSegmentWildcardRegexp($options) . ')';
            } elseif (PartType::FullWildcard === $part->type) {
                if (
                    !$customName
                    && (
                        null === $previousPart
                        || PartType::FixedText === $previousPart->type
                        || PartModifier::None !== $previousPart->modifier
                        || $needsGrouping
                        || '' !== $part->prefix
                    )
                ) {
                    $result .= '*';
                } else {
                    $result .= '(' . PatternParser::FULL_WILDCARD_REGEXP_VALUE . ')';
                }
            }

            if (
                PartType::SegmentWildcard === $part->type
                && $customName
                && '' !== $part->suffix
                && CodePoints::isValidNameCodePoint(mb_substr($part->suffix, 0, 1, 'UTF-8'), false)
            ) {
                $result .= '\\';
            }

            $result .= self::escapePatternString($part->suffix);

            if ($needsGrouping) {
                $result .= '}';
            }

            $result .= $part->modifier->value;
        }

        return $result;
    }

    public static function escapePatternString(string $input): string
    {
        $result = '';
        $length = strlen($input);
        for ($index = 0; $index < $length; ++$index) {
            $c = $input[$index];
            if (str_contains(self::PATTERN_SPECIAL_CHARACTERS, $c)) {
                $result .= '\\';
            }
            $result .= $c;
        }

        return $result;
    }
}
