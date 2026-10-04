<?php

declare(strict_types=1);

namespace BenTools\UrlPattern\Internal\Regex;

use BenTools\UrlPattern\Exception\InvalidPatternException;
use BenTools\UrlPattern\Internal\CodePoints;

use function array_key_exists;
use function array_map;
use function array_pop;
use function count;
use function ctype_alnum;
use function ctype_digit;
use function ctype_xdigit;
use function dechex;
use function hexdec;
use function implode;
use function in_array;
use function mb_ord;
use function preg_match;
use function str_contains;
use function strtoupper;
use function usort;

/**
 * Translates an ECMAScript regular expression (`v` flag, a.k.a. "unicodeSets" mode)
 * into an equivalent PCRE2 pattern body (to be compiled with the `u` and `D` modifiers).
 *
 * The translator is also a validator: it rejects what an ECMAScript engine would reject
 * (when PCRE would silently accept it), as well as the constructs it cannot translate.
 *
 * In "linear time guard" mode, it additionally rejects patterns prone to catastrophic
 * backtracking (a repeated sub-expression which itself contains an unbounded quantifier).
 *
 * @internal
 */
final class EcmaScriptTranslator
{
    private const SYNTAX_CHARACTERS = '^$\\.*+?()[]{}|/';
    private const CLASS_SET_SYNTAX_CHARACTERS = '()[]{}/-\\|';
    private const CLASS_SET_RESERVED_DOUBLE_PUNCTUATORS = '&!#$%*+,.:;<=>?@^`~';
    private const CLASS_SET_RESERVED_PUNCTUATORS = '&-!#%,:;<=>@`~';
    private const CONTROL_ESCAPES = ['f' => 0x0C, 'n' => 0x0A, 'r' => 0x0D, 't' => 0x09, 'v' => 0x0B];
    private const MODIFIER_FLAGS = 'ims';
    private const ANY_CODE_POINT = '[\s\S]';
    private const NEVER_MATCH = '(?!)';
    private const LINE_TERMINATORS_EXCLUDED = '[^\n\r\x{2028}\x{2029}]';
    /**
     * PHP compiles "/u" patterns with PCRE2_UCP, which makes \d, \w and \b Unicode-aware.
     * In ECMAScript, they are ASCII-only: they are therefore spelled out.
     */
    private const ASCII_DIGIT = '0-9';
    private const ASCII_WORD = 'A-Za-z0-9_';
    private const WORD_BOUNDARY = '(?:(?<=[A-Za-z0-9_])(?![A-Za-z0-9_])|(?<![A-Za-z0-9_])(?=[A-Za-z0-9_]))';
    private const NOT_WORD_BOUNDARY = '(?:(?<=[A-Za-z0-9_])(?=[A-Za-z0-9_])|(?<![A-Za-z0-9_])(?![A-Za-z0-9_]))';
    /** ECMAScript WhiteSpace and LineTerminator code points, as a PCRE class body. */
    private const WHITE_SPACE = '\t\n\x{0B}\f\r \x{A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}'
        . '\x{202F}\x{205F}\x{3000}\x{FEFF}';
    private const NON_BINARY_PROPERTIES = [
        'General_Category' => '',
        'gc' => '',
        'Script' => 'sc:',
        'sc' => 'sc:',
        'Script_Extensions' => 'scx:',
        'scx' => 'scx:',
    ];
    private const PROPERTIES_OF_STRINGS = [
        'Basic_Emoji',
        'Emoji_Keycap_Sequence',
        'RGI_Emoji_Modifier_Sequence',
        'RGI_Emoji_Flag_Sequence',
        'RGI_Emoji_Tag_Sequence',
        'RGI_Emoji_ZWJ_Sequence',
        'RGI_Emoji',
    ];

    /** @var list<string> */
    private readonly array $input;
    private int $pos = 0;
    /** @var array<string, true> */
    private array $groupNames = [];
    /** @var list<bool> dotAll ("s" flag) state, one entry per modifier group */
    private array $dotAll = [false];

    private function __construct(string $pattern, private readonly bool $linearTimeGuard)
    {
        $this->input = CodePoints::split($pattern);
    }

