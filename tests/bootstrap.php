<?php
/**
 * PHPUnit bootstrap.
 *
 * core/autoload.php registers the rule a request uses to find a class
 * (Application::classFile()), so a test loads a class of core or of a module
 * exactly as the site does: Zero\Core\Module\Auth, Zero\Core\Module\Auth\Model\*,
 * Zero\Module\Lists\Lib\*, a module's Tests\ classes in its tests/ folder.
 * No module needs an entry in composer.json, and a test needs no require_once
 * for a class that follows the convention.
 *
 * Two rules are added here, for tests only:
 *
 *   - Zero\Tests\Core\{Class} is core/tests/{Class}.php (ZeroTestCase and the
 *     other shared test classes).
 *   - A test that points Modules at throwaway folders still finds the classes
 *     of the real modules: when the request rule finds nothing for a
 *     Zero\Module\ class the real modules/ is looked in directly, and for a
 *     Zero\Core\Module\ class the real core/modules/, with the same folder
 *     rule (the exact name, then lower case).
 */
// dirname(__DIR__, 2) is the assembly root: this file is core/tests/bootstrap.php,
// and vendor/ + modules/ are siblings of core/, not of this directory.
require dirname(__DIR__) . '/autoload.php';

spl_autoload_register(static function (string $class): void {
    $root = dirname(__DIR__, 2);

    if (str_starts_with($class, 'Zero\\Tests\\Core\\')) {
        $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen('Zero\\Tests\\Core\\'))) . '.php';
        if (is_file($file)) {
            require_once $file;
        }
        return;
    }

    $prefix = null;
    foreach (['Zero\\Core\\Module\\' => '/core/modules/', 'Zero\\Module\\' => '/modules/'] as $candidate => $candidateFolder) {
        if (str_starts_with($class, $candidate)) {
            [$prefix, $folder] = [$candidate, $candidateFolder];
            break;
        }
    }
    if ($prefix === null || !\Zero\Core\Application::isQualifiedName($class)) {
        return;
    }

    $segments = explode('\\', substr($class, strlen($prefix)));
    $name     = array_shift($segments);
    $leaf     = $segments === [] ? $name : array_pop($segments);

    $dir = $root . $folder . $name . '/';
    foreach ($segments as $segment) {
        $dir .= (is_dir($dir . $segment) ? $segment : strtolower($segment)) . '/';
    }
    if (is_file($file = $dir . $leaf . '.php')) {
        require_once $file;
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
