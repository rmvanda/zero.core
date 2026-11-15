<?php

namespace Zero\Tests\Core;

use PHPUnit\Framework\TestCase;
use Zero\Core\AdminResponse;

/**
 * AdminResponse finds a module's real directory whatever casing or hyphenation
 * the caller used for its name.
 */
class AdminAssetResolverTest extends TestCase
{
    private function resolve(string $name): ?string
    {
        $method = new \ReflectionMethod(AdminResponse::class, 'resolveModuleDir');

        return $method->invoke(null, $name, dirname(__DIR__, 2) . '/modules/');
    }

    public function testMultiWordModuleResolvesInAnyForm(): void
    {
        foreach (['ApiConfig', 'apiconfig', 'api-config'] as $name) {
            $this->assertSame('ApiConfig', $this->resolve($name), $name);
        }
    }

    public function testSingleWordModuleResolvesInAnyCase(): void
    {
        foreach (['Links', 'links', 'LINKS'] as $name) {
            $this->assertSame('Links', $this->resolve($name), $name);
        }
        $this->assertSame('Admin', $this->resolve('admin'));
        $this->assertSame('S', $this->resolve('s'));
    }

    public function testUnknownModuleResolvesToNothing(): void
    {
        $this->assertNull($this->resolve('no-such-module'));
        $this->assertNull($this->resolve(''));
        $this->assertNull($this->resolve('../etc'));
    }
}
