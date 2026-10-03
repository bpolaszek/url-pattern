<?php

declare(strict_types=1);

namespace BenTools\UrlPattern\Internal\Parser;

/**
 * States of the constructor string parser. Component states are backed by their URLPatternInit key.
 *
 * @internal
 */
enum ConstructorState: string
{
    case Init = 'init';
    case Protocol = 'protocol';
    case Authority = 'authority';
    case Username = 'username';
    case Password = 'password';
    case Hostname = 'hostname';
    case Port = 'port';
    case Pathname = 'pathname';
    case Search = 'search';
    case Hash = 'hash';
    case Done = 'done';

    public const BEFORE_HOSTNAME = [self::Protocol, self::Authority, self::Username, self::Password];
    public const BEFORE_PATHNAME = [...self::BEFORE_HOSTNAME, self::Hostname, self::Port];
}
