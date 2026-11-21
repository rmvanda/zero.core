# `#[BranchOnLogin]` — an attribute that names a setup callback

**Status:** PROPOSAL, not implemented. Deferred 2026-08-16 because nothing needs it.
Checked against the code 2026-11-21.
**Build it when:** a single class has about four or more endpoints sharing the same
login-derived setup **and** something needs to opt out per endpoint. Without the opt-out, the
constructor already does the job.
**Related:** `AUTH_GATE_CONSISTENCY.md`, `core/docs/attributes/RequireLogin.md`

## The idea

Endpoints that are public but behave differently for a signed-in visitor open with a
preamble that computes login-derived state. Move the preamble into a named method and
declare it:

```php
#[AllowedMethods('POST')]
#[RequiredParams(['content'])]
#[Sanitize(['title' => 'filename'])]
#[BranchOnLogin('resolveAuthorship')]
public function create() {
    // $this->showAuthor / $this->authorDisplay are already set
}

private function resolveAuthorship(bool $isLoggedIn): void { … }
```

## Why it is deferred

Every existing attribute does something the method body cannot: halt before the method runs
(`RequireLogin`, `AllowedMethods`, `RequiredParams`) or change input before it is read
(`Sanitize`). "Call my own method before my other method" is one line in the body, greppable
and independent of attribute order.

The three modules that looked most repetitive were counted on 2026-08-16:

| Pattern | Sites | A login branch? |
|---|---|---|
| Resolve → compare `user_id` → reject | Paste ×3, TheButton ×6 | No — that is `#[RequireOwnership]` (`TODO.md`) |
| `$userId = Session::userId();` at method top | Ttrpg ×13, TheButton ×6 | No — inside `#[RequireLogin]`, login is guaranteed |
| `pageTitle` / `activeSection` preamble | Ttrpg ×8 | No — per-endpoint constants |
| Actual login-state branch | Paste ×4, TheButton ×3, Ttrpg ×1 | Yes |

Of the eight real branches the attribute could reach two, both in Paste, and Paste now sets
`$this->isLoggedIn` once in its constructor. TheButton's branches are in private helpers
reached through `__call()`, which cannot carry a per-endpoint attribute. Ttrpg's
`$userId = …` lines assign a local, which a callback cannot set.

## Constraints, if it is built

1. **Its own name.** Not an argument to `#[RequireLogin]`. `#[RequireLogin]` means the
   endpoint is closed; a callback form would make the same attribute mean it is open, and
   anyone auditing gates would have to read the callback to tell which.
2. **The argument is a method name.** PHP attribute arguments must be constant expressions,
   so a closure cannot be passed. A method-name string (or `[Class::class, 'method']`) only.
3. **The callback is `private`, invoked by reflection.** A public method is a URL endpoint.
   Marking it `#[AllowedMethods('none')]` would work but is a blocklist that has to be
   remembered on every callback; `private` cannot be forgotten.
4. **The attribute needs the module instance, and attributes are not given one.**
   `handler()` takes no arguments on all ten existing attributes, and
   `Application::checkAttributes(string $class, string $endpoint)` has four callers:
   - `Application::run()` — constructs the module first, then checks. An instance exists.
   - `Module::__call()` (submodules), `AdminResponse::routeToModuleAdmin()` and
     `Response::__call()` (standalone Index pages) — check **before** construction, on
     purpose, so a gate precedes the constructor's work. No instance exists.

   So the callback cannot simply ride the existing attribute pass. Either it runs in a second
   step after construction in every dispatcher, or the instance is threaded through
   `checkAttributes()` → `handleAttributes()` → `handler()` and the three early callers change
   order for this attribute only. Decide this first; it is most of the work.
5. **Order.** A callback that reads request data must run after `#[Sanitize]` and
   `#[Validate]`, so it goes last in `ORDER` in `core/tests/module/AttributeOrderTest.php`
   (a new attribute must be ranked there or `testEveryCoreAttributeIsRanked()` fails) and in
   the order listed in `core/docs/ATTRIBUTES.md`.
6. **Callback signature** `f(bool $isLoggedIn): void`, the flag read from
   `Session::isLoggedIn()` — the predicate `#[RequireLogin]` gates on, so the two cannot
   drift.
7. **Class-level is the form worth building:** `#[BranchOnLogin('resolveViewer')]` on a class
   with a dozen endpoints, with a method-level declaration able to override it. That is the
   one thing a `__construct()` call cannot do.
8. **Tests:** the callback runs; it gets the right `$isLoggedIn` for both session shapes; a
   method-level declaration overrides a class-level one; the named method is not reachable as
   a URL endpoint.

## Checking whether the trigger has fired

From the site root, list every login-state branch and see whether one class has accumulated
enough of the same one:

```bash
/usr/bin/grep -rn "isLoggedIn()\|isset(\$_SESSION\['user_id'\])" --include=*.php modules/ core/modules/ | grep -v /tests/
```

Use `/usr/bin/grep` — the shell's `grep` is a shim that honours `.gitignore` and
under-reports.
