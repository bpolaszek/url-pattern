<?php

declare(strict_types=1);

namespace BenTools\UrlPattern\Internal;

use function mb_check_encoding;
use function mb_scrub;
use function mb_str_split;
use function mb_substitute_character;
use function preg_match;

/**
 * Code point helpers. Every string handled here is expected to be valid UTF-8
 * (inputs are scrubbed at the public API boundary, like a WebIDL USVString).
 *
 * @internal
 */
final class CodePoints
{
    private const REPLACEMENT_CHARACTER = 0xFFFD;

    /**
     * Converts a string like a WebIDL USVString: invalid UTF-8 sequences become U+FFFD.
     */
    public static function scrub(string $input): string
    {
        if (mb_check_encoding($input, 'UTF-8')) {
            return $input;
        }

        $previousSubstituteCharacter = mb_substitute_character();
        mb_substitute_character(self::REPLACEMENT_CHARACTER);
        try {
            return mb_scrub($input, 'UTF-8');
        } finally {
            mb_substitute_character($previousSubstituteCharacter);
        }
    }

    /**
     * @return list<string>
     */
    public static function split(string $input): array
    {
        return mb_str_split($input, 1, 'UTF-8');
    }

    /**
     * ECMAScript IdentifierStart (when $first) or IdentifierPart.
     */
    public static function isValidNameCodePoint(string $codePoint, bool $first): bool
    {
        return 1 === preg_match(
            $first ? '/^[\p{ID_Start}$_]$/u' : '/^[\p{ID_Continue}$\x{200C}\x{200D}]$/u',
            $codePoint,
        );
    }
}
