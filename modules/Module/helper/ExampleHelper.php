<?php
/**
 * helper/ is NOT framework-aware. Nothing autoloads it — Response::$paths only
 * covers frame, view, component, model. Require it explicitly:
 *
 *     require_once __DIR__ . '/helper/ExampleHelper.php';
 *
 * Ttrpg/helper/ is the working example. (ExchangeGame/helpers/ is empty; the
 * singular form is the convention.)
 */
namespace Zero\Module\Module\Helper;

class ExampleHelper {

    /**
     * StudlyCaps -> kebab-case. This is the transform the framework applies to
     * derive a module's URL and its auto-loaded asset filenames:
     *   HelloWorld -> hello-world  (assets/css/hello-world.css, /hello-world/)
     */
    // Every capital gets a dash before it, except a leading one — including
    // runs of consecutive capitals (ABTest -> a-b-test, APIDemo -> a-p-i-demo).
    // mkmod's Bash reimplementation must match this byte for byte.
    public static function slugify(string $studly): string {
        return strtolower(preg_replace('/(?<!^)[A-Z]/', '-$0', $studly));
    }
}
