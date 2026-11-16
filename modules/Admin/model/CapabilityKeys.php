<?php
namespace Zero\Module\Admin\Model;

use Zero\Core\Modules;
use Zero\Core\Permission;

/**
 * The capability keys an admin can grant: every key a module gates an
 * endpoint with, and every key somebody already holds.
 *
 * The first half is read from the modules' source, so a key shows on the
 * user screen before anyone has been granted it.
 */
class CapabilityKeys
{
    /** Where a module declares endpoints, relative to the module's directory. */
    private const SOURCES = ['*.php', 'Admin/*.php', 'submodule/*/*.php'];

    /**
     * The directories (trailing slash) of every module that is switched on,
     * in every root.
     *
     * @return string[]
     */
    public static function enabledModuleDirs(): array
    {
        $dirs = [];
        foreach (Modules::all() as $name => $dir) {
            if (Modules::enabled((string) $name)) {
                $dirs[] = $dir;
            }
        }

        return $dirs;
    }

    /**
     * @param string[]|null $moduleDirs Module directories, each with a trailing
     *                                  slash; null reads every enabled module
     * @return string[] Sorted, unique keys
     */
    public static function all(?array $moduleDirs = null): array
    {
        $keys = array_merge(self::declared($moduleDirs), Permission::capabilityKeysInUse());
        $keys = array_values(array_unique($keys));
        sort($keys);

        return $keys;
    }

    /**
     * Keys named in a #[RequirePermission('...')] attribute anywhere in the
     * modules' source.
     *
     * @param string[]|null $moduleDirs Module directories, each with a trailing
     *                                  slash; null reads every enabled module
     * @return string[]
     */
    public static function declared(?array $moduleDirs = null): array
    {
        $moduleDirs ??= self::enabledModuleDirs();
        $keys = [];
        foreach ($moduleDirs as $dir) {
            foreach (self::SOURCES as $pattern) {
                foreach (glob($dir . $pattern) ?: [] as $file) {
                    $source = file_get_contents($file);
                    if ($source === false || !str_contains($source, 'RequirePermission')) {
                        continue;
                    }
                    if (preg_match_all('/RequirePermission\(\s*[\'"]([a-z0-9._-]+)[\'"]/', $source, $found)) {
                        array_push($keys, ...$found[1]);
                    }
                }
            }
        }

        return array_values(array_unique($keys));
    }
}
