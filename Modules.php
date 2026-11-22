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
 *   Push = off
 *   HelloWorld = on
 *
 *   [order]
 *   Links = 10
 */
class Modules
{
    private const REQUIRED = ['Auth', 'User', 'Admin', 'AccessRequest'];
    private const OPTIONAL = ['Push', 'Entity'];
    private const EXAMPLE = ['Module', 'HelloWorld', 'Test'];

    /** @var string[] Existing roots in priority order, each with a trailing slash */
    private static array $roots = [];
    private static ?string $iniFile = null;
    private static ?array $all = null;
    private static ?array $listing = null;
    private static ?array $ini = null;
    private static string $iniHash = 'absent';

    /**
     * Set the roots (priority order, site first) and the ini file, and reset
     * every cache. A root that does not exist is skipped.
     */
    public static function configure(array $roots, ?string $iniFile): void
    {
        self::$roots = [];
        $real = [];
        foreach ($roots as $root) {
            // A root that is the same folder as an earlier one (a symlink to
            // it) would clash with itself, so it is dropped.
            if (is_dir($root) && !in_array($path = realpath($root), $real, true)) {
                $real[] = $path;
                self::$roots[] = rtrim($root, '/') . '/';
            }
        }
        self::$iniFile = $iniFile;
        self::$all = null;
        self::$listing = null;
        self::$ini = null;
    }

