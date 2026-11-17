# Zero Framework Attributes

## Overview

PHP 8 attributes provide declarative method/class-level configuration. The framework checks attributes via `Application::checkAttributes()` before endpoint execution.

`checkAttributes()` is public and static, and every dispatcher calls it for its own level: `Application::run()` for top-level modules, `Module::__call()` for submodules, `AdminResponse::routeToModuleAdmin()` for module-admin controllers. Each checks its own `(class, endpoint)` pair, so gates are additive down the nesting chain and nothing is evaluated twice.

**Execution Flow:**
1. Class and method attributes are collected and resolved (see **Replace vs. additive** below)
2. Each remaining attribute's `handler()` method executes in declaration order (class-level first, then method-level)
3. Handler returns `true` (continue) or throws `HTTPError` (stop and render error)
4. If all pass, endpoint method executes

`Application::resolveAttributes()` is the decision half, split out so callers — and `core/tests/module/AttributeOrderTest.php` — can ask for the resolved sequence without running it.

## Replace vs. additive

**Default: method-level replaces class-level of the same type.** If a method declares any attribute of type `X`, class-level attributes of type `X` are skipped for that endpoint. Types with no method-level counterpart still apply from the class.

Replace is right for attributes that name a **destination** or a **constraint**, because two of those contradict each other:

```php
#[AllowedMethods('GET')]        // Class default: GET only
class Api extends Module {

    public function list() {
        // Inherits AllowedMethods('GET') from the class
    }

    #[AllowedMethods('POST')]   // Overrides class-level AllowedMethods for this method
    public function create() {
        // POST only — class-level GET restriction does NOT apply here
    }
}
```

**`RequirePermission` is the exception: it is additive.** Both declarations run and the effective requirement is the **union**, because a permission is neither a destination nor a constraint — two of them are a longer list.

```php
#[RequirePermission('allow.receipt.view')]
class Receipts extends Module {

    #[RequirePermission('allow.receipt.edit')]
    public function update() {
        // needs allow.receipt.view AND allow.receipt.edit
    }
}
```

`Application::isAdditive()` names the exception; it is a list of one. Consequence: a class-level `RequirePermission` can never be dropped or narrowed for a single endpoint, so a class with any deliberately public endpoint must gate per-method instead.

**Classes using `__call()`:** a virtual endpoint has no `ReflectionMethod`, so `checkAttributes()` reads `__call()`'s own attributes on its behalf. Those are additive on top of the class-level ones and cannot override them — a class whose `__call()` serves anonymous traffic must not carry a class-level gate. `modules/TheButton` gates per-method for exactly this reason.

## Inheritance — class-level attributes are NOT inherited

`ReflectionClass::getAttributes()` does not walk the parent, so a subclass gets **none** of its parent's class-level attributes. **Method-level attributes are inherited**, because an inherited method resolves to the declaring class's `ReflectionMethod`, which carries them.

```php
#[RequireLogin]                        // does NOT reach Child
class Parent extends Module {
    #[RequirePermission('allow.x')]    // DOES reach Child::save()
    public function save() {}
}

class Child extends Parent {}          // ungated at class level
```

Gate every class that needs gating, in its own file. This is also why a submodule cannot inherit its parent module's gates: a submodule extends `Module`, not the parent module, and each dispatch level checks its own pair anyway.

## Available Attributes

| Attribute | Purpose | Location |
|-----------|---------|----------|
| `AllowedMethods` | Restrict HTTP methods (GET/POST/etc) | [core/docs/attributes/AllowedMethods.md](attributes/AllowedMethods.md) |
| `RequiredParams` | Validate required GET/POST parameters | [core/docs/attributes/RequiredParams.md](attributes/RequiredParams.md) |
| `Sanitize` | Sanitize POST/GET parameters before execution | [core/docs/attributes/Sanitize.md](attributes/Sanitize.md) |
| `Validate` | Validate parameter format/values (STUB) | [core/docs/attributes/Validate.md](attributes/Validate.md) |
| `RequireLogin` | Require user to be logged in (optional redirect target) | [core/docs/attributes/RequireLogin.md](attributes/RequireLogin.md) |
| `AllowWithToken` | Establish a session from a Bearer token, for API clients | `core/attribute/AllowWithToken.php` |
| `RequireAuthLevel` | Require minimum auth level (**SHELVED** — prefer `RequirePermission`) | [core/docs/attributes/RequireAuthLevel.md](attributes/RequireAuthLevel.md) |
| `RequirePermission` | Require specific permission(s) — **additive**, class + method | [core/docs/attributes/RequirePermission.md](attributes/RequirePermission.md) |
| `RestrictIP` | Restrict to whitelisted IPs / CIDR ranges | [core/docs/attributes/RestrictIP.md](attributes/RestrictIP.md) |
| `AuditLog` | Log endpoint access for security/compliance | [core/docs/attributes/AuditLog.md](attributes/AuditLog.md) |

## Quick Reference

```php
use Zero\Core\Attribute\{AllowedMethods, RequiredParams, Sanitize, RequireLogin, RequireAuthLevel, RequirePermission, RestrictIP, AuditLog};

// Typical secure endpoint pattern
// (RequireAuthLevel is shelved and deliberately absent — RequirePermission is
//  narrower and, unlike RequireAuthLevel, enforces login + verified email.)
#[RequireLogin]
#[RequirePermission('allow.api')]
#[AuditLog('admin_action', includeParams: true)]
#[AllowedMethods('POST')]
#[RequiredParams(['action', 'data'])]
#[Sanitize(['action', 'user_id'])]
public function secureEndpoint() {
    // All checks passed, access logged, and params sanitized
}
```

