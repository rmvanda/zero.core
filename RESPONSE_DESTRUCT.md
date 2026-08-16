# `Response::__destruct()` — why module-level tests are hard to write

**Found:** 2026-08-16, while trying to give `modules/TheButton/TheButton.php`'s repaired
user-lookup call sites a behavioural test during sub-project B.
**Status:** Open. Documented, not fixed. Nothing in production is broken by this — it bites
tests only.
**Files:** `core/Response.php:15,24-28,51-67,206-232,233-247`

## Summary

`Zero\Core\Response` cannot be instantiated outside a real web request. Its **constructor**
fatals on constants that only the web entry point defines, and its **destructor** renders a
complete HTML page as a side effect. Both make `Response` and `Module` subclasses effectively
untestable without deliberate work.

This is why the invite-email fix in `modules/TheButton/TheButton.php` (`:920`, `:932`) shipped
with code review but no behavioural test — see `docs/superpowers/plans/2026-08-15-user-consolidation-b.md`.

## The two failures

### 1. The constructor needs constants the test bootstrap never defines

`__construct()` calls `defineBasePaths()` (`Response.php:51`), which reads `MODULE_PATH` at
`:54` and `ZERO_ROOT` at `:67`. Those constants — along with `ROOT_PATH` and `VIEW_PATH` — are
defined in exactly one place:

```php
// app/frontend/www/index.php:17-20
define("ZERO_ROOT",   $root_path[0].$SiteName."/");
define("ROOT_PATH",   $root_path[0]);
define("VIEW_PATH",   ZERO_ROOT . "app/frontend/frame/");
define("MODULE_PATH", ZERO_ROOT . "modules/");
```

PHPUnit boots through `core/tests/bootstrap.php`, which never loads that file. So:

```
$ php -r 'require "core/tests/bootstrap.php";
          class Probe extends \Zero\Core\Response {}
          new Probe();'

Error: Undefined constant "Zero\Core\MODULE_PATH"
```

Note the namespaced name in the message: PHP resolves an unqualified constant against the
current namespace before falling back to the global one, so the error says
`Zero\Core\MODULE_PATH` even though the constant is written as `MODULE_PATH`. That misleads —
it reads like a missing namespaced constant rather than a missing global one.

**Verified 2026-08-16.**

### 2. The destructor renders a page

```php
// Response.php:24-28
public function __destruct(){
    if(!static::$built){
        $this->build($this->body);
    }
}
```

`build()` (`:206`) adds `head`, `header`, `sideNav` and `footer`, each through `add()` (`:233`),
which resolves a path from three candidates in order:

```php
// Response.php:239-241
if(file_exists($path = $this->framePath.$piece.".php")
|| file_exists($path = $this->viewPath .$piece.".php")
|| file_exists($path = VIEW_PATH.$piece.".php")
```

So an object that merely goes out of scope **includes view files and emits a full HTML
document**. With the four constants defined, the probe above destructs without error but writes
`<!DOCTYPE html>…` to stdout. In a test run that is stray output at best; if `VIEW_PATH` is
undefined and the first two candidates miss, it is a fatal raised from inside `__destruct()` —
which surfaces at object-destruction or shutdown time, so it gets attributed to whatever
happens to be running, not to the test that created the object.

**Verified 2026-08-16:** with the constants defined, destruction is clean and emits a page;
without them, construction fails first and destruction is never reached.

## The `static::$built` trap

```php
// Response.php:15
protected static $built = false;
```

It is **static and never redeclared in any subclass**, so `static::$built` resolves to one slot
shared by every `Response` and `Module` subclass in the process.

The workaround currently used by `modules/TheButton/tests/TheButtonUserLookupTest.php` is to
flip it to `true` by reflection in `setUp()`, so `__destruct()` early-returns. That works, but:

- It is **one-way**. Restoring it in `tearDown()` was tried and abandoned: on a failing run the
  object is destroyed after the restore, and the destructor then fatals.
- It is **process-global for the rest of the run**. Any later test that instantiates a
  `Response`/`Module` subclass and expects `build()` to fire from `__destruct()` will silently
  not get it.

That is safe *today* only because no other test in the suite instantiates one — verified by
grepping `core/tests`, `modules/*/tests` and `aux/*/tests` on 2026-08-16. It is a landmine for
the next person, not a pattern to copy.

## Less destructive seams that already exist

Two early-returns are reachable without touching `$built`:

- **`Request::$acceptsJSON`** — `build()` returns at `:208` *before* setting `$built = true`
  (`:211`), so this leaves the shared flag untouched. This is the cleanest existing seam.
- **`Request::$madeWithAJAX`** — `add()` returns at `:234` for each piece, so nothing is
  included. `build()` still sets `$built = true`, so this one is also one-way.

Both are still global static mutation, just on `Request` rather than `Response`.

## Suggested fixes, roughly in order of value

1. **Guard the constant.** `add()`'s third candidate should be
   `defined('VIEW_PATH') && file_exists($path = VIEW_PATH.$piece.".php")`. A missing constant in
   the last branch of a fallback chain should not be fatal. One line, no behaviour change when
   the constant is present.
2. **Don't render from a destructor.** `__destruct()` calling `build()` means page assembly is
   triggered by garbage collection, which is why object lifetime and HTTP output are entangled
   here at all. Making `build()` explicit at the end of the request — `Application::run()`
   already owns that boundary — would remove the whole class of problem. Larger change; worth
   scoping separately.
3. **Define the four constants in `core/tests/bootstrap.php`.** Makes construction possible, but
   does *not* address the page-rendering side effect, and hardcodes paths into the test
   bootstrap. Treat as a stopgap, not a fix.
4. **Give `Mail::` a test seam.** Unrelated to the destructor, but it is the other half of why
   `sendInviteEmail()` is undrivable: it ends in a static `Mail::sendEmailTo()` with no
   injection point, so exercising it sends real email. Closing 1 and 4 together would make the
   two uncovered `TheButton` fixes testable.

## Reproduction

```bash
cd /var/www/html/unisolu.com/zero

# Constructor failure — the state a test actually runs in:
php -r 'require "core/tests/bootstrap.php";
        class Probe extends \Zero\Core\Response {}
        new Probe();'
# → Error: Undefined constant "Zero\Core\MODULE_PATH"

# With constants defined — destructs cleanly, but emits a page:
php -r 'define("ZERO_ROOT","/var/www/html/unisolu.com/zero/");
        define("ROOT_PATH","/var/www/html/");
        define("VIEW_PATH", ZERO_ROOT."app/frontend/frame/");
        define("MODULE_PATH", ZERO_ROOT."modules/");
        require "core/tests/bootstrap.php";
        class Probe extends \Zero\Core\Response {}
        $p = new Probe(); unset($p);'
# → <!DOCTYPE html> … a full page on stdout
```
