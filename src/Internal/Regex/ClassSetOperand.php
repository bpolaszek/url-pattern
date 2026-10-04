<?php

declare(strict_types=1);

namespace BenTools\UrlPattern\Internal\Regex;

/**
 * An operand of a `v`-mode character class, before it is assembled into a PCRE expression.
 * Exactly one of the properties is set.
 *
 * @internal
 */
final readonly class ClassSetOperand
{
    /**
     * @param list<list<int>>|null $strings
     */
    private function __construct(
        public ?int $codePoint = null,
        public ?string $classBody = null,
        public ?string $matcher = null,
        public ?array $strings = null,
    ) {
    }

    public static function codePoint(int $codePoint): self
    {
        return new self(codePoint: $codePoint);
    }

    /**
     * @param string $classBody an item usable inside a PCRE class, e.g. `\d` or `\p{L}`
     */
    public static function classBody(string $classBody): self
    {
        return new self(classBody: $classBody);
    }

    /**
     * @param string $matcher a PCRE expression matching a single code point
     */
    public static function matcher(string $matcher): self
    {
        return new self(matcher: $matcher);
    }

    /**
     * @param list<list<int>> $strings
     */
    public static function strings(array $strings): self
    {
        return new self(strings: $strings);
    }

    public function toMatcher(): string
    {
        return match (true) {
            null !== $this->codePoint => EcmaScriptTranslator::literal($this->codePoint),
            null !== $this->classBody => '[' . $this->classBody . ']',
            null !== $this->matcher => $this->matcher,
            default => throw new \BenTools\UrlPattern\Exception\InvalidPatternException(
                'Class string disjunctions (\q{...}) are only supported in character class unions.',
            ),
        };
    }
}
