<?php

declare(strict_types=1);

namespace BenTools\UrlPattern\Internal\Parser;

use BenTools\UrlPattern\Internal\Canonicalizer;
use BenTools\UrlPattern\Internal\CodePoints;
use BenTools\UrlPattern\Internal\Component;
use BenTools\UrlPattern\Internal\Tokenizer\Token;
use BenTools\UrlPattern\Internal\Tokenizer\TokenizePolicy;
use BenTools\UrlPattern\Internal\Tokenizer\Tokenizer;
use BenTools\UrlPattern\Internal\Tokenizer\TokenType;

use function array_key_exists;
use function array_slice;
use function count;
use function implode;
use function in_array;

/**
 * Implements "parse a constructor string".
 *
 * @see https://urlpattern.spec.whatwg.org/#parse-a-constructor-string
 *
 * @internal
 */
final class ConstructorStringParser
{
    private const NON_SPECIAL_TOKEN_TYPES = [TokenType::Char, TokenType::EscapedChar, TokenType::InvalidChar];
    private const SEARCH_PREFIX_BLOCKING_TOKEN_TYPES = [
        TokenType::Name,
        TokenType::Regexp,
        TokenType::Close,
        TokenType::Asterisk,
    ];

    /** @var list<string> */
    private readonly array $input;
    /** @var list<Token> */
    private readonly array $tokenList;
    /** @var array<string, string> */
    private array $result = [];
    private int $componentStart = 0;
    private int $tokenIndex = 0;
    private int $tokenIncrement = 1;
    private int $groupDepth = 0;
    private int $hostnameIPv6BracketDepth = 0;
    private bool $protocolMatchesSpecialScheme = false;
    private ConstructorState $state = ConstructorState::Init;

    private function __construct(string $input)
    {
        $this->input = CodePoints::split($input);
        $this->tokenList = Tokenizer::tokenize($input, TokenizePolicy::Lenient);
    }

    /**
     * @return array<string, string> a URLPatternInit
     */
    public static function parse(string $input): array
    {
        return (new self($input))->run();
    }

    /**
     * @return array<string, string>
     */
    private function run(): array
    {
        $tokenCount = count($this->tokenList);
        while ($this->tokenIndex < $tokenCount) {
            $this->tokenIncrement = 1;

            if (TokenType::End === $this->tokenList[$this->tokenIndex]->type) {
                if (ConstructorState::Init === $this->state) {
                    $this->rewind();
                    if ($this->isHashPrefix()) {
                        $this->changeState(ConstructorState::Hash, 1);
                    } elseif ($this->isSearchPrefix()) {
                        $this->changeState(ConstructorState::Search, 1);
                    } else {
                        $this->changeState(ConstructorState::Pathname, 0);
                    }
                    $this->tokenIndex += $this->tokenIncrement;
                    continue;
                }
                if (ConstructorState::Authority === $this->state) {
                    $this->rewindAndSetState(ConstructorState::Hostname);
                    $this->tokenIndex += $this->tokenIncrement;
                    continue;
                }
                $this->changeState(ConstructorState::Done, 0);
                break;
            }

            if (TokenType::Open === $this->tokenList[$this->tokenIndex]->type) {
                ++$this->groupDepth;
                $this->tokenIndex += $this->tokenIncrement;
                continue;
            }

            if ($this->groupDepth > 0) {
                if (TokenType::Close !== $this->tokenList[$this->tokenIndex]->type) {
                    $this->tokenIndex += $this->tokenIncrement;
                    continue;
                }
                --$this->groupDepth;
            }

            $this->handleState();
            $this->tokenIndex += $this->tokenIncrement;
        }

        if (array_key_exists('hostname', $this->result) && !array_key_exists('port', $this->result)) {
            $this->result['port'] = '';
        }

        return $this->result;
    }

    private function handleState(): void
    {
        switch ($this->state) {
            case ConstructorState::Init:
                if ($this->isNonSpecialPatternChar($this->tokenIndex, ':')) {
                    $this->rewindAndSetState(ConstructorState::Protocol);
                }
                break;
            case ConstructorState::Protocol:
                if ($this->isNonSpecialPatternChar($this->tokenIndex, ':')) {
                    $this->computeProtocolMatchesSpecialScheme();
                    $nextState = ConstructorState::Pathname;
                    $skip = 1;
                    if ($this->nextIsAuthoritySlashes()) {
                        $nextState = ConstructorState::Authority;
                        $skip = 3;
                    } elseif ($this->protocolMatchesSpecialScheme) {
                        $nextState = ConstructorState::Authority;
                    }
                    $this->changeState($nextState, $skip);
                }
                break;
            case ConstructorState::Authority:
                if ($this->isNonSpecialPatternChar($this->tokenIndex, '@')) {
                    $this->rewindAndSetState(ConstructorState::Username);
                } elseif ($this->isPathnameStart() || $this->isSearchPrefix() || $this->isHashPrefix()) {
                    $this->rewindAndSetState(ConstructorState::Hostname);
                }
                break;
            case ConstructorState::Username:
                if ($this->isNonSpecialPatternChar($this->tokenIndex, ':')) {
                    $this->changeState(ConstructorState::Password, 1);
                } elseif ($this->isNonSpecialPatternChar($this->tokenIndex, '@')) {
                    $this->changeState(ConstructorState::Hostname, 1);
                }
                break;
            case ConstructorState::Password:
                if ($this->isNonSpecialPatternChar($this->tokenIndex, '@')) {
                    $this->changeState(ConstructorState::Hostname, 1);
                }
                break;
            case ConstructorState::Hostname:
                if ($this->isNonSpecialPatternChar($this->tokenIndex, '[')) {
                    ++$this->hostnameIPv6BracketDepth;
                } elseif ($this->isNonSpecialPatternChar($this->tokenIndex, ']')) {
                    --$this->hostnameIPv6BracketDepth;
                } elseif (
                    $this->isNonSpecialPatternChar($this->tokenIndex, ':')
                    && 0 === $this->hostnameIPv6BracketDepth
                ) {
                    $this->changeState(ConstructorState::Port, 1);
                } elseif ($this->isPathnameStart()) {
                    $this->changeState(ConstructorState::Pathname, 0);
                } elseif ($this->isSearchPrefix()) {
                    $this->changeState(ConstructorState::Search, 1);
                } elseif ($this->isHashPrefix()) {
                    $this->changeState(ConstructorState::Hash, 1);
                }
                break;
            case ConstructorState::Port:
                if ($this->isPathnameStart()) {
                    $this->changeState(ConstructorState::Pathname, 0);
                } elseif ($this->isSearchPrefix()) {
                    $this->changeState(ConstructorState::Search, 1);
                } elseif ($this->isHashPrefix()) {
                    $this->changeState(ConstructorState::Hash, 1);
                }
                break;
            case ConstructorState::Pathname:
                if ($this->isSearchPrefix()) {
                    $this->changeState(ConstructorState::Search, 1);
                } elseif ($this->isHashPrefix()) {
                    $this->changeState(ConstructorState::Hash, 1);
                }
                break;
            case ConstructorState::Search:
                if ($this->isHashPrefix()) {
                    $this->changeState(ConstructorState::Hash, 1);
                }
                break;
            default:
                // Hash: nothing to do. Done is never reached here.
                break;
        }
    }

