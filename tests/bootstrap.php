<?php
/**
 * PHPUnit bootstrap.
 *
 * Beyond Composer's autoloader, this registers module ENDPOINT classes, which
 * Composer cannot reach: they live at modules/{Name}/{Name}.php, and PSR-4
 * cannot map the class Zero\Module\Auth to that path because the class is not
 * under a Zero\Module\Auth\ prefix. At request time the framework registers its
 * own autoloader (Response::registerAutoloaders()), which never runs here — so
 * without this, class_exists('Zero\Module\Auth') is false and no endpoint in
 * the codebase can be tested.
 *
 * Sub-namespaces (Zero\Module\Auth\Model\*, ...\Tests\*) already resolve via
 * the per-module PSR-4 entries in composer.json and are deliberately skipped.
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
    if (str_contains($rest, '\\')) {
        return;   // a sub-namespace — Composer's PSR-4 owns it
    }

    $file = dirname(__DIR__, 2) . '/modules/' . $rest . '/' . $rest . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});