    /**
     * @throws InvalidPatternException
     */
    public static function translate(string $pattern, bool $linearTimeGuard = false): string
    {
        $translator = new self($pattern, $linearTimeGuard);
        [$result] = $translator->parseDisjunction();
        if (null !== $translator->peek()) {
            throw self::error('unmatched ")"');
        }

        return $result;
    }

    /**
     * @return array{string, bool} the translation and whether it contains an unbounded quantifier
     */
    private function parseDisjunction(): array
    {
        $alternatives = [];
        $unbounded = false;
        do {
            [$alternative, $alternativeUnbounded] = $this->parseAlternative();
            $alternatives[] = $alternative;
            $unbounded = $unbounded || $alternativeUnbounded;
        } while ($this->eat('|'));

        return [implode('|', $alternatives), $unbounded];
    }

    /**
     * @return array{string, bool}
     */
    private function parseAlternative(): array
    {
        $result = '';
        $unbounded = false;
        while (null !== ($c = $this->peek()) && '|' !== $c && ')' !== $c) {
            [$term, $termUnbounded] = $this->parseTerm();
            $result .= $term;
            $unbounded = $unbounded || $termUnbounded;
        }

        return [$result, $unbounded];
    }

    /**
     * @return array{string, bool}
     */
    private function parseTerm(): array
    {
        $assertion = $this->tryParseAssertion();
        if (null !== $assertion) {
            if (null !== $this->tryParseQuantifier()) {
                throw self::error('nothing to repeat');
            }

            return $assertion;
        }

        [$atom, $unbounded] = $this->parseAtom();
        $quantifier = $this->tryParseQuantifier();
        if (null === $quantifier) {
            return [$atom, $unbounded];
        }
        [$quantifierString, $repeating, $quantifierUnbounded] = $quantifier;
        if ($this->linearTimeGuard && $repeating && $unbounded) {
            throw new InvalidPatternException(
                'Regular expression rejected by the linear-time guard: nested unbounded quantifiers are not allowed.',
            );
        }
        if (null !== $this->tryParseQuantifier()) {
            throw self::error('nothing to repeat');
        }

        return [$atom . $quantifierString, $unbounded || $quantifierUnbounded];
    }

    /**
     * @return array{string, bool}|null
     */
    private function tryParseAssertion(): ?array
    {
        $c = $this->peek();
        if ('^' === $c || '$' === $c) {
            ++$this->pos;

            return [$c, false];
        }
        if ('\\' === $c && in_array($this->peek(1), ['b', 'B'], true)) {
            $this->pos += 2;

            return ['b' === $this->input[$this->pos - 1] ? self::WORD_BOUNDARY : self::NOT_WORD_BOUNDARY, false];
        }
        if ('(' === $c && '?' === $this->peek(1)) {
            $lookaround = match (true) {
                '=' === $this->peek(2) => '(?=',
                '!' === $this->peek(2) => '(?!',
                '<' === $this->peek(2) && '=' === $this->peek(3) => '(?<=',
                '<' === $this->peek(2) && '!' === $this->peek(3) => '(?<!',
                default => null,
            };
            if (null === $lookaround) {
                return null;
            }
            $this->pos += \strlen($lookaround);
            [$body, $unbounded] = $this->parseDisjunction();
            $this->expect(')');

            return [$lookaround . $body . ')', $unbounded];
        }

        return null;
    }

    /**
     * @return array{string, bool, bool}|null the quantifier, whether it repeats and whether it is unbounded
     */
    private function tryParseQuantifier(): ?array
    {
        $c = $this->peek();
        if ('*' === $c || '+' === $c || '?' === $c) {
            ++$this->pos;
            $quantifier = [$c, '?' !== $c, '?' !== $c];
        } elseif ('{' === $c) {
            $start = $this->pos;
            ++$this->pos;
            $min = $this->parseDigits();
            if (null === $min) {
                throw self::error('lone quantifier brackets');
            }
            $max = $min;
            if ($this->eat(',')) {
                $max = $this->parseDigits();
            }
            $this->expect('}');
            if (null !== $max && (int) $max < (int) $min) {
                throw self::error('numbers out of order in {} quantifier');
            }
            $quantifier = [
                implode('', \array_slice($this->input, $start, $this->pos - $start)),
                null === $max || (int) $max > 1,
                null === $max,
            ];
        } else {
            return null;
        }

        if ($this->eat('?')) {
            $quantifier[0] .= '?';
        }

        return $quantifier;
    }

