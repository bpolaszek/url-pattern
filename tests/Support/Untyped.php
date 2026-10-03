<?php

declare(strict_types=1);

namespace BenTools\UrlPattern\Tests\Support;

use ReflectionClass;
use ReflectionMethod;

/**
 * Calls typed APIs with values their phpdoc forbids, as plain PHP callers (which are not statically analyzed) can.
 */
final class Untyped
{
    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    public static function instantiate(string $class, mixed ...$arguments): object
    {
        return (new ReflectionClass($class))->newInstance(...$arguments);
    }

    public static function call(object $object, string $method, mixed ...$arguments): mixed
    {
        return (new ReflectionMethod($object, $method))->invoke($object, ...$arguments);
    }
}
