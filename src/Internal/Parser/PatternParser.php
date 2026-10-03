<?php

declare(strict_types=1);

namespace BenTools\UrlPattern\Internal\Parser;

use BenTools\UrlPattern\Exception\InvalidPatternException;
use BenTools\UrlPattern\Internal\Tokenizer\Token;
use BenTools\UrlPattern\Internal\Tokenizer\TokenizePolicy;
use BenTools\UrlPattern\Internal\Tokenizer\Tokenizer;
use BenTools\UrlPattern\Internal\Tokenizer\TokenType;
use Closure;

use function count;

/**
 * Implements the "parse a pattern string" algorithm.
 *
 * @see https://urlpattern.spec.whatwg.org/#parse-a-pattern-string
 *
 * @internal
 */
final class PatternParser
{
    public const FULL_WILDCARD_REGEXP_VALUE = '.*';

    /** @var list<Token> */
    private array $tokenList;
    /** @var list<Part> */
    private array $partList = [];
    /** @var array<string, true> names of the parts of $partList, for constant-time duplicate checks */
    private array $partNames = [];
    private string $pendingFixedValue = '';
    private int $index = 0;
    private int $nextNumericName = 0;
    private readonly string $segmentWildcardRegexp;

    /**
     * @param Closure(string): string $encodingCallback
     */
    private function __construct(
        string $input,
        private readonly Options $options,
        private readonly Closure $encodingCallback,
    ) {
        $this->segmentWildcardRegexp = self::generateSegmentWildcardRegexp($options);
        $this->tokenList = Tokenizer::tokenize($input, TokenizePolicy::Strict);
    }

    /**
     * @param Closure(string): string $encodingCallback
     *
     * @return list<Part>
     *
     * @throws InvalidPatternException
     */
    public static function parse(string $input, Options $options, Closure $encodingCallback): array
    {
        return (new self($input, $options, $encodingCallback))->run();
    }

    public static function generateSegmentWildcardRegexp(Options $options): string
    {
        return '[^' . RegexpGenerator::escapeRegexpString($options->delimiterCodePoint) . ']+?';
    }

    /**
     * @return list<Part>
     */
    private function run(): array
    {
        $tokenCount = count($this->tokenList);
        while ($this->index < $tokenCount) {
            $charToken = $this->tryConsumeToken(TokenType::Char);
            $nameToken = $this->tryConsumeToken(TokenType::Name);
            $regexpOrWildcardToken = $this->tryConsumeRegexpOrWildcardToken($nameToken);

            if (null !== $nameToken || null !== $regexpOrWildcardToken) {
                $prefix = $charToken->value ?? '';
                if ('' !== $prefix && $prefix !== $this->options->prefixCodePoint) {
                    $this->pendingFixedValue .= $prefix;
                    $prefix = '';
                }
                $this->maybeAddPartFromPendingFixedValue();
                $modifierToken = $this->tryConsumeModifierToken();
                $this->addPart($prefix, $nameToken, $regexpOrWildcardToken, '', $modifierToken);
                continue;
            }

            $fixedToken = $charToken ?? $this->tryConsumeToken(TokenType::EscapedChar);
            if (null !== $fixedToken) {
                $this->pendingFixedValue .= $fixedToken->value;
                continue;
            }

            $openToken = $this->tryConsumeToken(TokenType::Open);
            if (null !== $openToken) {
                $prefix = $this->consumeText();
                $nameToken = $this->tryConsumeToken(TokenType::Name);
                $regexpOrWildcardToken = $this->tryConsumeRegexpOrWildcardToken($nameToken);
                $suffix = $this->consumeText();
                $this->consumeRequiredToken(TokenType::Close);
                $modifierToken = $this->tryConsumeModifierToken();
                $this->addPart($prefix, $nameToken, $regexpOrWildcardToken, $suffix, $modifierToken);
                continue;
            }

            $this->maybeAddPartFromPendingFixedValue();
            $this->consumeRequiredToken(TokenType::End);
        }

        return $this->partList;
    }

