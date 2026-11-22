<?php

namespace Zero\Tests\Core\Module;

use PHPUnit\Framework\TestCase;
use Zero\Core\Modules;

/**
 * No file in any module (the site's and core's), in the site's frames or in
 * swoole/ points at an admin URL that moved into a module's own offering.
 */
class AdminUrlsTest extends TestCase
{
    protected function setUp(): void
    {
        zeroTestConfigureModules();
    }

    public function testNoOldAdminUrlAnywhere(): void
    {
        // Admin URLs that moved into module offerings or into Admin's own
        // screens (see DEPRECATING_CHANGES.md).
        $oldUrls = [
            '/admin/manage/',
            '/admin/set-module',
            '/admin/update-permission',
            '/admin/disable-user',
            '/admin/enable-user',
            '/admin/group-form',
            '/admin/delete-group',
            '/admin/add-group-member',
            '/admin/remove-group-member',
            '/admin/update-group-member-role',
            '/admin/create-entity',
            '/admin/update-entity',
            '/admin/delete-entity',
            '/admin/view-api-response',
            '/admin/expire-api-cache',
            '/admin/web-socket',
            '/admin/push-notifications',
            '/admin/delete-push-notifications',
        ];

        $root = dirname(__DIR__, 3);
        $folders = array_values(Modules::all());
        foreach (['app/frontend/frame', 'swoole'] as $dir) {
            $folders[] = "{$root}/{$dir}/";
        }
        $this->assertGreaterThan(10, count($folders), 'the scan reaches the modules');

        $found = [];
        foreach ($folders as $dir) {
            if (!is_dir($dir)) {
                continue;
            }
            $files = new \RecursiveIteratorIterator(
                new \RecursiveCallbackFilterIterator(
                    new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
                    fn($file) => !$file->isDir() || !in_array($file->getFilename(), ['tests', 'docs', 'node_modules', 'vendor', '.superpowers', '.git'], true)
                )
            );
            foreach ($files as $file) {
                if (!$file->isFile() || $file->isLink() || str_ends_with($file->getFilename(), '.md')) {
                    continue;
                }
                $text = @file_get_contents($file->getPathname());
                if ($text === false) {
                    continue;
                }
                foreach ($oldUrls as $url) {
                    if (str_contains($text, $url)) {
                        $found[] = substr($file->getPathname(), strlen($root) + 1) . " has {$url}";
                    }
                }
            }
        }

        $this->assertSame([], $found, 'no file points at an admin URL that moved');
    }
}
