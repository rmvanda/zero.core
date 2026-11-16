<?php

namespace Zero\Core;

/**
 * Wording as constants.
 *
 * A site's own wording lives in app/strings/*.ini and is defined first. A
 * module ships its defaults in {Module}/strings/*.ini, and each of those is
 * defined only when nothing has defined it yet, so a site overrides any
 * module string by repeating its key. A module's keys carry the module's
 * name in upper snake case (AUTH_, USER_, ACCESS_REQUEST_).
 *
 * Files are parsed with INI_SCANNER_RAW: a value is taken literally. Wrap a
 * value in double quotes; inside them a single quote, =, ; and further double
 * quotes need no escaping. Without the wrapping, ; starts a comment.
 */
class Strings
{
    /** @var array<string,string> key => strings file that defined it */
    private static array $defined = [];

    /**
     * Whether a name can be a constant name defined from a file:
     * letters, digits and underscores, not starting with a digit.
     */
    public static function isConstantName(string $name): bool
    {
        return preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) === 1;
    }

    /**
     * Define every key of every .ini in $dir (trailing slash). A folder that
     * does not exist defines nothing. A key that is already defined keeps its
     * value (and is logged when something other than a strings file defined it).
     * A key that is not a valid constant name is skipped with a log line.
     */
    public static function loadSite(string $dir): void
    {
        self::loadDir($dir);
    }

    /**
     * Define the module's strings/*.ini keys that are not defined yet. $name
     * is any spelling of the module's name. A module that does not exist or
     * is switched off defines nothing. Calling it again is harmless.
     */
    public static function loadModule(string $name): void
    {
        $path = Modules::routablePath($name);
        if ($path !== null) {
            self::loadDir($path . 'strings/');
        }
    }

    /**
     * After the site's files are read: SITE_SHORT_NAME is SITE_NAME unless the
     * site defined it, and SITE_NAME is 'Zero' when the site defined neither
     * (SITE_SHORT_NAME alone stands in for SITE_NAME).
     */
    public static function defineSiteFallbacks(): void
    {
        if (!defined('SITE_NAME')) {
            define('SITE_NAME', defined('SITE_SHORT_NAME') ? SITE_SHORT_NAME : 'Zero');
        }
        if (!defined('SITE_SHORT_NAME')) {
            define('SITE_SHORT_NAME', SITE_NAME);
        }
    }

    private static function loadDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = glob(rtrim($dir, '/') . '/*.ini') ?: [];
        sort($files);
        foreach ($files as $file) {
            $values = @parse_ini_file($file, false, INI_SCANNER_RAW);
            if ($values === false) {
                Console::warn('Strings: cannot parse ' . $file);
                continue;
            }
            foreach ($values as $key => $value) {
                $key = (string) $key;
                if (!self::isConstantName($key)) {
                    Console::warn("Strings: $key in $file is not a valid constant name and is skipped");
                    continue;
                }
                if (defined($key)) {
                    // A key an earlier strings file defined is the normal
                    // override (the site over a module default); anything else
                    // that holds the name is worth a log line.
                    if (!isset(self::$defined[$key])) {
                        Console::warn("Strings: $key in $file is already defined elsewhere and keeps its value");
                    }
                    continue;
                }
                define($key, $value);
                self::$defined[$key] = $file;
            }
        }
    }
}