    private function parseDigits(): ?string
    {
        $digits = '';
        while (null !== ($c = $this->peek()) && ctype_digit($c)) {
            $digits .= $c;
            ++$this->pos;
        }

        return '' === $digits ? null : $digits;
    }

    /**
     * @return array{string, bool}
     */
    private function parseAtom(): array
    {
        $c = $this->peek();
        switch ($c) {
            case '.':
                ++$this->pos;

                $dotAll = $this->dotAll[count($this->dotAll) - 1];

                return [$dotAll ? self::ANY_CODE_POINT : self::LINE_TERMINATORS_EXCLUDED, false];
            case '(':
                return $this->parseGroup();
            case '[':
                return [$this->parseClass(), false];
            case '\\':
                return [$this->parseAtomEscape(), false];
            case '*':
            case '+':
            case '?':
                throw self::error('nothing to repeat');
            case ')':
            case ']':
            case '{':
            case '}':
                throw self::error('lone "' . $c . '"');
        }
        ++$this->pos;

        return [self::literal(mb_ord((string) $c, 'UTF-8')), false];
    }

    /**
     * @return array{string, bool}
     */
    private function parseGroup(): array
    {
        ++$this->pos;
        $open = '(';
        $dotAll = null;

        if ($this->eat('?')) {
            if ($this->eat(':')) {
                $open = '(?:';
            } elseif ($this->eat('<')) {
                $this->parseGroupName();
                // Named groups are emitted as plain capturing groups: the numbering is identical
                // and URLPattern only uses positional captures.
            } else {
                [$open, $dotAll] = $this->parseModifiers();
            }
        }

        if (null !== $dotAll) {
            $this->dotAll[] = $dotAll;
        }
        [$body, $unbounded] = $this->parseDisjunction();
        $this->expect(')');
        if (null !== $dotAll) {
            array_pop($this->dotAll);
        }

        return [$open . $body . ')', $unbounded];
    }

    private function parseGroupName(): void
    {
        $name = '';
        while (null !== ($c = $this->peek()) && '>' !== $c) {
            if (!CodePoints::isValidNameCodePoint($c, '' === $name)) {
                throw self::error('invalid capture group name');
            }
            $name .= $c;
            ++$this->pos;
        }
        $this->expect('>');
        if ('' === $name) {
            throw self::error('invalid capture group name');
        }
        if (array_key_exists($name, $this->groupNames)) {
            throw self::error('duplicate capture group name "' . $name . '"');
        }
        $this->groupNames[$name] = true;
    }

    /**
     * Parses ES2025 pattern modifiers, e.g. `(?i:...)` or `(?-s:...)`.
     *
     * @return array{string, ?bool} the group opening and the dotAll state within the group
     */
    private function parseModifiers(): array
    {
        $add = '';
        $remove = '';
        $removing = false;
        while (null !== ($c = $this->peek()) && ':' !== $c) {
            ++$this->pos;
            if ('-' === $c && !$removing) {
                $removing = true;
                continue;
            }
            if (!str_contains(self::MODIFIER_FLAGS, $c) || str_contains($add . $remove, $c)) {
                throw self::error('invalid group');
            }
            $removing ? $remove .= $c : $add .= $c;
        }
        $this->expect(':');
        if ($removing && '' === $add . $remove) {
            throw self::error('invalid group');
        }

        $dotAll = match (true) {
            str_contains($add, 's') => true,
            str_contains($remove, 's') => false,
            default => null,
        };

        return ['(?' . $add . ('' === $remove ? '' : '-' . $remove) . ':', $dotAll];
    }

    private function parseAtomEscape(): string
    {
        ++$this->pos;
        $c = $this->peek();
        if (null === $c) {
            throw self::error('\\ at end of pattern');
        }

        $classEscape = $this->tryParseCharacterClassEscape();
        if (null !== $classEscape) {
            return $classEscape[1] ? '[' . $classEscape[0] . ']' : $classEscape[0];
        }

        if ('k' === $c || ('0' !== $c && ctype_digit($c))) {
            throw new InvalidPatternException('Backreferences are not supported in regular expression groups.');
        }

        return self::literal($this->parseCharacterEscape());
    }

