<?php

declare(strict_types=1);

namespace BenTools\UrlPattern\Internal\Tokenizer;

use BenTools\UrlPattern\Exception\InvalidPatternException;
use BenTools\UrlPattern\Internal\CodePoints;

use function count;
use function implode;
use function array_slice;
use function ord;
use function strlen;

/**
 * Implements the "tokenize" algorithm.
 *
 * @see https://urlpattern.spec.whatwg.org/#tokenize
 *
 * @internal
 */
final class Tokenizer
{
    /** @var list<string> */
    private readonly array $input;
    private readonly int $length;

    /** @var list<Token> */
    private array $tokenList = [];
    private int $index = 0;
    private int $nextIndex = 0;
    private string $codePoint = '';

    private function __construct(string $input, private readonly TokenizePolicy $policy)
    {
        $this->input = CodePoints::split($input);
        $this->length = count($this->input);
    }

    /**
     * @return list<Token>
     *
     * @throws InvalidPatternException with the strict policy
     */
    public static function tokenize(string $input, TokenizePolicy $policy): array
    {
        return (new self($input, $policy))->run();
    }

    /**
     * @return list<Token>
     */
    private function run(): array
    {
        while ($this->index < $this->length) {
            $this->seekAndGetNextCodePoint($this->index);

            if ('*' === $this->codePoint) {
                $this->addTokenWithDefaultPositionAndLength(TokenType::Asterisk);
                continue;
            }

            if ('+' === $this->codePoint || '?' === $this->codePoint) {
                $this->addTokenWithDefaultPositionAndLength(TokenType::OtherModifier);
                continue;
            }

            if ('\\' === $this->codePoint) {
                if ($this->index === $this->length - 1) {
                    $this->processTokenizingError($this->nextIndex, $this->index);
                    continue;
                }
                $escapedIndex = $this->nextIndex;
                $this->getNextCodePoint();
                $this->addTokenWithDefaultLength(TokenType::EscapedChar, $this->nextIndex, $escapedIndex);
                continue;
            }

            if ('{' === $this->codePoint) {
                $this->addTokenWithDefaultPositionAndLength(TokenType::Open);
                continue;
            }

            if ('}' === $this->codePoint) {
                $this->addTokenWithDefaultPositionAndLength(TokenType::Close);
                continue;
            }

            if (':' === $this->codePoint) {
                $namePosition = $this->nextIndex;
                $nameStart = $namePosition;
                while ($namePosition < $this->length) {
                    $this->seekAndGetNextCodePoint($namePosition);
                    if (!CodePoints::isValidNameCodePoint($this->codePoint, $namePosition === $nameStart)) {
                        break;
                    }
                    $namePosition = $this->nextIndex;
                }
                if ($namePosition <= $nameStart) {
                    $this->processTokenizingError($nameStart, $this->index);
                    continue;
                }
                $this->addTokenWithDefaultLength(TokenType::Name, $namePosition, $nameStart);
                continue;
            }

            if ('(' === $this->codePoint) {
                $this->tokenizeRegexp();
                continue;
            }

            $this->addTokenWithDefaultPositionAndLength(TokenType::Char);
        }

        $this->addTokenWithDefaultLength(TokenType::End, $this->index, $this->index);

        return $this->tokenList;
    }

    private function tokenizeRegexp(): void
    {
        $depth = 1;
        $regexpPosition = $this->nextIndex;
        $regexpStart = $regexpPosition;

        while ($regexpPosition < $this->length) {
            $this->seekAndGetNextCodePoint($regexpPosition);

            if (!self::isAscii($this->codePoint)) {
                $this->processTokenizingError($regexpStart, $this->index);

                return;
            }

            if ($regexpPosition === $regexpStart && '?' === $this->codePoint) {
                $this->processTokenizingError($regexpStart, $this->index);

                return;
            }

            if ('\\' === $this->codePoint) {
                if ($regexpPosition === $this->length - 1) {
                    $this->processTokenizingError($regexpStart, $this->index);

                    return;
                }
                $this->getNextCodePoint();
                if (!self::isAscii($this->codePoint)) {
                    $this->processTokenizingError($regexpStart, $this->index);

                    return;
                }
                $regexpPosition = $this->nextIndex;
                continue;
            }

            if (')' === $this->codePoint) {
                --$depth;
                if (0 === $depth) {
                    $regexpPosition = $this->nextIndex;
                    break;
                }
            } elseif ('(' === $this->codePoint) {
                ++$depth;
                if ($regexpPosition === $this->length - 1) {
                    $this->processTokenizingError($regexpStart, $this->index);

                    return;
                }
                $temporaryPosition = $this->nextIndex;
                $this->getNextCodePoint();
                if ('?' !== $this->codePoint) {
                    $this->processTokenizingError($regexpStart, $this->index);

                    return;
                }
                $this->nextIndex = $temporaryPosition;
            }

            $regexpPosition = $this->nextIndex;
        }

        if (0 !== $depth) {
            $this->processTokenizingError($regexpStart, $this->index);

            return;
        }

        $regexpLength = $regexpPosition - $regexpStart - 1;
        if (0 === $regexpLength) {
            $this->processTokenizingError($regexpStart, $this->index);

            return;
        }

        $this->addToken(TokenType::Regexp, $regexpPosition, $regexpStart, $regexpLength);
    }

    private function getNextCodePoint(): void
    {
        $this->codePoint = $this->input[$this->nextIndex];
        ++$this->nextIndex;
    }

    private function seekAndGetNextCodePoint(int $index): void
    {
        $this->nextIndex = $index;
        $this->getNextCodePoint();
    }

    private function addToken(TokenType $type, int $nextPosition, int $valuePosition, int $valueLength): void
    {
        $value = implode('', array_slice($this->input, $valuePosition, $valueLength));
        $this->tokenList[] = new Token($type, $this->index, $value);
        $this->index = $nextPosition;
    }

    private function addTokenWithDefaultLength(TokenType $type, int $nextPosition, int $valuePosition): void
    {
        $this->addToken($type, $nextPosition, $valuePosition, $nextPosition - $valuePosition);
    }

    private function addTokenWithDefaultPositionAndLength(TokenType $type): void
    {
        $this->addTokenWithDefaultLength($type, $this->nextIndex, $this->index);
    }

    private function processTokenizingError(int $nextPosition, int $valuePosition): void
    {
        if (TokenizePolicy::Strict === $this->policy) {
            throw new InvalidPatternException(
                'Invalid pattern string: unexpected code point at index ' . $valuePosition . '.',
            );
        }
        $this->addTokenWithDefaultLength(TokenType::InvalidChar, $nextPosition, $valuePosition);
    }

    private static function isAscii(string $codePoint): bool
    {
        return 1 === strlen($codePoint) && ord($codePoint) < 0x80;
    }
}
