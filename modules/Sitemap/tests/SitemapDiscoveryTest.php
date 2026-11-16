<?php

namespace Zero\Module\Sitemap\Tests;

use PHPUnit\Framework\TestCase;
use Zero\Core\Modules;

/**
 * The sitemap lists the modules Modules says are switched on, from every root,
 * and loads no controller of a module that is off.
 */
class SitemapDiscoveryTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/zerotest-sitemap-' . bin2hex(random_bytes(4));
        foreach (['site/Zalpha/view', 'site/Zoff/view', 'bundled/Zbeta/view'] as $dir) {
            mkdir("{$this->base}/{$dir}", 0777, true);
            $name = basename(dirname($dir));
            file_put_contents("{$this->base}/{$dir}/page.php", '');
            // The controller file only records that it was included
            file_put_contents("{$this->base}/" . dirname($dir) . "/{$name}.php", "<?php\n\$GLOBALS['zerotest_sitemap_loaded'][] = '{$name}';\n");
        }
        file_put_contents($this->base . '/modules.ini', "[enabled]\nZoff = off\n");
        Modules::configure([$this->base . '/site/', $this->base . '/bundled/'], $this->base . '/modules.ini');
        $GLOBALS['zerotest_sitemap_loaded'] = [];
        spl_autoload_register([\Zero\Core\Application::class, 'autoloader']);
    }

    protected function tearDown(): void
    {
        spl_autoload_unregister([\Zero\Core\Application::class, 'autoloader']);
        unset($GLOBALS['zerotest_sitemap_loaded']);
        $this->remove($this->base);
        zeroTestConfigureModules();
    }

    private function remove(string $path): void
    {
        if (is_file($path) || is_link($path)) {
            unlink($path);
            return;
        }
        foreach (scandir($path) as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->remove($path . '/' . $entry);
            }
        }
        rmdir($path);
    }

    public function testListsEnabledModulesOfBothRootsAndSkipsDisabledOnes(): void
    {
        $sitemap = new class extends \Zero\Module\Sitemap {
            public function __construct() {}
            public function __destruct() {}
        };
        $discover = new \ReflectionMethod(\Zero\Module\Sitemap::class, 'discoverModulesWithRoutes');
        $modules = $discover->invoke($sitemap);

        $this->assertSame(['Zalpha', 'Zbeta'], array_column($modules, 'name'));
        $this->assertSame('/zalpha', $modules[0]['base_route']);
        $this->assertSame(['/zalpha', '/zalpha/page'], array_column($modules[0]['routes'], 'url'));
        // The enabled modules' controllers are loaded to read their methods;
        // the disabled one's never is.
        $this->assertNotContains('Zoff', $GLOBALS['zerotest_sitemap_loaded']);
    }
}