    /**
     * Parses a CharacterClassEscape (`\d`, `\p{...}`, ...), the backslash being already consumed.
     *
     * @return array{string, bool}|null the PCRE equivalent, and whether it can be used inside a PCRE class
     */
    private function tryParseCharacterClassEscape(): ?array
    {
        $c = $this->peek();
        switch ($c) {
            case 'd':
                ++$this->pos;

                return [self::ASCII_DIGIT, true];
            case 'D':
                ++$this->pos;

                return ['[^' . self::ASCII_DIGIT . ']', false];
            case 'w':
                ++$this->pos;

                return [self::ASCII_WORD, true];
            case 'W':
                ++$this->pos;

                return ['[^' . self::ASCII_WORD . ']', false];
            case 's':
                ++$this->pos;

                return [self::WHITE_SPACE, true];
            case 'S':
                ++$this->pos;

                return ['[^' . self::WHITE_SPACE . ']', false];
            case 'p':
            case 'P':
                ++$this->pos;

                return ['\\' . $c . '{' . $this->parseUnicodeProperty() . '}', true];
        }

        return null;
    }

    private function parseUnicodeProperty(): string
    {
        $this->expect('{');
        $name = '';
        $value = null;
        while (null !== ($c = $this->peek()) && '}' !== $c) {
            ++$this->pos;
            if ('=' === $c && null === $value) {
                $value = '';
                continue;
            }
            if (!ctype_alnum($c) && '_' !== $c) {
                throw self::error('invalid property name');
            }
            null === $value ? $name .= $c : $value .= $c;
        }
        $this->expect('}');

        if (null !== $value) {
            if (!array_key_exists($name, self::NON_BINARY_PROPERTIES) || '' === $value) {
                throw self::error('invalid property name');
            }

            return self::NON_BINARY_PROPERTIES[$name] . $value;
        }
        if (in_array($name, self::PROPERTIES_OF_STRINGS, true)) {
            throw new InvalidPatternException('Properties of strings (\\p{' . $name . '}) are not supported.');
        }
        if ('' === $name) {
            throw self::error('invalid property name');
        }

        return $name;
    }

    /**
     * Parses a CharacterEscape or an IdentityEscape, the backslash being already consumed.
     *
     * @param string $identityEscapes the code points that may be escaped to stand for themselves
     */
    private function parseCharacterEscape(string $identityEscapes = self::SYNTAX_CHARACTERS): int
    {
        // Not through peek(): PHPStan would remember its narrowed result across the position change.
        $c = $this->input[$this->pos] ?? '';
        ++$this->pos;

        if (array_key_exists($c, self::CONTROL_ESCAPES)) {
            return self::CONTROL_ESCAPES[$c];
        }

        if ('c' === $c) {
            $letter = $this->peek();
            if (null === $letter || 1 !== preg_match('/^[A-Za-z]$/', $letter)) {
                throw self::error('invalid escape');
            }
            ++$this->pos;

            return mb_ord($letter, 'UTF-8') % 32;
        }

        if ('0' === $c) {
            if (null !== ($next = $this->peek()) && ctype_digit($next)) {
                throw self::error('invalid decimal escape');
            }

            return 0;
        }

        if ('x' === $c) {
            return (int) hexdec($this->parseHexDigits(2));
        }

        if ('u' === $c) {
            return $this->parseUnicodeEscape();
        }

        if (str_contains($identityEscapes, $c)) {
            return mb_ord($c, 'UTF-8');
        }

        throw self::error('invalid escape "\\' . $c . '"');
    }

    private function parseUnicodeEscape(): int
    {
        if ($this->eat('{')) {
            $hex = '';
            while (null !== ($c = $this->peek()) && '}' !== $c) {
                $hex .= $c;
                ++$this->pos;
            }
            $this->expect('}');
            if ('' === $hex || !ctype_xdigit($hex) || hexdec($hex) > 0x10FFFF) {
                throw self::error('invalid Unicode escape');
            }

            return (int) hexdec($hex);
        }

        $codeUnit = (int) hexdec($this->parseHexDigits(4));
        if ($codeUnit >= 0xD800 && $codeUnit <= 0xDBFF && '\\' === $this->peek() && 'u' === $this->peek(1)) {
            $start = $this->pos;
            $this->pos += 2;
            $trail = (int) hexdec($this->parseHexDigits(4));
            if ($trail >= 0xDC00 && $trail <= 0xDFFF) {
                return 0x10000 + (($codeUnit - 0xD800) << 10) + ($trail - 0xDC00);
            }
            $this->pos = $start;
        }
        if ($codeUnit >= 0xD800 && $codeUnit <= 0xDFFF) {
            throw new InvalidPatternException('Lone surrogates are not supported in regular expressions.');
        }

        return $codeUnit;
    }

