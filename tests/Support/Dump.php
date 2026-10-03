<?php

declare(strict_types=1);

namespace BenTools\UrlPattern\Tests\Support;

use BenTools\UrlPattern\Internal\Parser\Part;
use BenTools\UrlPattern\Internal\Tokenizer\Token;
use BenTools\UrlPattern\Internal\Tokenizer\Tokenizer;
use BenTools\UrlPattern\Internal\Tokenizer\TokenizePolicy;

use function array_map;
use function sprintf;

/**
 * Compact, comparable representations of internal structures, for readable test expectations.
 */
final class Dump
{
    /**
     * @return list<string> tokens formatted as "Type@index=value"
     */
    public static function tokens(string $input, TokenizePolicy $policy = TokenizePolicy::Strict): array
    {
        return array_map(
            static fn (Token $token): string => sprintf('%s@%d=%s', $token->type->name, $token->index, $token->value),
            Tokenizer::tokenize($input, $policy),
        );
    }

    /**
     * @param list<Part> $parts
     *
     * @return list<string> parts formatted as "Type:name:value:modifier:prefix:suffix"
     */
    public static function parts(array $parts): array
    {
        return array_map(
            static fn (Part $part): string => sprintf(
                '%s:%s:%s:%s:%s:%s',
                $part->type->name,
                $part->name,
                $part->value,
                $part->modifier->name,
                $part->prefix,
                $part->suffix,
            ),
            $parts,
        );
    }
}
