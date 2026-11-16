<?php

namespace Zero\Tests\Core\Module;

use PHPUnit\Framework\TestCase;

/**
 * A site module never reaches a bundled module as a sibling folder
 * (`__DIR__ . '/../Auth/...'`): the bundled modules live in core/modules/ and
 * are found through Zero\Core\Modules::path().
 */
class SiblingPathsTest extends TestCase
{
    private const BUNDLED = 'Auth|User|Admin|AccessRequest|Sitemap|Push|Entity|Module|HelloWorld|Test';

    public function testNoSiteModuleFileNamesABundledModuleAsASibling(): void
    {
        $root = dirname(__DIR__, 3) . '/modules';
        $found = [];
        if (is_dir($root)) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $file) {
                $path = $file->getPathname();
                if (!$file->isFile() || is_link($path) || strpos($path, '/node_modules/') !== false || strpos($path, '/.superpowers/') !== false) {
                    continue;
                }
                if (!preg_match('/\.(php|js|sh)$/', $path) && basename($path) !== 'mkmod') {
                    continue;
                }
                if (preg_match('#\.\./(' . self::BUNDLED . ')/#', (string) file_get_contents($path))) {
                    $found[] = substr($path, strlen($root) + 1);
                }
            }
        }
        $this->assertSame([], $found, 'sibling-relative reach into a bundled module; use Modules::path()');
    }
}
