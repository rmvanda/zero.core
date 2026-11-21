<?php
namespace Zero\Tests\Core;

/**
 * Calls a protected static method of the framework from a test.
 *
 * Methods such as Response::findModuleView(), Response::framePieceFile() and
 * Module::linkAssets() are protected, because a public method of a module is
 * an endpoint. A test that checks one of them directly calls it through here:
 *
 *     Reach::call(Response::class, 'framePieceFile', 'footer', $dirs);
 */
final class Reach
{
    public static function call(string $class, string $method, mixed ...$args): mixed
    {
        return (new \ReflectionMethod($class, $method))->invoke(null, ...$args);
    }
}