    private function changeState(ConstructorState $newState, int $skip): void
    {
        $state = $this->state;
        if (
            ConstructorState::Init !== $state
            && ConstructorState::Authority !== $state
            && ConstructorState::Done !== $state
        ) {
            $this->result[$state->value] = $this->makeComponentString();
        }

        if (ConstructorState::Init !== $state && ConstructorState::Done !== $newState) {
            if (
                in_array($state, ConstructorState::BEFORE_HOSTNAME, true)
                && in_array(
                    $newState,
                    [
                        ConstructorState::Port,
                        ConstructorState::Pathname,
                        ConstructorState::Search,
                        ConstructorState::Hash,
                    ],
                    true,
                )
                && !array_key_exists('hostname', $this->result)
            ) {
                $this->result['hostname'] = '';
            }

            if (
                in_array($state, ConstructorState::BEFORE_PATHNAME, true)
                && in_array($newState, [ConstructorState::Search, ConstructorState::Hash], true)
                && !array_key_exists('pathname', $this->result)
            ) {
                $this->result['pathname'] = $this->protocolMatchesSpecialScheme ? '/' : '';
            }

            if (
                (in_array($state, ConstructorState::BEFORE_PATHNAME, true) || ConstructorState::Pathname === $state)
                && ConstructorState::Hash === $newState
                && !array_key_exists('search', $this->result)
            ) {
                $this->result['search'] = '';
            }
        }

        $this->state = $newState;
        $this->tokenIndex += $skip;
        $this->componentStart = $this->tokenIndex;
        $this->tokenIncrement = 0;
    }

    private function rewind(): void
    {
        $this->tokenIndex = $this->componentStart;
        $this->tokenIncrement = 0;
    }

    private function rewindAndSetState(ConstructorState $state): void
    {
        $this->rewind();
        $this->state = $state;
    }

    private function getSafeToken(int $index): Token
    {
        return $this->tokenList[$index] ?? $this->tokenList[count($this->tokenList) - 1];
    }

    private function isNonSpecialPatternChar(int $index, string $value): bool
    {
        $token = $this->getSafeToken($index);

        return $token->value === $value && in_array($token->type, self::NON_SPECIAL_TOKEN_TYPES, true);
    }

    private function nextIsAuthoritySlashes(): bool
    {
        return $this->isNonSpecialPatternChar($this->tokenIndex + 1, '/')
            && $this->isNonSpecialPatternChar($this->tokenIndex + 2, '/');
    }

    private function isPathnameStart(): bool
    {
        return $this->isNonSpecialPatternChar($this->tokenIndex, '/');
    }

    private function isSearchPrefix(): bool
    {
        if ($this->isNonSpecialPatternChar($this->tokenIndex, '?')) {
            return true;
        }
        if ('?' !== $this->tokenList[$this->tokenIndex]->value) {
            return false;
        }
        if ($this->tokenIndex - 1 < 0) {
            return true;
        }
        $previousToken = $this->getSafeToken($this->tokenIndex - 1);

        return !in_array($previousToken->type, self::SEARCH_PREFIX_BLOCKING_TOKEN_TYPES, true);
    }

    private function isHashPrefix(): bool
    {
        return $this->isNonSpecialPatternChar($this->tokenIndex, '#');
    }

    private function makeComponentString(): string
    {
        $token = $this->tokenList[$this->tokenIndex];
        $componentStartInputIndex = $this->getSafeToken($this->componentStart)->index;

        return implode(
            '',
            array_slice($this->input, $componentStartInputIndex, $token->index - $componentStartInputIndex),
        );
    }

    private function computeProtocolMatchesSpecialScheme(): void
    {
        $protocolComponent = Component::compile(
            $this->makeComponentString(),
            Canonicalizer::protocol(...),
            Options::default(),
        );
        $this->protocolMatchesSpecialScheme = $protocolComponent->matchesSpecialScheme();
    }
}
