<?php

namespace Zero\Tests\Core;

use PHPUnit\Framework\TestCase;
use Zero\Core\Application;
use Zero\Core\Module;
use Zero\Core\Modules;

/**
 * The ten modules every site gets live in core/modules/. The site's own
 * modules/ folder holds none of them, and nothing in core names a module only
 * unisolu has.
 */
class BundledModulesTest extends TestCase
{
    private const BUNDLED = ['Auth', 'User', 'Admin', 'AccessRequest', 'Push', 'Entity', 'Module', 'HelloWorld', 'Test'];

    private string $root;
    private ?string $tmp = null;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2) . '/';
        zeroTestConfigureModules();
    }

    protected function tearDown(): void
    {
        if ($this->tmp !== null) {
            exec('rm -rf ' . escapeshellarg($this->tmp));
            $this->tmp = null;
        }
        zeroTestConfigureModules();
    }

    private function tmpDir(): string
    {
        $this->tmp = sys_get_temp_dir() . '/zerotest-bundled-' . bin2hex(random_bytes(4));
        mkdir($this->tmp, 0777, true);

        return $this->tmp;
    }

    public function testEachBundledModuleResolvesIntoCore(): void
    {
        foreach (self::BUNDLED as $name) {
            $this->assertSame($this->root . 'core/modules/' . $name . '/', Modules::path($name), $name);
            $this->assertDirectoryDoesNotExist($this->root . 'modules/' . $name, "$name is not left in the site folder");
        }
    }

    public function testAControllerOfEachBundledModuleResolvesThroughTheNamespaceMap(): void
    {
        foreach (self::BUNDLED as $name) {
            if ($name === 'Entity') {
                continue;   // Entity is a library and an admin offering: it has no controller
            }
            $this->assertSame(
                $this->root . 'core/modules/' . $name . '/' . $name . '.php',
                Application::classFile('Zero\\Module\\' . $name),
                $name
            );
        }
        $this->assertSame(
            $this->root . 'core/modules/Entity/Admin/EntityAdmin.php',
            Application::classFile('Zero\\Module\\Entity\\Admin\\EntityAdmin')
        );
        $this->assertSame(
            $this->root . 'core/modules/Auth/model/AuthToken.php',
            Application::classFile('Zero\\Module\\Auth\\Model\\AuthToken')
        );
    }

    public function testNoBundledNameClashesWithASiteModule(): void
    {
        Modules::assertNoClash();
        $this->addToAssertionCount(1);
    }

    public function testLinkAssetsReplacesALinkWhoseTargetIsGone(): void
    {
        $dir = $this->tmpDir();
        mkdir("$dir/new-assets");
        mkdir("$dir/www/assets", 0777, true);
        symlink("$dir/old-place/assets", "$dir/www/assets/hello-world");

        $this->assertTrue(\Zero\Tests\Core\Reach::call(\Zero\Core\Module::class, 'linkAssets', 'HelloWorld', "$dir/new-assets", "$dir/www/assets/hello-world"));
        $this->assertSame("$dir/new-assets", readlink("$dir/www/assets/hello-world"));
    }

    public function testLinkAssetsLeavesALiveLinkADirectoryAndAFile(): void
    {
        $dir = $this->tmpDir();
        mkdir("$dir/target");
        mkdir("$dir/other");
        mkdir("$dir/www/assets/real", 0777, true);
        file_put_contents("$dir/www/assets/file", 'x');
        symlink("$dir/other", "$dir/www/assets/live");

        $this->assertFalse(\Zero\Tests\Core\Reach::call(\Zero\Core\Module::class, 'linkAssets', 'HelloWorld', "$dir/target", "$dir/www/assets/live"));
        $this->assertSame("$dir/other", readlink("$dir/www/assets/live"));
        $this->assertFalse(\Zero\Tests\Core\Reach::call(\Zero\Core\Module::class, 'linkAssets', 'HelloWorld', "$dir/target", "$dir/www/assets/real"));
        $this->assertFalse(is_link("$dir/www/assets/real"));
        $this->assertFalse(\Zero\Tests\Core\Reach::call(\Zero\Core\Module::class, 'linkAssets', 'HelloWorld', "$dir/target", "$dir/www/assets/file"));
        $this->assertSame('x', file_get_contents("$dir/www/assets/file"));
    }

    public function testADisabledModuleDoesNotReplaceADanglingLink(): void
    {
        $dir = $this->tmpDir();
        mkdir("$dir/new-assets");
        mkdir("$dir/www/assets", 0777, true);
        symlink("$dir/old-place/assets", "$dir/www/assets/ghost");

        $this->assertFalse(\Zero\Tests\Core\Reach::call(\Zero\Core\Module::class, 'linkAssets', 'NoSuchModuleAnywhere', "$dir/new-assets", "$dir/www/assets/ghost"));
        $this->assertSame("$dir/old-place/assets", readlink("$dir/www/assets/ghost"));
    }

    public function testCoreNamesNoModuleOnlyTheSiteHas(): void
    {
        $pattern = '#modules/(Share|Issue|Receipts|Lists|Notebook|Llm|Comfy)\b|Zero\\\\Module\\\\(Share|Issue|Receipts|Lists|Notebook|Llm|Comfy)\b#';
        $hits = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(
            $this->root . 'core',
            \FilesystemIterator::SKIP_DOTS
        ));
        foreach ($it as $file) {
            $path = $file->getPathname();
            if (!$file->isFile() || str_contains($path, '/tests/') || str_contains($path, '/node_modules/')
                || !in_array($file->getExtension(), ['php', 'js', 'css', 'ini', 'json', 'sh'], true)
                && basename($path) !== 'mkmod') {
                continue;
            }
            foreach (file($path) as $n => $line) {
                $trim = ltrim($line);
                // Comments, which may use the names as examples, are not code
                if (str_starts_with($trim, '//') || str_starts_with($trim, '*') || str_starts_with($trim, '#') || str_starts_with($trim, '/*')) {
                    continue;
                }
                if (preg_match($pattern, $line)) {
                    $hits[] = substr($path, strlen($this->root)) . ':' . ($n + 1);
                }
            }
        }
        $this->assertSame([], $hits, 'core names a site-only module');
    }

    public function testNoTrackedSymlinkIsAbsoluteOrDangling(): void
    {
        $bad = [];
        $seen = 0;
        foreach (['', 'core', 'modules'] as $sub) {
            $dir = rtrim($this->root . $sub, '/');
            if (!is_dir($dir . '/.git') && $sub !== '') {
                continue;
            }
            $out = [];
            exec('git -C ' . escapeshellarg($dir) . ' ls-files -s 2>/dev/null', $out);
            foreach ($out as $line) {
                if (strpos($line, '120000') !== 0) {
                    continue;
                }
                $path = $dir . '/' . substr($line, strpos($line, "\t") + 1);
                $seen++;
                $target = readlink($path);
                if ($target === false || $target[0] === '/' || !file_exists($path)) {
                    $bad[] = $path . ' -> ' . (string) $target;
                }
            }
        }
        $this->assertGreaterThan(0, $seen, "the scan found tracked symlinks");
        $this->assertSame([], array_values(array_unique($bad)), 'tracked symlinks must be relative and live');
    }
}