    /**
     * Every module directory in every root, enabled or not, keyed by
     * directory name. A directory is a module when its name is an uppercase
     * letter followed by letters and digits (^[A-Z][A-Za-z0-9]*$), the names
     * the router serves and app/modules.ini can hold. When two roots hold the
     * same name the earlier root wins.
     *
     * @return array<string,string> name => absolute path with trailing slash
     */
    public static function all(): array
    {
        if (self::$all !== null) {
            return self::$all;
        }

        $found = [];
        foreach (self::listing() as [$root, $entry]) {
            if (self::isModuleDirName($entry) && !isset($found[$entry])) {
                $found[$entry] = $root . $entry . '/';
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
     * Throw when two roots hold the same module name, compared the way path()
     * compares (case-insensitive, hyphens ignored). The message names both
     * paths. Reads the cached listing.
     */
    public static function assertNoClash(): void
    {
        $seen = [];
        foreach (self::listing() as [$root, $entry]) {
            $key = self::fold($entry);
            if (isset($seen[$key])) {
                [$first, $firstEntry] = $seen[$key];
                if (self::isModuleDirName($entry) || self::isModuleDirName($firstEntry)) {
                    throw new \RuntimeException(
                        "Module name clash: {$first}{$firstEntry}/ and {$root}{$entry}/ are the same module name"
                    );
                }
                continue;
            }
            $seen[$key] = [$root, $entry];
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
     * The folder (trailing slash) of a module that exists and is switched
     * on, or null. The one rule every "may this module be used" lookup shares.
     */
    public static function routablePath(string $name): ?string
    {
        return self::routable($name) ? self::path($name) : null;
    }

    /**
     * The app/modules.ini path handed to configure(), or null.
     */
    public static function iniFile(): ?string
    {
        return self::$iniFile;
    }

    /**
     * The real paths (trailing slash, sorted) of every module that is not
     * enabled. A module whose real path is not strictly inside one of the
     * roots (a folder that is a symlink to a parent directory) is ignored, so
     * it cannot make the walk drop every link.
     *
     * @return string[]
     */
    public static function disabledPaths(): array
    {
        $rootReals = [];
        foreach (self::$roots as $root) {
            if (($real = realpath($root)) !== false) {
                $rootReals[] = rtrim($real, '/') . '/';
            }
        }

        $disabled = [];
        foreach (self::all() as $name => $dir) {
            if (self::enabled((string) $name) || ($real = realpath($dir)) === false) {
                continue;
            }
            $real = rtrim($real, '/') . '/';
            foreach ($rootReals as $rootReal) {
                if ($real !== $rootReal && str_starts_with($real, $rootReal)) {
                    $disabled[] = $real;
                    break;
                }
            }
        }
        sort($disabled);

        return $disabled;
    }

    /**
     * Remove the public asset links of modules that are not enabled: every
     * symlink under {webRoot}/assets/, and under each real directory there
     * (one level), whose real path lies inside a disabled module's folder.
     * That covers links of any spelling (assets/networkMap) and links into a
     * submodule (assets/hexmap, assets/test/route). Only symlinks are looked
     * at or removed: a real directory, a link into an enabled module and a
     * link pointing outside every module stay. A link another request removed
     * first is not an error. Returns false when a link that had to go could
     * not be removed.
     */
    public static function dropDisabledAssetLinks(string $webRoot): bool
    {
        $disabled = self::disabledPaths();
        if ($disabled === []) {
            return true;
        }

        $ok = true;
        $assets = rtrim($webRoot, '/') . '/assets/';
        foreach (self::entries($assets, true) as $entry) {
            $path = $assets . $entry;
            if (is_link($path)) {
                $ok = self::dropLinkInto($path, $disabled) && $ok;
            } elseif (is_dir($path)) {
                foreach (self::entries($path . '/', true) as $inner) {
                    if (is_link($path . '/' . $inner)) {
                        $ok = self::dropLinkInto($path . '/' . $inner, $disabled) && $ok;
                    }
                }
            }
        }

        return $ok;
    }

    /**
     * Run dropDisabledAssetLinks() when the set of disabled modules is not the
     * one the marker file recorded (a hash of the sorted real paths). The
     * marker is written only when every removal succeeded; when it cannot be
     * written, or a removal failed, the cleanup runs again next time. An
     * empty set with no marker means nothing to do; an empty set with a marker
     * of a former set rewrites the marker. Returns whether the cleanup ran.
     */
    public static function dropDisabledAssetLinksWhenChanged(string $marker, string $webRoot): bool
    {
        $disabled = self::disabledPaths();
        $state = $disabled === [] ? 'none' : sha1(implode("\n", $disabled));
        $known = is_file($marker) ? trim((string) @file_get_contents($marker)) : null;

        if ($known === $state || ($known === null && $disabled === [])) {
            return false;
        }

        $ok = $disabled === [] ? true : self::dropDisabledAssetLinks($webRoot);
        if ($ok) {
            $dir = dirname($marker);
            if (is_dir($dir) || @mkdir($dir, 0775, true)) {
                @file_put_contents($marker, $state);
            }
        }

        return $disabled !== [];
    }

    /**
     * Unlink a symlink whose real path is inside one of the folders (each
     * with a trailing slash, so Foo does not match FooBar). False when the
     * link had to go and could not be removed.
     */
    private static function dropLinkInto(string $link, array $folders): bool
    {
        $target = realpath($link);
        if ($target === false) {
            return true;
        }
        $target = rtrim($target, '/') . '/';
        foreach ($folders as $folder) {
            if (str_starts_with($target, $folder)) {
                return @unlink($link) || !is_link($link);
            }
        }

        return true;
    }

    /**
     * Whether a directory name is a module name.
     */
    private static function isModuleDirName(string $name): bool
    {
        return preg_match('/^[A-Z][A-Za-z0-9]*$/D', $name) === 1;
    }

    /**
     * Lowercase with hyphens removed, the form every spelling is compared in.
     */
    private static function fold(string $name): string
    {
        return strtolower(str_replace('-', '', $name));
    }

    /**
     * Every sub-directory of every root as [root, name], in root priority
     * order, scanned once per configure().
     *
     * @return array<int,array{0:string,1:string}>
     */
    private static function listing(): array
    {
        if (self::$listing !== null) {
            return self::$listing;
        }

        $listing = [];
        foreach (self::$roots as $root) {
            foreach (self::entries($root) as $entry) {
                $listing[] = [$root, $entry];
                // The listing is cached: this logs once per request.
                if (ctype_upper($entry[0]) && !self::isModuleDirName($entry)) {
                    Console::warn("{$root}{$entry}/ is not a module: a module's folder name matches ^[A-Z][A-Za-z0-9]*\$");
                }
            }
        }

        return self::$listing = $listing;
    }

    /**
     * Names of the sub-directories of a root (and, with $links, its symlinks).
     *
     * @return string[]
     */
    private static function entries(string $root, bool $links = false): array
    {
        $entries = [];
        foreach (@scandir($root) ?: [] as $entry) {
            if ($entry[0] !== '.' && ((is_dir($root . $entry)) || ($links && is_link($root . $entry)))) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    /**
     * The sections of an ini file as Modules reads them (typed scanner), or
     * null when the file does not parse. ModulesIni checks what it is about to
     * write with this same call.
     */
    public static function parseIniFile(string $file): ?array
    {
        $text = @file_get_contents($file);

        return $text === false ? null : self::parseIniString($text);
    }

    /**
     * The sections of ini text as Modules reads them (typed scanner), or null
     * when it does not parse.
     */
    public static function parseIniString(string $text): ?array
    {
        $parsed = @parse_ini_string($text, true, INI_SCANNER_TYPED);

        return $parsed === false ? null : $parsed;
    }

    /**
     * The hash of the exact text of app/modules.ini that the answers of this
     * class were read from (sha1 of the text; 'absent' when there is no file).
     * It is the same value ModulesIni::hash() has for the same content, so a
     * screen drawn from this class carries the hash of what it shows.
     */
    public static function iniHash(): string
    {
        self::ini();

        return self::$iniHash;
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
        self::$iniHash = 'absent';
        if (self::$iniFile !== null && is_file(self::$iniFile)) {
            // One read: the hash and the parse are of the same bytes.
            $text = @file_get_contents(self::$iniFile);
            $parsed = $text === false ? null : self::parseIniString($text);
            if ($text !== false) {
                self::$iniHash = sha1($text);
            }
            if ($parsed === null) {
                // Cached with the rest of the ini: this logs once per request.
                Console::warn(self::$iniFile . ' exists but does not parse; every module is at its default');
                $parsed = [];
            }
        }

        return self::$ini = [
            'enabled' => is_array($parsed['enabled'] ?? null) ? $parsed['enabled'] : [],
            'order' => is_array($parsed['order'] ?? null) ? $parsed['order'] : [],
        ];
    }
}
