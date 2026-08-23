# Proposal: `#[AllowPublic]`

**Status:** PROPOSED, not built. Written 2026-08-16 for a decision.
**Related:** `AUTH_GATE_CONSISTENCY.md`, `core/Application.php::checkAttributes()`

## The gap

`checkAttributes()` can add a gate to an endpoint. It cannot remove one.
`AUTH_GATE_CONSISTENCY.md` already states the consequence:

> There is no way to *drop* the gate for a single endpoint, so a class with any
> deliberately public endpoint must gate per-method rather than at class level.

That rule works, and `modules/S/S.php` is its reference implementation —
method-level `#[RequireLogin]` on all eight private endpoints, nothing on the
two public ones. `modules/Push` was just fixed the same way: four gated methods,
`vapidKey()` left public.

The cost is the **default when someone adds a method later**:

| | New method added later |
|---|---|
| Per-method gates | **ungated** — fail-open |
| Class gate + `#[AllowPublic]` | **gated** — fail-safe |

That is the whole argument for building this. It is not about saving three
attribute declarations.

It would also close a second gap. A class-level gate covers `__call()`, and
`__call()` has no `ReflectionMethod` to carry an override — so a class whose
`__call()` serves anonymous traffic cannot have a class-level gate at all. That
is exactly the `modules/TheButton` bug: a class-level `#[RequireLogin]` silently
killed `allow_anonymous` buttons and guest invite links, measurably, for as long
as the attribute was there.

## Sketch

```php
// core/attribute/AllowPublic.php
#[Attribute]
class AllowPublic {
    /** Presence is the whole signal — the resolver reads it, not this. */
    public function handler(): bool { return true; }
}
```

```php
// core/Application.php::checkAttributes(), replacing the existing filter
$isPublic = isset($methodNames[Attribute\AllowPublic::class]);

$effectiveClass = array_filter(
    $classAttributes,
    fn($a) => $isPublic && self::isAuthGate($a->getName())
        ? false
        : (!isset($methodNames[$a->getName()]) || self::isAdditive($a->getName()))
);

/**
 * Gates that #[AllowPublic] suppresses. Auth ONLY.
 *
 * AllowedMethods, RestrictIP, RequiredParams, Sanitize, Validate and AuditLog
 * are constraints rather than gates: a deliberately public endpoint still wants
 * its method whitelist, its input sanitising and its audit trail. Suppressing
 * those too would turn one opt-out into four.
 */
private static function isAuthGate(string $c): bool {
    return in_array($c, [
        Attribute\RequireLogin::class,
        Attribute\RequirePermission::class,
        Attribute\RequireAuthLevel::class,
    ], true);
}
```

`RequirePermission` must be named explicitly. It is additive as of `ceee404`, so
it is no longer dropped by the `$methodNames` check and would otherwise survive
`#[AllowPublic]`.

Usage:

```php
#[RequireLogin]
class Push extends Module {

    public function subscribe() { … }      // gated by the class

    #[AllowPublic]
    public function vapidKey() { … }       // deliberately open
}
```

## Arguments against

Worth stating plainly, because they are why this is a proposal and not a commit.

**A subtractive gate is a copy-paste hazard.** `#[RequireLogin]` in the wrong
place fails closed and someone reports a bug. `#[AllowPublic]` in the wrong
place fails open and nobody notices. The attribute that removes protection is
the one you least want duplicated by someone scaffolding a new endpoint from an
old one.

**It splits the rule in two.** Today there is one answer to "is this endpoint
gated": read the attributes that are present. With `AllowPublic` there are two —
what is present, and what is cancelled. `AUTH_GATE_CONSISTENCY.md` exists
because two predicates that could disagree already cost real bugs.

**The problem it solves is hypothetical here.** No module has been shown to have
lost a gate by adding a method. The fail-open default is a real risk, but an
unmeasured one.

**It is a resolver change.** `checkAttributes()` now runs from three dispatchers
(`Application::run()`, `Module::__call()`, `AdminResponse::routeToModuleAdmin()`)
and is the single point every gate passes through. The bar for changing it
should be high.

## Arguments for

**Fail-safe beats fail-open on an auth boundary.** This is the one that matters.
Every other consideration is style; this one is the direction the failure goes.

**It makes class-level gating usable with `__call()`.** Currently a whole
category of module — anything serving virtual endpoints, `S`, `Paste`,
`TheButton`, `Links` — is barred from class-level gating outright.

**Audit becomes greppable.** `grep -rn "AllowPublic"` enumerates every
deliberately public endpoint in the codebase. Today the public endpoints are the
ones with *no* attribute, which cannot be grepped for and cannot be
distinguished from the ones someone forgot.

That last point may be the strongest practical one. `modules/Push` was
unprotected for its entire life and nothing surfaced it — an absent attribute
looks identical to an oversight.

## If it is built

1. `#[AllowPublic]` on a method with no class-level gate should be a no-op, not
   an error. It still documents intent.
2. It should probably `Console::debug()` when it actually suppresses something,
   so the DevToolbar attribute tab shows the cancellation rather than a silent
   absence. `$attributeLog` already has a `scope` field and an `overridden`
   flag; a `suppressed` marker fits alongside them.
3. Tests belong in `core/tests/AttributeOverrideTest.php`, which already covers
   replace, additive and the `__call` fallback. Needed: suppression of each of
   the three auth gates, non-suppression of `AllowedMethods`, and the no-op case.
4. Convert `modules/Push` to class-level `#[RequireLogin]` + `#[AllowPublic]` on
   `vapidKey()` as the first user, and revisit `modules/TheButton`, where the
   fail-safe default earns the most.

## Recommendation

Build it, but not urgently, and not as part of any other change. The Push fix
did not need it and neither did `S`. Its value is preventing the *next*
unprotected endpoint, and that value is real but deferred.

If it is declined, the alternative is to make the current rule enforceable
rather than conventional: a test, or a `bin/` script, that lists every public
method on a module class carrying no gate attribute, so a new ungated endpoint
shows up in review instead of in a security audit.