    private function parseHexDigits(int $count): string
    {
        $hex = implode('', \array_slice($this->input, $this->pos, $count));
        if (\strlen($hex) !== $count || !ctype_xdigit($hex)) {
            throw self::error('invalid escape');
        }
        $this->pos += $count;

        return $hex;
    }

    /**
     * Parses a `v`-mode character class and returns a PCRE expression matching a single code point
     * (or one of the strings of a `\q{...}` disjunction).
     */
    private function parseClass(): string
    {
        $this->expect('[');
        $negated = $this->eat('^');

        if ($this->eat(']')) {
            return $negated ? self::ANY_CODE_POINT : self::NEVER_MATCH;
        }

        $first = $this->parseClassSetOperand();

        if ('&' === $this->peek() && '&' === $this->peek(1)) {
            return $this->parseClassSetOperation($first, '&', $negated);
        }
        if ('-' === $this->peek() && '-' === $this->peek(1)) {
            return $this->parseClassSetOperation($first, '-', $negated);
        }

        return $this->parseClassUnion($first, $negated);
    }

    /**
     * @param ClassSetOperand $first
     */
    private function parseClassUnion(ClassSetOperand $first, bool $negated): string
    {
        $body = '';
        $matchers = [];
        $strings = [];
        $operand = $first;

        while (true) {
            if (null !== $operand->codePoint && '-' === $this->peek() && '-' !== $this->peek(1)) {
                ++$this->pos;
                $to = $this->parseClassSetOperand();
                if (null === $to->codePoint || $to->codePoint < $operand->codePoint) {
                    throw self::error('invalid character class range');
                }
                $body .= self::literal($operand->codePoint) . '-' . self::literal($to->codePoint);
            } elseif (null !== $operand->codePoint) {
                $body .= self::literal($operand->codePoint);
            } elseif (null !== $operand->classBody) {
                $body .= $operand->classBody;
            } elseif (null !== $operand->strings) {
                foreach ($operand->strings as $string) {
                    $strings[] = $string;
                }
            } else {
                $matchers[] = (string) $operand->matcher;
            }

            if ($this->eat(']')) {
                break;
            }
            if (
                ('&' === $this->peek() && '&' === $this->peek(1))
                || ('-' === $this->peek() && '-' === $this->peek(1))
            ) {
                throw self::error('invalid set operation in character class');
            }
            $operand = $this->parseClassSetOperand();
        }

        // Single code point strings can live in the class body; longer strings are matched first.
        $multiCodePointStrings = [];
        foreach ($strings as $string) {
            if (1 === count($string)) {
                $body .= self::literal($string[0]);
            } else {
                $multiCodePointStrings[] = $string;
            }
        }
        if ($negated && [] !== $multiCodePointStrings) {
            throw self::error('negated character class may contain strings');
        }
        usort($multiCodePointStrings, static fn (array $a, array $b): int => count($b) <=> count($a));

        $alternatives = array_map(
            static fn (array $string): string => implode('', array_map(self::literal(...), $string)),
            $multiCodePointStrings,
        );
        if ('' !== $body) {
            $alternatives[] = '[' . $body . ']';
        }
        foreach ($matchers as $matcher) {
            $alternatives[] = $matcher;
        }

        if ($negated) {
            if ([] === $matchers) {
                return '[^' . $body . ']';
            }

            return '(?:(?!' . implode('|', $alternatives) . ')' . self::ANY_CODE_POINT . ')';
        }

        if (1 === count($alternatives) && [] === $multiCodePointStrings) {
            return $alternatives[0];
        }

        return [] === $alternatives ? self::NEVER_MATCH : '(?:' . implode('|', $alternatives) . ')';
    }

