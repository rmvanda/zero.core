<?php

namespace Zero\Tests\Core\Acceptance;

use PHPUnit\Framework\TestCase;

/**
 * The guard against two runs of BareSiteTest at once: while another process
 * holds the lock for the throwaway database, a run skips the whole class and
 * creates, drops and removes nothing.
 */
final class BareSiteLockTest extends TestCase
{
    public function testARunWhileAnotherHoldsTheLockSkipsAndTouchesNothing(): void
    {
        $lockFile = sys_get_temp_dir() . '/zerotest_bare_site.lock';
        $lock = fopen($lockFile, 'c');
        $this->assertTrue(flock($lock, LOCK_EX | LOCK_NB), 'no other run holds the lock now');
        try {
            // a database the holder of the lock "owns": the skipped run must not drop it
            $before = glob(sys_get_temp_dir() . '/zerotest-baresite-*') ?: [];
            $root = dirname(__DIR__, 3);
            $cmd = 'cd ' . escapeshellarg($root) . ' && php vendor/bin/phpunit -c phpunit.xml --display-skipped '
                . escapeshellarg('core/tests/acceptance/BareSiteTest.php') . ' 2>&1';
            $out = (string) shell_exec($cmd);
            $this->assertStringContainsString('another run is using zerotest_bare_site', $out);
            $this->assertStringNotContainsString('Failures:', $out);
            $this->assertSame($before, glob(sys_get_temp_dir() . '/zerotest-baresite-*') ?: [], 'the skipped run made no folder');
            $this->assertFileExists($lockFile, 'the skipped run left the lock alone');
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
