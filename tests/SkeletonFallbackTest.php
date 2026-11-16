<?php

namespace Zero\Tests\Core;

use Zero\Core\Application;
use Zero\Core\Response;

/**
 * A site that has not written its own frame pieces or error_codes.ini gets
 * core's copy from core/skeleton/. A site overrides by having the file.
 */
final class SkeletonFallbackTest extends ZeroTestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/zerotest-skeleton-' . getmypid() . '/';
        $this->wipe();
        mkdir($this->dir . 'frame', 0777, true);
        mkdir($this->dir . 'config', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->wipe();
        parent::tearDown();
    }

    private function wipe(): void
    {
        if (!is_dir($this->dir)) {
            return;
        }
        foreach (glob($this->dir . '*/*') ?: [] as $f) {
            unlink($f);
        }
        foreach (glob($this->dir . '*') ?: [] as $d) {
            rmdir($d);
        }
        rmdir($this->dir);
    }

    private function skeletonFrame(): string
    {
        return realpath(__DIR__ . '/../skeleton/frontend/frame') . '/';
    }

    public function testSkeletonFooterIsUsedWhenSiteHasNone(): void
    {
        $path = Response::framePieceFile('footer', [$this->dir . 'frame/']);
        $this->assertSame($this->skeletonFrame() . 'footer.php', $path);
    }

    public function testSiteFooterWinsOverSkeleton(): void
    {
        file_put_contents($this->dir . 'frame/footer.php', '<?php // site');
        $path = Response::framePieceFile('footer', [$this->dir . 'frame/']);
        $this->assertSame($this->dir . 'frame/footer.php', $path);
    }

    public function testEarlierDirectoryWinsOverLater(): void
    {
        mkdir($this->dir . 'first');
        file_put_contents($this->dir . 'first/footer.php', '<?php // module');
        file_put_contents($this->dir . 'frame/footer.php', '<?php // site');
        $path = Response::framePieceFile('footer', [$this->dir . 'first/', $this->dir . 'frame/']);
        $this->assertSame($this->dir . 'first/footer.php', $path);
        unlink($this->dir . 'first/footer.php');
        rmdir($this->dir . 'first');
    }

    public function testUnknownPieceIsNull(): void
    {
        $this->assertNull(Response::framePieceFile('zerotest-nothing', [$this->dir . 'frame/']));
    }

    public function testErrorCodesComeFromSkeletonWhenSiteHasNone(): void
    {
        $skel = realpath(__DIR__ . '/../skeleton/config') . '/';
        $files = Application::configFiles($this->dir . 'config/', $skel);
        $this->assertContains($skel . 'error_codes.ini', $files);
        $consts = parse_ini_file($skel . 'error_codes.ini', false, INI_SCANNER_RAW);
        $this->assertArrayHasKey('ERROR_CODE_404', $consts);
    }

    public function testSiteErrorCodesAreNotReadTwice(): void
    {
        file_put_contents($this->dir . 'config/error_codes.ini', "ERROR_CODE_404=404\n");
        file_put_contents($this->dir . 'config/other.ini', "A=1\n");
        $skel = realpath(__DIR__ . '/../skeleton/config') . '/';
        $files = Application::configFiles($this->dir . 'config/', $skel);
        $this->assertSame(
            [$this->dir . 'config/error_codes.ini', $this->dir . 'config/other.ini'],
            array_slice($files, 0, 2),
            'the site files come first, in order'
        );
        $this->assertNotContains($skel . 'error_codes.ini', $files);
    }

    public function testASiteWithNoConfigDirectoryGetsTheSkeletonDefaults(): void
    {
        $skel = realpath(__DIR__ . '/../skeleton/config') . '/';
        $files = Application::configFiles($this->dir . 'no-such-config/', $skel);
        $this->assertContains($skel . 'error_codes.ini', $files);
    }

    public function testAFileNamedPathsIniGetsTheZeroRootPrefix(): void
    {
        if (!defined('ZERO_ROOT')) {
            define('ZERO_ROOT', dirname(__DIR__, 2) . '/');
        }
        $this->assertSame(ZERO_ROOT . 'app/x/', Application::configValue('/any/dir/paths.ini', 'app/x/'));
        $this->assertSame('app/x/', Application::configValue('/any/dir/other.ini', 'app/x/'));
        $this->assertSame('app/x/', Application::configValue('/any/dir/notpaths.ini', 'app/x/'));
    }

    public function testASiteWithNoConfigDirectoryStillDefinesDevSubnetAndItMatchesNoAddress(): void
    {
        $skel = realpath(__DIR__ . '/../skeleton/config') . '/';
        $keys = [];
        foreach (Application::configFiles($this->dir . 'no-such-config/', $skel) as $file) {
            $keys += parse_ini_file($file, false, INI_SCANNER_RAW);
        }
        $this->assertArrayHasKey('DEV_SUBNET', $keys);
        foreach (['192.168.1.5', '203.0.113.9', '::1', '2001:db8::1'] as $address) {
            $this->assertFalse(str_contains($address, $keys['DEV_SUBNET']), "$address must not turn on DEVMODE");
        }
    }
}
