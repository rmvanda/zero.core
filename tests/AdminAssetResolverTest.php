<?php

namespace Zero\Tests\Core;

use PHPUnit\Framework\TestCase;
use Zero\Core\Modules;

/**
 * Admin asset loaders find a module's real directory name through
 * Modules::name(), whatever casing or hyphenation the caller used.
 */
class AdminAssetResolverTest extends TestCase
{
    protected function setUp(): void
    {
        Modules::configure([dirname(__DIR__, 2) . '/modules/', dirname(__DIR__, 2) . '/core/modules/'], null);
    }

    protected function tearDown(): void
    {
        zeroTestConfigureModules();
    }

    private function resolve(string $name): ?string
    {
        return Modules::name($name);
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
