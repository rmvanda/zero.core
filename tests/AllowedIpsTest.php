<?php
namespace Zero\Tests\Core;

use Zero\Core\Application;

/**
 * app/config/allowed-ips.lst names the clients Application::banNefariousRequest()
 * never bans. A false positive here leaves a scanner unbanned; a false negative
 * bans the site's own owner at Cloudflare.
 */
class AllowedIpsTest extends ZeroTestCase
{
    private string $dir;
    private string $file;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/zerotest-allowed-ips-' . uniqid() . '/';
        mkdir($this->dir);
        $this->file = $this->dir . 'allowed-ips.lst';
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '*'));
        rmdir($this->dir);
        parent::tearDown();
    }

    public function testEntriesAreReadOnePerLineWithoutCommentsOrBlanks(): void
    {
        file_put_contents($this->file, "# home\n203.0.113.5\n\n  198.51.100.0/24   # the office\n\t\n2001:db8::/32\n");
        $this->assertSame(['203.0.113.5', '198.51.100.0/24', '2001:db8::/32'], Application::allowedIps($this->file));
    }

    public function testWindowsLineEndingsAreTolerated(): void
    {
        file_put_contents($this->file, "203.0.113.5\r\n198.51.100.7\r\n");
        $this->assertSame(['203.0.113.5', '198.51.100.7'], Application::allowedIps($this->file));
    }

    public function testAMissingFileAllowsNobody(): void
    {
        $this->assertSame([], Application::allowedIps($this->file));
        $this->assertFalse(Application::isAllowedIp('203.0.113.5', $this->file));
    }

    public function testAListedIpAndAnIpInAListedRangeAreAllowed(): void
    {
        file_put_contents($this->file, "203.0.113.5\n198.51.100.0/24\n2001:db8::/32\n");
        $this->assertTrue(Application::isAllowedIp('203.0.113.5', $this->file));
        $this->assertTrue(Application::isAllowedIp('198.51.100.77', $this->file));
        $this->assertTrue(Application::isAllowedIp('2001:db8::1', $this->file));

        $this->assertFalse(Application::isAllowedIp('203.0.113.6', $this->file));
        $this->assertFalse(Application::isAllowedIp('198.51.101.1', $this->file));
    }

    public function testAnEmptyClientIpIsNeverAllowed(): void
    {
        file_put_contents($this->file, "203.0.113.5\n\n");
        $this->assertFalse(Application::isAllowedIp('', $this->file));
    }

    public function testAnEntryThatIsNotAnAddressMatchesNothing(): void
    {
        file_put_contents($this->file, "not-an-ip\n203.0.113.5/99\n*\n0.0.0.0/zero\n");
        $this->assertFalse(Application::isAllowedIp('203.0.113.5', $this->file));
    }

    public function testTheRequestsThatGetAClientBanned(): void
    {
        $this->assertNotNull(Application::nefariousReason('/index.php'));
        $this->assertNotNull(Application::nefariousReason('/.env'));
        $this->assertNotNull(Application::nefariousReason('/wp-login'));
        $this->assertNull(Application::nefariousReason('/auth/login'));
        $this->assertNull(Application::nefariousReason('/'));
    }

    public function testAListFileInTheConfigFolderIsNotReadAsConstants(): void
    {
        file_put_contents($this->file, "203.0.113.5\n");
        file_put_contents($this->dir . 'x.ini', "A=1\n");
        file_put_contents($this->dir . '.x.ini.swp', "junk");
        $this->assertSame([$this->dir . 'x.ini'], Application::configFiles($this->dir, $this->dir . 'no-skeleton/'));
    }

    public function testTheTemplateIsAllCommentsSoANewSiteAllowsNobody(): void
    {
        $this->assertSame([], Application::allowedIps(__DIR__ . '/../skeleton/config-templates/allowed-ips.lst'));
    }
}
