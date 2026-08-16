# `#[BranchOnLogin]` — an attribute that names a setup callback

**Status:** Deferred 2026-08-16, deliberately, after counting Paste, TheButton
and Ttrpg specifically — see "Why it is deferred" below for the numbers.
**Trigger:** build it when a single class has ~4+ endpoints sharing the same
login-derived setup **and** something needs to opt out per endpoint. Without
that last clause the constructor already does the job for free.
**Related:** `core/attribute/AUTH_GATE_CONSISTENCY.md`, `docs/attributes/RequireLogin.md`

## The idea

Endpoints that are public but *behave* differently for a signed-in visitor open
with a preamble that computes login-derived state. The proposal is to move that
preamble into a named method and declare it:

```php
#[AllowedMethods('POST')]
#[RequiredParams(['content'])]
#[Sanitize(['title' => 'filename'])]
#[BranchOnLogin('resolveAuthorship')]
public function create() {
    // preamble gone; $this->showAuthor / $this->authorDisplay are already set
}

private function resolveAuthorship(bool $isLoggedIn): void { … }
```

The motivating case was `modules/Paste/Paste.php:90-99`.

## Why it is NOT a mode of `#[RequireLogin]`

The proposal arrived as `#[RequireLogin('resolveAuthorship')]`. That must not
ship under that name.

`#[RequireLogin]` was just made to mean one thing — see
`AUTH_GATE_CONSISTENCY.md`. Reading the attribute tells you the endpoint is
closed. A callback argument would make the *same* attribute mean the endpoint is
**open**, so anyone auditing gates would have to open the named callback to find
out which of the two it is. That is exactly the ambiguity the audit removed.

Whatever this becomes, it gets its own name.

## Three facts settled while evaluating it

**1. There IS a module instance when attributes run.** `core/Application.php`:

```php
$moduleInstance = new $Module();                   // :218
try {
    $this->checkForAttributes($Module, $endpoint); // :220
    $moduleInstance->{$endpoint}(...$args);        // :221
```

The instance is constructed *before* attribute evaluation; `checkForAttributes()`
simply does not receive it. Thread it through
`checkForAttributes()` → `handleAttributes()` → `handler()` and the callback can
use `$this->` normally. No statics, and no `#[BranchOnLogin('Module::method')]`
string form — both of which the original sketch assumed were forced.

The cost is real but small: `handler()` takes no arguments across all nine
existing attributes, so this widens a signature the other eight ignore.

**2. A real callable cannot be passed.** PHP attribute arguments must be
constant expressions. A closure literal is not one, so
`#[BranchOnLogin(fn($in) => …)]` will never compile. The argument can only be a
method-name string or a `[Class::class, 'method']` array. "Optionally provide a
callable" is really "optionally name a method".

**3. The callback must be `private`, invoked by reflection.** The original
sketch proposed marking it `#[AllowedMethods('none')]` to keep the router out.
That does work — `'NONE'` never matches a real HTTP method, so the handler
throws 405 — but it is a **blocklist you have to remember**. Forget it on one
callback and that method is a public endpoint, silently. Same failure class as
the hand-rolled gates the audit removed.

`private` + `ReflectionMethod::setAccessible()` makes the callback
*structurally* unreachable from the router instead of conventionally, with
nothing to forget. It also matches what the codebase already does for
non-endpoint methods — `TheButton::handlePress()`, `Paste::show()`.

## Ordering constraint, if it is ever built

`docs/ATTRIBUTES.md` documents `RequireLogin` **first** in the attribute chain.
A setup callback that reads request data wants `#[Sanitize]` to have run
*already*, so it belongs **last**.

Attributes that only *check* are order-tolerant. One that *computes from request
data* is not. Whatever ships must document this, because following the existing
documented order would break it silently.

## Why it is deferred: nothing needs it

The decisive argument is not any of the above. It is that every existing
attribute does something the method body **cannot** — halt before the method
runs (`RequireLogin`, `AllowedMethods`, `RequiredParams`), or mutate input
before it is read (`Sanitize`). "Call my own method before my other method" is
what the body already does, in one line, greppable and order-independent:

```php
public function create() {
    [$showAuthor, $authorDisplay] = $this->resolveAuthorship();
    …
}
```

The three modules that look most repetitive — Paste, TheButton, Ttrpg — were
counted properly on 2026-08-16, because "surely those need it" is the obvious
objection. They are repetitive. Almost none of it is login-*branching*:

| Pattern | Sites | A login branch? |
|---|---|---|
| Resolve → compare `user_id` → reject | Paste ×3, TheButton ×6 | No — that is `#[RequireOwnership]` |
| `$userId = User::getId();` at method top | Ttrpg ×13, TheButton ×6 | No — inside `#[RequireLogin]`, login is guaranteed |
| `pageTitle` / `activeSection` preamble | Ttrpg ×8 | No — per-endpoint constants |
| **Actual login-state branch** | **Paste ×4, TheButton ×3, Ttrpg ×1** | Yes |

And of those eight real branch sites, the attribute can address two:

- **Paste ×4.** `index()` and `fork()` set the identical one-liner
  `$this->isLoggedIn = …`; `create()` computes something different
  (authorship); `show()` needs `$paste`, so it cannot run before the method
  anyway. **Since resolved by hoisting the flag into Paste's constructor** —
  which also fixed a bug where `create()`'s error path rendered the signed-out
  form, and cost one line instead of an attribute plus a callback.
- **TheButton ×3.** `status()` is one branch. The other two —
  `handlePress()`, `handleView()` — are private helpers reached through
  `__call()`, and attributes cannot be attached to a `__call`-dispatched
  endpoint at all. Structurally out of reach.
- **Ttrpg ×1.** `index()`.

So the addressable set is two identical one-liners, in a class whose
constructor already solves it better. The class-level form of the attribute
buys exactly one thing a constructor line does not — per-endpoint opt-out —
and none of these three classes wants one.

Ttrpg's 13 `$userId = User::getId();` lines deserve a specific note, since they
look like the strongest case: they assign a **local**, which a callback cannot
set. Converting them would mean rewriting every downstream use as
`$this->userId` first — at which point the constructor does it again for free.

## What to build when the trigger fires

The version worth building is the **class-level** one: a class where a dozen
endpoints need the same login-derived setup. There `#[BranchOnLogin('resolveViewer')]`
on the class genuinely beats `$this->resolveViewer();` repeated twelve times,
and method-level overrides let one endpoint opt out — which a `__construct()`
call cannot do.

1. New attribute, own name. Not a `RequireLogin` argument.
2. Callback is `private`; invoke via `ReflectionMethod::setAccessible()`.
3. Pass `$moduleInstance` from `Application::run()` through
   `checkForAttributes()` and `handleAttributes()` into `handler()`.
4. Callback signature `f(bool $isLoggedIn): void`, reading the flag from
   `User::isLoggedIn()` — the same predicate `#[RequireLogin]` gates on, so the
   two can never drift.
5. Document that it must be declared **after** `#[Sanitize]`, and why.
6. Tests: that the callback runs, that it runs with the right `$isLoggedIn` for
   both session shapes, that a method-level declaration overrides a class-level
   one, and that the named method is NOT reachable as a URL endpoint.

## Reproducing the evidence

```bash
# Every login-state branch left in the tree, to check whether any class has
# accumulated enough of the same one to justify the attribute.
/usr/bin/grep -rn "isLoggedIn()\|isset(\$_SESSION\['user_id'\])" --include=*.php modules/ | grep -v /tests/
```

Use `/usr/bin/grep` — the shell's `grep` is a shim that honours `.gitignore` and
will under-report.
