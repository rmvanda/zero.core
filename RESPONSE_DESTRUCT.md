# `Response::__destruct()` — why module-level tests are hard to write

**Status:** Open. Documented, not fixed. Nothing in production is broken by this; it affects
tests only. Found 2026-08-16, checked against the code 2026-11-21.
**Code:** `core/Response.php` — `__construct()`, `defineBasePaths()`, `__destruct()`,
`build()`, `add()`, `framePiecePath()`, `export()`, and the static `$built`.

## Summary

A `Zero\Core\Response` or `Module` subclass cannot be created and dropped in a test without
preparation. The **constructor** needs a constant only the web entry point defines, and the
**destructor** renders a complete HTML page.

## The two failures

### 1. The constructor needs `ZERO_ROOT`

`__construct()` calls `defineBasePaths()`, which falls back to
`ZERO_ROOT."app/frontend/{frame,view,component,model}/"` for any folder the module does not
have. `ZERO_ROOT`, `ROOT_PATH`, `VIEW_PATH` and `MODULE_PATH` are defined by the site's
`app/frontend/www/index.php` (template: `core/skeleton/frontend/www/index.php`).
`core/tests/bootstrap.php` defines none of them.

```
$ php -r 'require "core/tests/bootstrap.php";
          class Probe extends \Zero\Core\Response {}
          new Probe();'
Error: Undefined constant "Zero\Core\ZERO_ROOT"
```

The message names `Zero\Core\ZERO_ROOT` because PHP resolves an unqualified constant against
the current namespace first. The missing constant is the global `ZERO_ROOT`.

Tests that need it define it themselves (`if (!defined('ZERO_ROOT')) define(…)` in
`ModuleRoutingTest` and `SkeletonFallbackTest`; unconditionally in `RenderErrorTest`). A
constant cannot be undefined again, so whichever test runs first sets it for the process.

### 2. The destructor renders a page

```php
public function __destruct(){
    if(!static::$built){
        $this->build($this->body ?? "");
    }
}
```

`build()` adds `head`, `header`, `sideNav` and `footer` through `add()`, which asks
`framePiecePath()` for each file. So an object that merely goes out of scope includes frame
files and writes a full HTML document.

In a test there are two outcomes, both bad:

- `VIEW_PATH` undefined: `framePiecePath()` passes the bare constant, so destruction is a
  fatal `Undefined constant "Zero\Core\VIEW_PATH"`. It is raised from inside `__destruct()`,
  at object destruction or shutdown, so it is attributed to whatever is running then, not to
  the test that created the object.
- `VIEW_PATH` defined: the site's `head.php` is included, writes `<!DOCTYPE html>…` to
  stdout, then fatals on the next constant it needs (`WEB_ROOT`, in `getStylesheets()`).

## The `static::$built` trap

`protected static $built` is declared once on `Response` and never redeclared, so
`static::$built` is one slot shared by every `Response` and `Module` subclass in the process.
`build()` and `export()` set it to `true`; nothing sets it back.

## Workarounds in use

| Workaround | Used by | Cost |
|---|---|---|
| Subclass that overrides `__destruct() {}` (and often `__construct() {}`) | `core/tests/ModuleRoutingTest.php`, `core/tests/AdoptFrameTest.php` | None beyond the subclass. Only possible when the test controls the class. **Prefer this.** |
| `Request::$acceptsJSON = true` | `core/tests/RenderErrorTest.php`, the site's `modules/Plog/tests/PlogControllerTest.php` | `build()` returns before setting `$built`, so the shared flag is untouched. Global static on `Request`; must be reset. |
| Set `Response::$built = true` by reflection | the site's `modules/TheButton/tests/TheButtonUserLookupTest.php`, `modules/Paste/tests/PasteRejectionTest.php`, `modules/Plog/tests/PlogControllerTest.php` | One-way and process-global: any later test that expects `build()` to run from `__destruct()` silently does not get it. Restoring it in `tearDown()` does not work — on a failing run the object is destroyed after the restore and the destructor fatals. |

`Request::$madeWithAJAX = true` also stops output (`add()` returns for each piece), but
`build()` still sets `$built`, so it has the same one-way cost as the reflection approach.

The reflection workaround is needed when the test instantiates a real module class. Three
test files now depend on it, so the order tests run in already decides whether a real
`build()` can happen. Do not add a fourth without fixing the cause.

## Suggested fixes, in order of value

1. **Don't render from a destructor.** `__destruct()` calling `build()` ties page assembly to
   garbage collection. `Application::run()` owns the end of the request and can call
   `build()` explicitly. Larger change, but it removes the whole problem and every workaround
   above. Watch out: `Application::run()` is not the only place a `Response` is created
   (`Module::__call()` for submodules, `AdminResponse::routeToModuleAdmin()`,
   `Response::__call()` for Index pages), and endpoints that set `$this->body` and return
   rely on the destructor today.
2. **Guard `VIEW_PATH` in `framePiecePath()`**: pass it only when `defined('VIEW_PATH')`. A
   missing constant in a fallback list should not be fatal; `framePieceFile()` already falls
   back to `core/skeleton/frontend/frame/`. One line, no change when the constant is defined.
   On its own this turns the fatal into a rendered skeleton page on stdout, so it helps only
   together with 1 or a workaround.
3. **Define `ZERO_ROOT` in `core/tests/bootstrap.php`.** It already computes the same root
   for the autoloader, and three tests define the constant by hand. Makes construction
   possible everywhere; does nothing about the destructor. Watch out:
   `core/tests/EntityLocationTest.php` has cases that need `ZERO_ROOT` undefined or set to
   another value, so check how it isolates them before defining it globally.
4. **Reset `$built` per request or make it an instance property.** Removes the trap for
   tests; needs a check of every place that relies on one response per process.

## Reproduction

```bash
cd /var/www/html/unisolu.com/zero

# 1. Constructor failure — the state a test starts in:
php -r 'require "core/tests/bootstrap.php";
        class Probe extends \Zero\Core\Response {}
        new Probe();'
# → Error: Undefined constant "Zero\Core\ZERO_ROOT"

# 2. ZERO_ROOT defined — constructs, then fatals in the destructor:
php -r 'define("ZERO_ROOT","/var/www/html/unisolu.com/zero/");
        require "core/tests/bootstrap.php";
        class Probe extends \Zero\Core\Response {}
        $p = new Probe(); unset($p);'
# → Fatal error: Undefined constant "Zero\Core\VIEW_PATH" (Response::framePiecePath)

# 3. VIEW_PATH defined too — the destructor starts writing a page:
php -r 'define("ZERO_ROOT","/var/www/html/unisolu.com/zero/");
        define("VIEW_PATH", ZERO_ROOT."app/frontend/frame/");
        require "core/tests/bootstrap.php";
        class Probe extends \Zero\Core\Response {}
        $p = new Probe(); unset($p);'
# → <!DOCTYPE html> … on stdout, then Undefined constant "Zero\Core\WEB_ROOT"
```