    private function tryConsumeToken(TokenType $type): ?Token
    {
        $nextToken = $this->tokenList[$this->index];
        if ($nextToken->type !== $type) {
            return null;
        }
        ++$this->index;

        return $nextToken;
    }

    private function tryConsumeModifierToken(): ?Token
    {
        return $this->tryConsumeToken(TokenType::OtherModifier) ?? $this->tryConsumeToken(TokenType::Asterisk);
    }

    private function tryConsumeRegexpOrWildcardToken(?Token $nameToken): ?Token
    {
        $token = $this->tryConsumeToken(TokenType::Regexp);
        if (null === $nameToken && null === $token) {
            $token = $this->tryConsumeToken(TokenType::Asterisk);
        }

        return $token;
    }

    private function consumeRequiredToken(TokenType $type): void
    {
        if (null === $this->tryConsumeToken($type)) {
            throw new InvalidPatternException('Invalid pattern string: unexpected token.');
        }
    }

    private function consumeText(): string
    {
        $result = '';
        while (true) {
            $token = $this->tryConsumeToken(TokenType::Char) ?? $this->tryConsumeToken(TokenType::EscapedChar);
            if (null === $token) {
                break;
            }
            $result .= $token->value;
        }

        return $result;
    }

    private function maybeAddPartFromPendingFixedValue(): void
    {
        if ('' === $this->pendingFixedValue) {
            return;
        }
        $encodedValue = ($this->encodingCallback)($this->pendingFixedValue);
        $this->pendingFixedValue = '';
        $this->partList[] = new Part(PartType::FixedText, $encodedValue, PartModifier::None);
    }

    private function addPart(
        string $prefix,
        ?Token $nameToken,
        ?Token $regexpOrWildcardToken,
        string $suffix,
        ?Token $modifierToken,
    ): void {
        $modifier = match ($modifierToken?->value) {
            '?' => PartModifier::Optional,
            '*' => PartModifier::ZeroOrMore,
            '+' => PartModifier::OneOrMore,
            default => PartModifier::None,
        };

        if (null === $nameToken && null === $regexpOrWildcardToken && PartModifier::None === $modifier) {
            $this->pendingFixedValue .= $prefix;

            return;
        }

        $this->maybeAddPartFromPendingFixedValue();

        if (null === $nameToken && null === $regexpOrWildcardToken) {
            if ('' === $prefix) {
                return;
            }
            $encodedValue = ($this->encodingCallback)($prefix);
            $this->partList[] = new Part(PartType::FixedText, $encodedValue, $modifier);

            return;
        }

        $regexpValue = match (true) {
            null === $regexpOrWildcardToken => $this->segmentWildcardRegexp,
            TokenType::Asterisk === $regexpOrWildcardToken->type => self::FULL_WILDCARD_REGEXP_VALUE,
            default => $regexpOrWildcardToken->value,
        };

        $type = PartType::Regexp;
        if ($regexpValue === $this->segmentWildcardRegexp) {
            $type = PartType::SegmentWildcard;
            $regexpValue = '';
        } elseif (self::FULL_WILDCARD_REGEXP_VALUE === $regexpValue) {
            $type = PartType::FullWildcard;
            $regexpValue = '';
        }

        if (null !== $nameToken) {
            $name = $nameToken->value;
        } else {
            $name = (string) $this->nextNumericName;
            ++$this->nextNumericName;
        }

        if (isset($this->partNames[$name])) {
            throw new InvalidPatternException('Invalid pattern string: duplicate group name "' . $name . '".');
        }
        $this->partNames[$name] = true;

        $encodedPrefix = ($this->encodingCallback)($prefix);
        $encodedSuffix = ($this->encodingCallback)($suffix);
        $this->partList[] = new Part($type, $regexpValue, $modifier, $name, $encodedPrefix, $encodedSuffix);
    }
}
