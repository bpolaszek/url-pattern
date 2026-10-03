<?php

declare(strict_types=1);

namespace BenTools\UrlPattern\Internal;

use function ord;
use function sprintf;
use function str_contains;
use function strlen;

/**
 * UTF-8 percent-encoding with the WHATWG percent-encode sets.
 *
 * Every byte of a multi-byte UTF-8 sequence is >= 0x80 and is therefore always encoded,
 * which allows working on bytes instead of code points.
 *
 * @see https://url.spec.whatwg.org/#percent-encoded-bytes
 *
 * @internal
 */
final class PercentEncoder
{
    /** Extra code points of the fragment percent-encode set (on top of the C0 control set). */
    public const FRAGMENT_SET = ' "<>`';
    public const QUERY_SET = ' "#<>';
    public const SPECIAL_QUERY_SET = self::QUERY_SET . "'";
    public const PATH_SET = self::QUERY_SET . '?^`{}';
    public const USERINFO_SET = self::PATH_SET . '/:;=@[\\]|';

    /**
     * @param string $set extra ASCII code points to encode on top of the C0 control percent-encode set
     */
    public static function encode(string $input, string $set = ''): string
    {
        $result = '';
        $length = strlen($input);
        for ($index = 0; $index < $length; ++$index) {
            $byte = $input[$index];
            $code = ord($byte);
            if ($code < 0x20 || $code > 0x7E || str_contains($set, $byte)) {
                $result .= sprintf('%%%02X', $code);
            } else {
                $result .= $byte;
            }
        }

        return $result;
    }
}
