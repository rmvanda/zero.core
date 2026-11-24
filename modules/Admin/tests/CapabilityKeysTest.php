<?php
namespace Zero\Core\Module\Admin\Tests;

use Zero\Core\Modules;
use Zero\Core\Module\Admin\Model\CapabilityKeys;
use Zero\Tests\Core\ZeroTestCase;

/**
 * The keys modules declare with #[RequirePermission] are read from every
 * module that is switched on, in every root; a module that is off adds none.
 */
class CapabilityKeysTest extends ZeroTestCase
{
    private string $base;

    protected function setUp(): void
    {
        parent::setUp();
        $this->base = sys_get_temp_dir() . '/zerotest-capkeys-' . bin2hex(random_bytes(4));
        $this->module('site/', 'OnSite', 'zerotest.onsite');
        $this->module('site/', 'OffSite', 'zerotest.offsite');
        $this->module('bundled/', 'OnBundled', 'zerotest.onbundled');
        file_put_contents($this->base . '/modules.ini', "[enabled]\nOffSite = off\n");
        Modules::configure([$this->base . '/site/', $this->base . '/bundled/'], $this->base . '/modules.ini');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->base . '/*/*/*.php') ?: [] as $file) {
            unlink($file);
        }
        foreach (glob($this->base . '/*/*') ?: [] as $dir) {
            rmdir($dir);
        }
        foreach (glob($this->base . '/*') ?: [] as $entry) {
            is_dir($entry) ? rmdir($entry) : unlink($entry);
        }
        rmdir($this->base);
        zeroTestConfigureModules();
        parent::tearDown();
    }

    private function module(string $root, string $name, string $key): void
    {
        mkdir($this->base . '/' . $root . $name, 0777, true);
        file_put_contents(
            $this->base . '/' . $root . $name . '/' . $name . '.php',
            "<?php\n#[RequirePermission('$key')]\nclass X {}\n"
        );
    }

    public function testDeclaredReadsEnabledModulesInEveryRootAndSkipsDisabledOnes(): void
    {
        $keys = CapabilityKeys::declared();

        $this->assertContains('zerotest.onsite', $keys);
        $this->assertContains('zerotest.onbundled', $keys);
        $this->assertNotContains('zerotest.offsite', $keys);
    }

    public function testExplicitDirectoriesAreReadWhateverTheirState(): void
    {
        $keys = CapabilityKeys::declared([$this->base . '/site/OffSite/']);

        $this->assertSame(['zerotest.offsite'], $keys);
    }
}