    /**
     * Parses a ClassIntersection (`&&`) or a ClassSubtraction (`--`), translated with lookaheads.
     */
    private function parseClassSetOperation(ClassSetOperand $first, string $operator, bool $negated): string
    {
        $base = $first->toMatcher();
        $lookaheads = '';
        while ($this->peek() === $operator && $this->peek(1) === $operator) {
            $this->pos += 2;
            $operand = $this->parseClassSetOperand();
            $lookaheads .= ('&' === $operator ? '(?=' : '(?!') . $operand->toMatcher() . ')';
        }
        $this->expect(']');

        $matcher = '(?:' . $lookaheads . $base . ')';

        return $negated ? '(?:(?!' . $matcher . ')' . self::ANY_CODE_POINT . ')' : $matcher;
    }

    private function parseClassSetOperand(): ClassSetOperand
    {
        $c = $this->peek();
        if (null === $c) {
            throw self::error('unterminated character class');
        }

        if ('[' === $c) {
            return ClassSetOperand::matcher($this->parseClass());
        }

        if ('\\' === $c) {
            ++$this->pos;
            if ('q' === $this->peek() && '{' === $this->peek(1)) {
                $this->pos += 2;

                return ClassSetOperand::strings($this->parseClassStringDisjunction());
            }
            $classEscape = $this->tryParseCharacterClassEscape();
            if (null !== $classEscape) {
                return $classEscape[1]
                    ? ClassSetOperand::classBody($classEscape[0])
                    : ClassSetOperand::matcher($classEscape[0]);
            }
            if ($this->eat('b')) {
                return ClassSetOperand::codePoint(0x08);
            }

            return ClassSetOperand::codePoint(
                $this->parseCharacterEscape(self::SYNTAX_CHARACTERS . self::CLASS_SET_RESERVED_PUNCTUATORS),
            );
        }

        if (str_contains(self::CLASS_SET_SYNTAX_CHARACTERS, $c)) {
            throw self::error('invalid character "' . $c . '" in character class');
        }
        if (str_contains(self::CLASS_SET_RESERVED_DOUBLE_PUNCTUATORS, $c) && $this->peek(1) === $c) {
            throw self::error('invalid set operation in character class');
        }
        ++$this->pos;

        return ClassSetOperand::codePoint(mb_ord($c, 'UTF-8'));
    }

    /**
     * Parses the strings of a `\q{...}` ClassStringDisjunction, the `\q{` being already consumed.
     *
     * @return list<list<int>> each string as a list of code points
     */
    private function parseClassStringDisjunction(): array
    {
        $strings = [];
        $current = [];
        while (true) {
            $c = $this->peek();
            if (null === $c) {
                throw self::error('unterminated class string disjunction');
            }
            ++$this->pos;
            if ('}' === $c) {
                $strings[] = $current;

                return $strings;
            }
            if ('|' === $c) {
                $strings[] = $current;
                $current = [];
                continue;
            }
            if ('\\' === $c) {
                $current[] = $this->eat('b')
                    ? 0x08
                    : $this->parseCharacterEscape(self::SYNTAX_CHARACTERS . self::CLASS_SET_RESERVED_PUNCTUATORS);
                continue;
            }
            if (str_contains(self::CLASS_SET_SYNTAX_CHARACTERS, $c)) {
                throw self::error('invalid character "' . $c . '" in class string disjunction');
            }
            $current[] = mb_ord($c, 'UTF-8');
        }
    }

    /**
     * Emits a code point as a PCRE literal. Anything but ASCII alphanumerics is hex-escaped,
     * which makes the output safe whatever the context (inside or outside a class) and delimiter.
     */
    public static function literal(int $codePoint): string
    {
        if ($codePoint >= 0 && $codePoint < 0x80 && ctype_alnum(\chr($codePoint))) {
            return \chr($codePoint);
        }

        return '\x{' . strtoupper(dechex($codePoint)) . '}';
    }

    private function peek(int $offset = 0): ?string
    {
        return $this->input[$this->pos + $offset] ?? null;
    }

    private function eat(string $c): bool
    {
        if ($this->peek() !== $c) {
            return false;
        }
        ++$this->pos;

        return true;
    }

    private function expect(string $c): void
    {
        if (!$this->eat($c)) {
            throw self::error('expected "' . $c . '"');
        }
    }

    private static function error(string $reason): InvalidPatternException
    {
        return new InvalidPatternException('Invalid regular expression: ' . $reason . '.');
    }
}
