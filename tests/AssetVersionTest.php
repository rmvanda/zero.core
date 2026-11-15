<?php

namespace Zero\Tests\Core;

use Zero\Core\Request;
use Zero\Core\Response;

/**
 * Module asset tags carry the file's version.
 *
 * Apache serves module scripts and stylesheets with no Cache-Control, so a
 * browser keeps a copy for about a tenth of the file's age when it fetched
 * it, and a normal reload doesn't ask again. A script last changed months
 * ago stays cached for weeks after a deploy replaces it, and the browser
 * runs it against the new markup. `?v=<mtime>` gives every version of a file
 * its own URL, so the page always loads the script it was written for.
 *
 * The fixture lives under a temporary WEB_ROOT: PHPUnit never defines the
 * site's, and the test must not write into the live document root.
 */
final class AssetVersionTest extends ZeroTestCase
{
    private string $dir;
    private array $backupRequest;

    /** Probe: Response with its constructor and destructor disarmed. */
    private function probe(): object
    {
        return new class extends Response {
            // Response::__construct() wants a routed request; skip it.
            public function __construct() {}

            // Response::__destruct() renders the page when nothing else has.
            public function __destruct() {}

            public function tags(string $type, string $dir): string
            {
                $method = new \ReflectionMethod(Response::class, 'loadAssetTypeFromDir');
                ob_start();
                $method->invoke($this, $type, $dir);
                return ob_get_clean();
            }
        };
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (!defined('WEB_ROOT')) {
            define('WEB_ROOT', sys_get_temp_dir() . '/zero-asset-version-test-root');
        }
        if (!str_starts_with(WEB_ROOT, sys_get_temp_dir())) {
            $this->markTestSkipped('WEB_ROOT is a real document root; this test writes a fixture under it');
        }

        $this->backupRequest = [Request::$module, Request::$moduleOrig, Request::$endpoint, Request::$endpointOrig];
        Request::$module = Request::$moduleOrig = 'widgets';
        Request::$endpoint = Request::$endpointOrig = 'view';

        $this->dir = WEB_ROOT . '/assets/widgets-' . getmypid() . '/';
        foreach (['js', 'css'] as $type) {
            @mkdir($this->dir . $type, 0777, true);
        }
        file_put_contents($this->dir . 'js/widgets.js', '// module script');
        file_put_contents($this->dir . 'js/view.js', '// endpoint script');
        file_put_contents($this->dir . 'js/other.js', '// matches nothing');
        file_put_contents($this->dir . 'css/view.css', '/* endpoint styles */');
        touch($this->dir . 'js/widgets.js', 1700000000);
        touch($this->dir . 'js/view.js', 1800000000);
        touch($this->dir . 'css/view.css', 1750000000);
    }

    protected function tearDown(): void
    {
        if (isset($this->dir)) {
            foreach (glob($this->dir . '{js,css}/*', GLOB_BRACE) as $file) {
                unlink($file);
            }
            @rmdir($this->dir . 'js');
            @rmdir($this->dir . 'css');
            @rmdir($this->dir);
        }
        if (isset($this->backupRequest)) {
            [Request::$module, Request::$moduleOrig, Request::$endpoint, Request::$endpointOrig] = $this->backupRequest;
        }
        parent::tearDown();
    }

    private function publicPath(string $file): string
    {
        return str_replace(WEB_ROOT, '', $this->dir) . $file;
    }

    public function testAScriptTagCarriesTheFilesModificationTime(): void
    {
        $html = $this->probe()->tags('js', $this->dir . 'js/');

        $this->assertStringContainsString('<script src="' . $this->publicPath('js/view.js') . '?v=1800000000"></script>', $html);
        $this->assertStringContainsString('<script src="' . $this->publicPath('js/widgets.js') . '?v=1700000000"></script>', $html);
    }

    public function testAStylesheetTagCarriesTheFilesModificationTime(): void
    {
        $html = $this->probe()->tags('css', $this->dir . 'css/');

        $this->assertStringContainsString('href="' . $this->publicPath('css/view.css') . '?v=1750000000"', $html);
    }

    public function testChangingAFileChangesItsUrl(): void
    {
        $before = $this->probe()->tags('js', $this->dir . 'js/');
        touch($this->dir . 'js/view.js', 1800000600);
        clearstatcache();
        $after = $this->probe()->tags('js', $this->dir . 'js/');

        $this->assertNotSame($before, $after);
        $this->assertStringContainsString('view.js?v=1800000600"', $after);
    }

    public function testAFileMatchingNeitherNameStillDoesNotLoad(): void
    {
        $html = $this->probe()->tags('js', $this->dir . 'js/');

        $this->assertStringNotContainsString('other.js', $html);
    }
}
