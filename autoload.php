<?php
/**
 * The framework's class loading for code that does not run through index.php:
 * a command-line script, a cron job, the test bootstrap.
 *
 *     require __DIR__ . '/../../core/autoload.php';
 *
 * It registers Application::classFile(), the rule a request uses, so a class
 * loads here exactly when it loads on the site: Zero\Core\, Zero\Lib\,
 * Zero\Entity\, Zero\Aux\ and Zero\Module\{Name}\... with its model/, lib/
 * and submodule folders. No class of the framework or of a module needs an
 * entry in composer.json. Composer loads the third-party packages only, and
 * is included here when the site has a vendor/ folder.
 *
 * The site root is the folder that holds core/. Modules is pointed at the
 * site's modules/ and at core/modules/, as Application::registerAutoloaders()
 * does at request time. A class that is not found is left to the next
 * autoloader, without a log line.
 */

namespace Zero\Core;

require_once __DIR__ . '/Application.php';
require_once __DIR__ . '/Modules.php';

(static function (): void {
    $root = dirname(__DIR__) . '/';

    Modules::configure([$root . 'modules/', $root . 'core/modules/'], $root . 'app/modules.ini');

    spl_autoload_register(static function (string $class): void {
        if (!str_starts_with($class, 'Zero\\')) {
            return;
        }
        $file = Application::classFile($class);
        if ($file !== null) {
            require_once $file;
        }
    });

    if (is_file($vendor = $root . 'vendor/autoload.php')) {
        require_once $vendor;
    }
})();
