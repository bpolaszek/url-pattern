# UrlPattern

[![CI Workflow](https://github.com/bpolaszek/url-pattern/actions/workflows/ci-workflow.yml/badge.svg)](https://github.com/bpolaszek/url-pattern/actions/workflows/ci-workflow.yml)
[![Coverage](https://codecov.io/gh/bpolaszek/url-pattern/branch/main/graph/badge.svg)](https://codecov.io/gh/bpolaszek/url-pattern)
[![License](https://poser.pugx.org/bentools/url-pattern/license)](https://packagist.org/packages/bentools/url-pattern)
[![Total Downloads](https://poser.pugx.org/bentools/url-pattern/downloads)](https://packagist.org/packages/bentools/url-pattern)

A PHP implementation of the [WHATWG URL Pattern Standard](https://urlpattern.spec.whatwg.org/)
(the `URLPattern` web API), validated against the official
[web-platform-tests](https://github.com/web-platform-tests/wpt/tree/master/urlpattern) suite.

```php
use BenTools\UrlPattern\URLPattern;

$pattern = new URLPattern('https://example.com/books/:id');

$pattern->test('https://example.com/books/42');   // true
$pattern->exec('https://example.com/books/42')?->pathname->groups['id']; // "42"
$pattern->test('foo');                              // false (never throws on invalid URLs)
```

**WPT conformance: 369/369** cases of
[`urlpatterntestdata.json`](tests/Fixtures/README.md) pass on PHP 8.3, 8.4 and 8.5, with no skipped case.

## Installation

```bash
composer require bentools/url-pattern
```

Requirements: PHP >= 8.3 with `ctype` and `mbstring`. `intl` is recommended on PHP < 8.5 (faster IDNA).

## Usage

### Creating a pattern

```php
use BenTools\UrlPattern\URLPattern;

// From a constructor string
$pattern = new URLPattern('https://*.example.com/books/:id(\\d+)');

// From a constructor string, relative to a base URL
$pattern = new URLPattern('/books/:id', 'https://example.com');

// From component patterns (missing components default to the "*" wildcard)
$pattern = new URLPattern(['hostname' => '{*.}?example.com', 'pathname' => '/books/:id']);

// With options
$pattern = new URLPattern('https://example.com/Books/:id', options: ['ignoreCase' => true]);
```

An invalid pattern throws `BenTools\UrlPattern\Exception\InvalidPatternException`
(the equivalent of the JavaScript `TypeError`).

The normalized component patterns are exposed as read-only properties:

```php
$pattern = new URLPattern('https://example.com/books/:id');

$pattern->protocol;        // "https"
$pattern->username;        // "*"
$pattern->password;        // "*"
$pattern->hostname;        // "example.com"
$pattern->port;            // ""
$pattern->pathname;        // "/books/:id"
$pattern->search;          // "*"
$pattern->hash;            // "*"
$pattern->hasRegExpGroups; // false
```

### Matching

`test()` and `exec()` accept a URL string (with an optional base URL) or an array of component values.
**They never throw on an invalid URL**: they return `false` / `null`. The only exception is a programming
error: passing a base URL along with an array input throws an `InvalidArgumentException`.

```php
$pattern = new URLPattern(['pathname' => '/books/:id/:section?']);

$pattern->test('https://example.com/books/42');                // true
$pattern->test('/books/42/reviews', 'https://example.com');    // true
$pattern->test(['pathname' => '/books/42']);                   // true
$pattern->test('not a url');                                   // false

$result = $pattern->exec('https://example.com/books/42');
$result->inputs;                      // ["https://example.com/books/42"]
$result->pathname->input;             // "/books/42"
$result->pathname->groups;            // ["id" => "42", "section" => null]
$result->hostname->groups;            // [0 => "example.com"] (anonymous wildcard group)
```

`URLPatternResult` exposes `inputs`, plus one `URLPatternComponentResult` (`input`, `groups`) per component:
`protocol`, `username`, `password`, `hostname`, `port`, `pathname`, `search`, `hash`. A group that did not
participate in the match is `null` (`undefined` in JavaScript). Anonymous groups are numbered from `0`, and,
PHP being PHP, their keys are integers.

### Options

| Option           | Type | Default   | Description                                                          |
|------------------|------|-----------|----------------------------------------------------------------------|
| `ignoreCase`     | bool | `false`   | Case-insensitive matching (pathname, search and hash, as in the spec) |
| `backtrackLimit` | int (>= 1) | `100_000` | Maximum PCRE work per component match, see [Security](#security-redos) |

An invalid option value throws an `InvalidPatternException`.

### Performance

Patterns are compiled once, in the constructor. `URLPattern` instances are immutable (`readonly`) and can be
shared and cached freely. There is no global cache in the library: if you build patterns from user input,
keep an LRU cache of compiled instances on your side.

Indicative figures (Apple Silicon, `https://example.com/books/:id`):

| PHP | URL parser                        | `new URLPattern()` | `test()` (match) | `test('foo')` |
|-----|-----------------------------------|--------------------|------------------|---------------|
| 8.3 | `rowbot/url` (via polyfill)       | ~630 µs            | ~75 µs           | ~6 µs         |
| 8.4 | `rowbot/url` (via polyfill)       | ~500 µs            | ~90 µs           | ~9 µs         |
| 8.5 | native `Uri\WhatWg\Url` (lexbor)  | ~155 µs            | ~4 µs            | ~0.2 µs       |

Matching a URL string is dominated by URL parsing: on PHP < 8.5, matching against an array of components
(which skips the parser), or upgrading to PHP 8.5, is much faster.

## URL parser

The encoding callbacks of the specification rely on the
[WHATWG URL parser](https://url.spec.whatwg.org/). This library uses `Uri\WhatWg\Url`, which is native since
PHP 8.5, and backported to PHP 8.3/8.4 by
[`league/uri-polyfill`](https://github.com/thephpleague/uri-polyfill) (itself built on
[`rowbot/url`](https://github.com/TRowbotham/URL-Parser)). On PHP 8.5, the polyfill is inert and the native
implementation is used.

Why `league/uri-polyfill` rather than `rowbot/url` directly: a single API (`Uri\WhatWg\Url`) across versions,
and the native, much faster parser on PHP 8.5, while the polyfill still delegates to `rowbot/url`, a WPT-compliant
parser, on older versions. PSR-7 / RFC 3986 URI libraries are not suitable: URL patterns require WHATWG
semantics (IDNA, IPv4 normalization, backslashes, special schemes...).

`Uri\WhatWg\Url` does not expose the "state override" entry points of the URL parser that the encoding callbacks
use, so the corresponding parser states (port, path, opaque path, query, fragment, userinfo encoding) are
reimplemented in `Internal\Canonicalizer`; the URL parser is used for scheme validation and host parsing.

The native parser of PHP 8.5.1 deviates from the URL standard in two places, which the library normalizes
itself:

- it does not lowercase percent-decoded code points of special hosts (`https://%41.com/` yields `A.com`
  instead of `a.com`);
- it does not percent-encode `^` in paths (`https://example.com/a^b` keeps `/a^b` instead of `/a%5Eb`).

## Differences with the specification

The specification compiles patterns into ECMAScript regular expressions (with the `v` flag). PHP has no
ECMAScript regex engine, so the generated expressions are translated to PCRE2 (`/u`). The translator validates
the ECMAScript syntax (it rejects what a JavaScript engine rejects, e.g. `\m`, `(?R)` or `\H`, which PCRE would
accept) and preserves the ECMAScript semantics where PCRE differs:

| Topic | Behavior |
|---|---|
| `.` | Translated to "any code point but `\n`, `\r`, U+2028, U+2029", like JavaScript (or any code point under `(?s:)`). |
| `\s`, `\S` | Translated to the ECMAScript white space + line terminator set. |
| `\d`, `\w`, `\b` | ASCII, like JavaScript in Unicode mode. With `ignoreCase`, JavaScript's `\w` also matches U+017F and U+212A; PCRE does not. |
| `v`-flag set operations (`[A--B]`, `[A&&B]`, nested classes) | Supported, translated with lookaheads. |
| `\q{...}` string disjunctions | Supported in class unions only; rejected in negated classes, intersections and subtractions. |
| Properties of strings (`\p{RGI_Emoji}`...) | **Not supported**: `InvalidPatternException`. |
| `\p{...}` names | Passed to PCRE2, which matches property names loosely: some names JavaScript rejects (e.g. different casing) are accepted. |
| Lookbehind | PCRE2 requires a bounded length (variable-length lookbehind needs PCRE2 >= 10.43, i.e. a recent PHP). |
| Backreferences (`\1`, `\k<name>`) | **Not supported** (rejected by the ReDoS guard). |
| Lone surrogates in regexps (`\uD800`) | **Not supported**: PHP strings are UTF-8 and cannot contain them. |
| Duplicate named groups in different alternatives (ES2025) | Rejected. |
| ReDoS guard | Some valid patterns are rejected, see below. |
| Backtracking limit | A match exceeding the limit is reported as no match. |

Other notes:

- Strings are converted like WebIDL `USVString`s: invalid UTF-8 sequences become U+FFFD.
- Unknown keys of an init array are ignored (like JavaScript dictionaries); non-string values are rejected.
- The `undefined` value of a group is `null`.

## Security (ReDoS)

URL patterns may come from untrusted sources (e.g. Mercure subscribers), and a malicious regular expression can
make a backtracking engine run for ages, freezing a single-threaded event loop. The library defends in depth:

1. **Linear-time guard** (at construction): user-provided regexp groups whose structure is prone to
   catastrophic backtracking are rejected with an `InvalidPatternException`. That is a repeated sub-expression
   (`*`, `+`, `{n,}`, `{n,m}` with m > 1, or a URLPattern `*`/`+` modifier) containing an unbounded quantifier,
   e.g. `(a+)+`, `(?:a*)*`, `((a+)){2,}`. Backreferences are rejected too. This is a deliberate deviation from
   the specification. It is a heuristic, not a proof of linearity, hence the next layer.
2. **Bounded matching** (at match time): every `test()`/`exec()` call temporarily sets `pcre.backtrack_limit`
   (PCRE2's match limit) to the `backtrackLimit` option, and restores the previous value afterwards. The limit
   applies to each component match: with the default of 100,000, a hostile component costs about a millisecond
   with the PCRE JIT, and a whole call at most 8 times that. The limit is also enforced by the JIT, which therefore
   stays enabled. **Without the JIT** (`pcre.jit=0`, or a platform refusing executable memory), each unit of the
   limit is far more expensive: a hostile component can cost about half a second with the default limit, so lower
   `backtrackLimit` accordingly (e.g. 10,000) or keep the JIT enabled. Note that patterns without any regexp group
   can be polynomial or even exponential too (e.g. `*a*a*a*b`, or `/x:a+`, which behaves like `(a+)+`); this
   layer covers them.
3. **Fail as no match**: any PCRE error (limit exhausted, JIT stack limit, ...) results in `false` / `null`,
   never in an exception or a warning.

If `ini_set()` is disabled on your platform, the process-wide `pcre.backtrack_limit` applies instead (PHP's
default is 1,000,000).

Recommendations for untrusted patterns:

- Cache compiled patterns (LRU) instead of compiling them on every request.
- Bound the length of patterns and URLs you accept.
- If you do not need custom regular expressions, reject patterns where `$pattern->hasRegExpGroups` is `true`,
  as the specification itself suggests. This narrows the attack surface, but does not replace the backtracking
  limit (see the regexp-free examples above).

## Development

```bash
composer ci:check   # composer validate, phpcs (PSR-12), PHPStan (max level), Pest with 100% coverage
```

The WPT fixtures are vendored in [`tests/Fixtures`](tests/Fixtures/README.md).

## License

MIT.
