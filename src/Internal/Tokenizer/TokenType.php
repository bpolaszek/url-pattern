<?php

declare(strict_types=1);

namespace BenTools\UrlPattern\Internal\Tokenizer;

/**
 * @internal
 */
enum TokenType
{
    case Open;
    case Close;
    case Regexp;
    case Name;
    case Char;
    case EscapedChar;
    case OtherModifier;
    case Asterisk;
    case End;
    case InvalidChar;
}
