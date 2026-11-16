<?php

namespace Zero\Core;

/**
 * Modules: one place that knows where a module is, which tier it belongs to
 * and whether it is switched on.
 *
 * Modules live in several roots (the site's modules/ folder first, then the
 * folder core ships its own in). Application hands the roots and the path of
 * app/modules.ini to configure(); the class reads no global constant, so tests
 * can point it at throwaway directories.
 *
 * Tiers: required modules are always on, optional and site modules default
 * on, example modules default off. app/modules.ini lists only the departures
 * from those defaults:
 *
 *   [enabled]
 *   Sitemap = off
 *   HelloWorld = on
 *
 *   [order]
 *   Links = 10
 */
class Modules
{
    private const REQUIRED = ['Auth', 'User', 'Admin', 'AccessRequest'];
    private const OPTIONAL = ['Sitemap', 'Push', 'Entity'];
    private const EXAMPLE = ['Module', 'HelloWorld', 'Test'];

    /** @var string[] Existing roots in priority order, each with a trailing slash */
    private static array $roots = [];
    private static ?string $iniFile = null;
    private static ?array $all = null;
    private static ?array $ini = null;

    /**
     * Set the roots (priority order, site first) and the ini file, and reset
     * every cache. A root that does not exist is skipped.
     */
    public static function configure(array $roots, ?string $iniFile): void
    {
        self::$roots = [];
        foreach ($roots as $root) {
            if (is_dir($root)) {
                self::$roots[] = rtrim($root, '/') . '/';
            }
        }
        self::$iniFile = $iniFile;
        self::$all = null;
        self::$ini = null;
    }

    /**
     * Every module directory in every root, enabled or not, keyed by
     * directory name. A directory is a module when its name starts with an
     * uppercase letter. When two roots hold the same name the earlier root wins.
     *
     * @return array<string,string> name => absolute path with trailing slash
     */
    public static function all(): array
    {
        if (self::$all !== null) {
            return self::$all;
        }

        $found = [];
        foreach (self::$roots as $root) {
            foreach (self::entries($root) as $entry) {
                if (ctype_upper($entry[0]) && !isset($found[$entry])) {
                    $found[$entry] = $root . $entry . '/';
                }
            }
        }

        return self::$all = $found;
    }

    /**
     * Absolute path of a module with a trailing slash, or null. The exact
     * directory name matches first, then any case with hyphens ignored
     * (api-config, apiconfig, ApiConfig).
     */
    public static function path(string $name): ?string
    {
        $real = self::name($name);

        return $real === null ? null : self::all()[$real];
    }

    /**
     * The real directory name for any accepted spelling, or null.
     */
    public static function name(string $name): ?string
    {
        $all = self::all();
        if (isset($all[$name])) {
            return $name;
        }

        $wanted = self::fold($name);
        if ($wanted === '') {
            return null;
        }
        foreach (array_keys($all) as $real) {
            if (self::fold((string) $real) === $wanted) {
                return (string) $real;
            }
        }

        return null;
    }

    /**
     * 'required' | 'optional' | 'example' | 'site'
     */
    public static function tier(string $name): string
    {
        $name = self::name($name) ?? $name;
        if (in_array($name, self::REQUIRED, true)) {
            return 'required';
        }
        if (in_array($name, self::OPTIONAL, true)) {
            return 'optional';
        }
        if (in_array($name, self::EXAMPLE, true)) {
            return 'example';
        }

        return 'site';
    }

    /**
     * Whether a module is switched on. False for an unknown module. Required
     * modules are always on; an ini entry for one is ignored.
     */
    public static function enabled(string $name): bool
    {
        $real = self::name($name);
        if ($real === null) {
            return false;
        }

        $tier = self::tier($real);
        if ($tier === 'required') {
            return true;
        }

        foreach (self::ini()['enabled'] as $key => $value) {
            if (self::fold((string) $key) === self::fold($real)) {
                return (bool) $value;
            }
        }

        return $tier !== 'example';
    }

    /**
     * The load order from the ini's [order] section, or null.
     */
    public static function order(string $name): ?int
    {
        $wanted = self::fold(self::name($name) ?? $name);
        foreach (self::ini()['order'] as $key => $value) {
            if (self::fold((string) $key) === $wanted && is_numeric($value)) {
                return (int) $value;
            }
        }

        return null;
    }

    /**
     * Throw when two roots hold the same module name, compared
     * case-insensitively. The message names both paths.
     */
    public static function assertNoClash(): void
    {
        $seen = [];
        foreach (self::$roots as $root) {
            foreach (self::entries($root) as $entry) {
                $key = strtolower($entry);
                if (isset($seen[$key])) {
                    if (ctype_upper($entry[0]) || ctype_upper(basename($seen[$key])[0])) {
                        throw new \RuntimeException(
                            "Module name clash: {$seen[$key]} and {$root}{$entry}/ are the same module name"
                        );
                    }
                    continue;
                }
                $seen[$key] = $root . $entry . '/';
            }
        }
    }

    /**
     * A module that exists and is switched on.
     */
    public static function routable(string $name): bool
    {
        return self::path($name) !== null && self::enabled($name);
    }

    /**
     * Remove {webRoot}/assets/{module} symlinks that belong to a module that
     * is not enabled. A real directory, or the link of an enabled module,
     * stays.
     */
    public static function dropDisabledAssetLinks(string $webRoot): void
    {
        $assets = rtrim($webRoot, '/') . '/assets/';
        foreach (array_keys(self::all()) as $name) {
            if (self::enabled((string) $name)) {
                continue;
            }
            $link = $assets . strtolower((string) $name);
            if (is_link($link)) {
                unlink($link);
            }
        }
    }

    /**
     * Lowercase with hyphens removed, the form every spelling is compared in.
     */
    private static function fold(string $name): string
    {
        return strtolower(str_replace('-', '', $name));
    }

    /**
     * Names of the sub-directories of a root.
     *
     * @return string[]
     */
    private static function entries(string $root): array
    {
        $entries = [];
        foreach (scandir($root) ?: [] as $entry) {
            if ($entry[0] !== '.' && is_dir($root . $entry)) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    /**
     * @return array{enabled: array, order: array}
     */
    private static function ini(): array
    {
        if (self::$ini !== null) {
            return self::$ini;
        }

        $parsed = [];
        if (self::$iniFile !== null && is_file(self::$iniFile)) {
            $parsed = @parse_ini_file(self::$iniFile, true, INI_SCANNER_TYPED) ?: [];
        }

        return self::$ini = [
            'enabled' => is_array($parsed['enabled'] ?? null) ? $parsed['enabled'] : [],
            'order' => is_array($parsed['order'] ?? null) ? $parsed['order'] : [],
        ];
    }
}
