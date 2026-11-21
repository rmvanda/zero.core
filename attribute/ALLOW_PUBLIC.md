# Proposal: `#[AllowPublic]`

**Status:** PROPOSAL, not implemented. Written 2026-08-16 for a decision; no decision
recorded. Checked against the code 2026-11-21.
**Related:** `AUTH_GATE_CONSISTENCY.md`, `Application::resolveAttributes()` in
`core/Application.php`

## The gap

A method-level attribute can replace a class-level one of the same type, or (for
`RequirePermission`) add to it. Nothing can remove a class-level gate for one endpoint. So a
class with any deliberately public endpoint must gate per method, not at class level.

That rule works. `core/modules/Push` follows it (four gated methods, `vapidKey()` public), as
do the site's `modules/S` and `modules/TheButton`.

The cost is the default when someone adds a method later:

| | New method added later |
|---|---|
| Per-method gates | **ungated** — fail-open |
| Class gate + `#[AllowPublic]` | **gated** — fail-safe |

That is the argument for building this. It is not about saving attribute declarations.

A second gap: attributes on `__call()` are additive on top of the class-level ones, so a
class whose `__call()` serves anonymous traffic cannot have a class-level gate at all. A
class-level `#[RequireLogin]` on `modules/TheButton` once disabled its anonymous buttons and
guest invite links for exactly this reason.

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
// Application::resolveAttributes(), replacing the existing $effectiveClass filter
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
 * its method whitelist, its input sanitising and its audit trail.
 */
private static function isAuthGate(string $c): bool {
    return in_array($c, [
        Attribute\RequireLogin::class,
        Attribute\RequirePermission::class,
        Attribute\RequireAuthLevel::class,
    ], true);
}
```

`RequirePermission` must be named explicitly: it is additive (`Application::isAdditive()`),
so the `$methodNames` check does not drop it and it would survive `#[AllowPublic]`.

Because `$methodNames` is also filled from `__call()`'s attributes for a virtual endpoint,
`#[AllowPublic]` on `__call()` would open the virtual routes of a class-gated module. That is
the second gap above, closed by the same change.

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

- **A subtractive gate is a copy-paste hazard.** `#[RequireLogin]` in the wrong place fails
  closed and someone reports a bug. `#[AllowPublic]` in the wrong place fails open and nobody
  notices.
- **It splits the rule in two.** Today "is this endpoint gated" has one answer: read the
  attributes present. With `AllowPublic` there is what is present and what is cancelled.
- **The problem is unmeasured here.** No module has been shown to have lost a gate by adding
  a method.
- **It is a resolver change.** `resolveAttributes()` is the single point every gate passes
  through, for all four dispatchers (`Application::run()`, `Module::__call()`,
  `AdminResponse::routeToModuleAdmin()`, `Response::__call()`), and
  `core/tests/module/AttributeOrderTest.php` reads its result.

## Arguments for

- **Fail-safe beats fail-open on an auth boundary.** This is the one that matters.
- **It makes class-level gating usable with `__call()`.** Modules serving virtual endpoints
  (`S`, `Paste`, `TheButton`, `Links`) cannot gate at class level today.
- **Audit becomes greppable.** `grep -rn "AllowPublic"` lists every deliberately public
  endpoint. Today a public endpoint is one with no attribute, which cannot be grepped for and
  cannot be told from one someone forgot. `core/modules/Push` was ungated for its whole life
  until 2026-08-16 and nothing surfaced it.

## If it is built

1. `#[AllowPublic]` on a method with no class-level gate is a no-op, not an error. It still
   documents intent.
2. Mark the suppression in `Application::$attributeLog` (it already has `scope` and
   `overridden`; add `suppressed`), so the DevToolbar attribute tab shows the cancellation.
3. Give it a place in `ORDER` in `core/tests/module/AttributeOrderTest.php`;
   `testEveryCoreAttributeIsRanked()` fails otherwise.
4. Tests go in `core/tests/AttributeOverrideTest.php`, which covers replace, additive and the
   `__call` fallback. Needed: suppression of each of the three auth gates, non-suppression of
   `AllowedMethods`, the no-op case, and `#[AllowPublic]` on `__call()`.
5. First user: `core/modules/Push`, as class-level `#[RequireLogin]` + `#[AllowPublic]` on
   `vapidKey()`. Then revisit `modules/TheButton`.
6. Update `core/docs/ATTRIBUTES.md` and the docblock of `RequireLogin`, which both state that
   a gate cannot be dropped for one endpoint.

## Recommendation

Build it, not urgently, and not as part of another change. Its value is preventing the next
unprotected endpoint.

If it is declined, make the current rule enforceable instead: a test or a `bin/` script that
lists every public method on a module class carrying no gate attribute, so a new ungated
endpoint shows up in review. No such test exists today.