## When to Use Attributes

**Use for:**
- Authentication/authorization enforcement
- HTTP method validation
- Required parameter checking
- Audit logging and compliance tracking
- Cross-cutting concerns

**Manual validation for:**
- Complex/custom business logic
- Format validation (email, regex)
- Database-dependent checks beyond permissions
- Context-specific error messages

## Order — enforced, not advisory

```php
#[RestrictIP([...])]        //  1. Network deny; needs nothing from the session
#[AllowWithToken]           //  2. ESTABLISHES a session from a Bearer token
#[RequireLogin]             //  3. Who are you (redirect to login page)
#[RequireAuthLevel(1)]      //  4. Shelved — see below
#[RequirePermission('api')] //  5. What may you do (access request if missing)
#[AuditLog('action_type')]  //  6. Audit logging
#[AllowedMethods('POST')]   //  7. HTTP method
#[RequiredParams('data')]   //  8. Required parameters
#[Sanitize(['data'])]       //  9. Sanitization
#[Validate([...])]          // 10. Last, so it judges the sanitized value
```

**`core/tests/module/AttributeOrderTest.php` enforces this** across every module class
and every endpoint, including virtual ones dispatched through `__call()`. It
asks `Application::resolveAttributes()` for the sequence the framework will
actually run, so it sees replace/additive resolution rather than raw
declarations — and it fails if an attribute is added to `core/attribute/`
without being given a place in the order.

Attributes are NOT sorted at runtime. Declaration order is execution order, so
what you read in a module is what runs and the DevToolbar's attribute tab stays
truthful; the test is what keeps declaration order correct.

**The order spans class and method level.** `Application` runs class-level
attributes first, so a class-level `#[RequireLogin]` above a method-level
`#[AllowWithToken]` is wrong even though each declaration reads correctly on
its own — and that combination silently kills token auth on the endpoint, since
the Bearer caller is bounced to the login page before the attribute that would
have authenticated it ever runs. This is the case the test exists for.

Two placements that are easy to get backwards:

- **`AllowWithToken` before every gate.** It is the one attribute that *creates*
  a session rather than checking one.
- **`AuditLog` after `AllowWithToken`.** It records `Session::userId()`, so
  running it first logs every Bearer-authenticated action with no user against
  it.

**Auth workflow logic:**
1. **RequireLogin** - Bounces anonymous users to `/user/login?r=y`, or to
   `#[RequireLogin('/some/path')]` if the endpoint names one. JSON requests get
   `401` instead of a redirect.
2. **RequirePermission** - Redirects to the access request page (user is logged
   in, needs a specific permission). It opens by delegating to
   `RequireLogin::handler()`, so listing `#[RequireLogin]` alongside it is
   belt-and-braces rather than required.

**RequireAuthLevel is shelved — do not reach for it in new code, and never pair
it with `#[RequirePermission]`.** It is the only auth gate that does not delegate
to `RequireLogin`, so it alone does not enforce a verified email; and `auth.level`
is a setting in `user_settings`, separate from the `allow.*` grants, so a level gate
beside a permission gate silently vetoes permissions that were deliberately
granted. It holds a slot in the order above only because four modules —
`Test`, `User` (`permissions()`/`permission()`), `HelloWorld` (`secureHello()`)
and `Sitemap` — still declare it as their sole gate, and anything that can appear
in a pipeline needs a defined place in it. See
[core/docs/attributes/RequireAuthLevel.md](attributes/RequireAuthLevel.md) for the full
reasoning and the condition for un-shelving it.

This order ensures users are progressively validated and receive appropriate responses:
- Not logged in → friendly login page, or `401` for a JSON caller
- Logged in but missing a permission → access-request page, or `403` for a JSON caller

Check auth before logging or validating request data. Sanitize after confirming parameters exist.

## Gating is the attribute's job, not the endpoint's

`#[RequireLogin]` is THE authentication gate. Do not re-implement it in an
endpoint body:

```php
// WRONG — a gate written by hand
public function save() {
    if (!Session::isLoggedIn()) { $this->error('Authentication required'); return; }
    …
}

// RIGHT
#[RequireLogin]
public function save() { … }
```

`Session::isLoggedIn()` is for endpoints that are genuinely **public** and merely
*behave* differently when someone is signed in — a dashboard that shows a
sign-in prompt instead of a list, a click log that records a user id or `null`.
If the branch ends in a redirect, an error, or an early `return`, it is a gate
and belongs in an attribute. See
[core/attribute/AUTH_GATE_CONSISTENCY.md](../attribute/AUTH_GATE_CONSISTENCY.md).

## Creating Custom Attributes

```php
namespace Zero\Core\Attribute;

#[Attribute]
class MyAttribute {
    public function __construct($config) {
        $this->config = $config;
    }

    public function handler() {
        if (!$this->isValid()) {
            throw new \Zero\Core\Exception\HTTPError(403, 'Denied');
        }
        return true;
    }
}
```

**Requirements:**
- Namespace: `\Zero\Core\Attribute`
- Location: `core/attribute/`
- Must implement `handler()` returning `true` or throwing `HTTPError`
