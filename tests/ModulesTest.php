<?php

namespace Zero\Tests\Core;

use PHPUnit\Framework\TestCase;
use Zero\Core\Modules;

/**
 * Modules knows where a module lives, which tier it is and whether it is on.
 * Every test builds throwaway module trees and hands them to configure().
 */
class ModulesTest extends TestCase
{
    private string $base;
    private string $site;
    private string $bundled;
    private string $ini;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/zerotest-modules-' . bin2hex(random_bytes(4));
        $this->site = $this->base . '/site/';
        $this->bundled = $this->base . '/bundled/';
        $this->ini = $this->base . '/modules.ini';
        mkdir($this->site, 0777, true);
        mkdir($this->bundled, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->remove($this->base);
    }

    private function remove(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);
            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->remove($path . '/' . $entry);
            }
        }
        rmdir($path);
    }

    private function make(string $root, string ...$names): void
    {
        foreach ($names as $name) {
            mkdir($root . $name, 0777, true);
        }
    }

    private function configure(?string $ini = null): void
    {
        Modules::configure([$this->site, $this->bundled], $ini);
    }

    private function writeIni(string $content): void
    {
        file_put_contents($this->ini, $content);
    }

    public function testClashOnIdenticalNameThrowsNamingBothPaths(): void
    {
        $this->make($this->site, 'Auth');
        $this->make($this->bundled, 'Auth');
        $this->configure();

        try {
            Modules::assertNoClash();
            $this->fail('expected a RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString($this->site . 'Auth', $e->getMessage());
            $this->assertStringContainsString($this->bundled . 'Auth', $e->getMessage());
        }
    }

    public function testClashIgnoresCase(): void
    {
        $this->make($this->site, 'auth');
        $this->make($this->bundled, 'Auth');
        $this->configure();

        $this->expectException(\RuntimeException::class);
        Modules::assertNoClash();
    }

    public function testNoClashWhenNamesDiffer(): void
    {
        $this->make($this->site, 'Lists');
        $this->make($this->bundled, 'Auth');
        $this->configure();

        Modules::assertNoClash();
        $this->addToAssertionCount(1);
    }

    public function testMissingRootIsSkipped(): void
    {
        $this->make($this->site, 'Lists');
        Modules::configure([$this->site, $this->base . '/nowhere/'], null);

        Modules::assertNoClash();
        $this->assertSame(['Lists'], array_keys(Modules::all()));
    }

    public function testPathMatchesAnySpelling(): void
    {
        $this->make($this->site, 'ApiConfig');
        $this->configure();

        $expected = $this->site . 'ApiConfig/';
        foreach (['ApiConfig', 'api-config', 'apiconfig', 'APICONFIG'] as $name) {
            $this->assertSame($expected, Modules::path($name), $name);
            $this->assertSame('ApiConfig', Modules::name($name), $name);
        }
    }

    public function testPathUnknownOrTraversalIsNull(): void
    {
        $this->make($this->site, 'Lists');
        $this->configure();

        $this->assertNull(Modules::path('Nope'));
        $this->assertNull(Modules::path('../etc'));
        $this->assertNull(Modules::path(''));
        $this->assertNull(Modules::name('Nope'));
    }

    public function testPathFindsModuleInSecondRoot(): void
    {
        $this->make($this->bundled, 'Auth');
        $this->configure();

        $this->assertSame($this->bundled . 'Auth/', Modules::path('auth'));
    }

    public function testLowercaseDirectoryIsNotAModule(): void
    {
        $this->make($this->site, 'vendor', 'Lists');
        $this->configure();

        $this->assertSame(['Lists'], array_keys(Modules::all()));
        $this->assertNull(Modules::path('vendor'));
    }

    public function testTiers(): void
    {
        $this->configure();

        foreach (['Auth', 'User', 'Admin', 'AccessRequest'] as $name) {
            $this->assertSame('required', Modules::tier($name), $name);
        }
        foreach (['Sitemap', 'Push', 'Entity'] as $name) {
            $this->assertSame('optional', Modules::tier($name), $name);
        }
        foreach (['Module', 'HelloWorld', 'Test'] as $name) {
            $this->assertSame('example', Modules::tier($name), $name);
        }
        $this->assertSame('site', Modules::tier('Lists'));
    }

    public function testEnabledDefaultsWithoutIni(): void
    {
        $this->make($this->site, 'Lists');
        $this->make($this->bundled, 'Auth', 'Sitemap', 'HelloWorld');
        $this->configure($this->ini);

        $this->assertTrue(Modules::enabled('Auth'));
        $this->assertTrue(Modules::enabled('Sitemap'));
        $this->assertFalse(Modules::enabled('HelloWorld'));
        $this->assertTrue(Modules::enabled('Lists'));
        $this->assertFalse(Modules::enabled('Nope'));
        $this->assertNull(Modules::order('Links'));
    }

    public function testEnabledFollowsIni(): void
    {
        $this->make($this->site, 'Lists');
        $this->make($this->bundled, 'Auth', 'Sitemap', 'HelloWorld');
        $this->writeIni("[enabled]\nSitemap = off\nHelloWorld = on\nAuth = off\nLists = off\nGhost = off\n");
        $this->configure($this->ini);

        $this->assertFalse(Modules::enabled('Sitemap'));
        $this->assertTrue(Modules::enabled('HelloWorld'));
        $this->assertTrue(Modules::enabled('Auth'));
        $this->assertFalse(Modules::enabled('Lists'));
        $this->assertFalse(Modules::enabled('Ghost'));
    }

    public function testRoutableNeedsExistsAndEnabled(): void
    {
        $this->make($this->site, 'Lists', 'Gone');
        $this->writeIni("[enabled]\nGone = off\n");
        $this->configure($this->ini);

        $this->assertTrue(Modules::routable('lists'));
        $this->assertFalse(Modules::routable('Gone'));
        $this->assertFalse(Modules::routable('Nope'));
    }

    public function testOrderComesFromIni(): void
    {
        $this->make($this->site, 'Links', 'Lists');
        $this->writeIni("[order]\nLinks = 10\n");
        $this->configure($this->ini);

        $this->assertSame(10, Modules::order('Links'));
        $this->assertNull(Modules::order('Lists'));
    }

    public function testDropDisabledAssetLinksRemovesOnlyDisabledSymlinks(): void
    {
        $this->make($this->site, 'Lists', 'Gone', 'Real');
        $this->writeIni("[enabled]\nGone = off\nReal = off\n");
        $this->configure($this->ini);

        $web = $this->base . '/www';
        mkdir($web . '/assets/real', 0777, true);
        symlink($this->site . 'Gone', $web . '/assets/gone');
        symlink($this->site . 'Lists', $web . '/assets/lists');

        Modules::dropDisabledAssetLinks($web);

        $this->assertFalse(is_link($web . '/assets/gone'));
        $this->assertTrue(is_link($web . '/assets/lists'));
        $this->assertTrue(is_dir($web . '/assets/real') && !is_link($web . '/assets/real'));
    }
}
