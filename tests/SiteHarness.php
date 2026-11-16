<?php

namespace Zero\Tests\Core;

/**
 * What the tests that build a throwaway site share: removing a tree, running
 * zero-init.sh in the site, and quoting a value into generated PHP. The using
 * class has a $site property (the site folder, with core/ inside it).
 */
trait SiteHarness
{
    private function wipe(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);
            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (array_diff(scandir($path), ['.', '..']) as $entry) {
            $this->wipe($path . '/' . $entry);
        }
        rmdir($path);
    }

    /** @return array{0:int,1:string} exit code and combined output */
    private function init(array $args = []): array
    {
        $cmd = escapeshellarg($this->site . '/core/skeleton/zero-init.sh');
        foreach ($args as $a) {
            $cmd .= ' ' . escapeshellarg($a);
        }
        exec($cmd . ' 2>&1', $out, $code);
        return [$code, implode("\n", $out)];
    }

    private function q(string $s): string
    {
        return var_export($s, true);
    }
}
