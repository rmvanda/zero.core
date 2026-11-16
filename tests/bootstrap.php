<?php
/**
 * PHPUnit bootstrap.
 *
 * Beyond Composer's autoloader, this registers module ENDPOINT classes, which
 * Composer cannot reach: they live at {modules folder}/{Name}/{Name}.php (the
 * site's modules/ or core/modules/, looked up directly so a test that points
 * Modules at throwaway folders still finds the real controllers), and PSR-4
 * cannot map the class Zero\Module\Auth to that path because the class is not
 * under a Zero\Module\Auth\ prefix. At request time the framework registers its
 * own autoloader (Response::registerAutoloaders()), which never runs here — so
 * without this, class_exists('Zero\Module\Auth') is false and no endpoint in
 * the codebase can be tested.
 *
 * Sub-namespaces (Zero\Module\Auth\Model\*, ...\Tests\*) already resolve via
 * the per-module PSR-4 entries in composer.json and are deliberately skipped.
 *
 * The one sub-namespace registered here is a module's lib/ (classes other
 * modules call, Zero\Module\{Name}\Lib\{Class} in {module folder}/lib/{Class}.php):
 * Composer's Zero\Module\ -> modules/ mapping would look for it in Lib/, and
 * the directory is lowercase.
 */
// dirname(__DIR__, 2) is the assembly root: this file is core/tests/bootstrap.php,
// and vendor/ + modules/ are siblings of core/, not of this directory.
require dirname(__DIR__, 2) . '/vendor/autoload.php';

spl_autoload_register(static function (string $class): void {
    $prefix = 'Zero\\Module\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $rest = substr($class, strlen($prefix));
    if (preg_match('/^(\w+)\\\\Lib\\\\(.+)$/', $rest, $m)) {
        foreach (['/modules/', '/core/modules/'] as $folder) {
            $file = dirname(__DIR__, 2) . $folder . $m[1] . '/lib/' . str_replace('\\', '/', $m[2]) . '.php';
            if (is_file($file)) {
                require_once $file;
                return;
            }
        }
        return;
    }
    if (str_contains($rest, '\\')) {
        return;   // a sub-namespace — Composer's PSR-4 owns it
    }

    foreach (['/modules/', '/core/modules/'] as $folder) {
        $file = dirname(__DIR__, 2) . $folder . $rest . '/' . $rest . '.php';
        if (is_file($file)) {
            require_once $file;
            return;
        }
    }
});

/**
 * Point Modules at the real tree, as Application::registerAutoloaders() does at
 * request time. Tests that configure throwaway trees call this to put it back.
 */
function zeroTestConfigureModules(): void
{
    $root = dirname(__DIR__, 2) . '/';
    \Zero\Core\Modules::configure([$root . 'modules/', $root . 'core/modules/'], $root . 'app/modules.ini');
}

zeroTestConfigureModules();
