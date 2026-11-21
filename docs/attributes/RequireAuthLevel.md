# RequireAuthLevel

**Class:** `Zero\Core\Attribute\RequireAuthLevel` (`core/attribute/RequireAuthLevel.php`)

**Status: SHELVED (2026-08-16). Do not use it on new endpoints; use
[`#[RequirePermission]`](RequirePermission.md).** It works and its existing
call sites stay. This page records why it is not the gate to pick.

Requires the user's `auth.level` setting to be at least a given number.

## Why it is shelved

**1. There is only one tier.** The attribute exists to express numeric tiers,
and every declaration asks for level `1`. On this install one user holds an
`auth.level` row. In practice it means "is this user special", which
`allow.admin` answers more precisely.

**2. Beside `#[RequirePermission]` it vetoes grants.** `auth.level` is a
setting in `user_settings`, separate from the `allow.*` capabilities, so a user
can hold `allow.admin` and still be refused by `RequireAuthLevel(1)` for want
of an `auth.level` row. Granting a permission is supposed to grant access.
**Never pair the two attributes.** The gates that sat beside a
`#[RequirePermission]`, and what they locked out, are listed in
[../DEPRECATING_CHANGES.md](../DEPRECATING_CHANGES.md) under
"`#[RequireAuthLevel]` — SHELVED".

**3. It is the only auth gate that does not require a verified account.**
`RequirePermission::handler()` opens with `(new RequireLogin())->handler()`.
`RequireAuthLevel::handler()` does not: it reads `auth.level` and never
`verified`. A session for an unverified account, which `#[AllowWithToken]` can
establish, passes `RequireAuthLevel` and fails the other two. No endpoint
declares both `#[AllowWithToken]` and `#[RequireAuthLevel]`, so nothing is
exposed, but it is the reason not to add new bare uses.

**4. It does not redirect.** An anonymous visitor gets a bare `401`, not the
login page.

Background: `core/attribute/AUTH_GATE_CONSISTENCY.md` § "The third gate".

### The condition for un-shelving

A real need for **blanket** privilege tiers that named `allow.*` capabilities
cannot express: more than one populated `auth.level`, and an ordering between
them where level 5 implies everything level 3 can do.

If it is un-shelved, first make `handler()` open with
`(new RequireLogin())->handler();` as `RequirePermission` does, so all three
auth gates share one definition of "logged in".

## Where it is declared

In each of these it has no `#[RequirePermission]` behind it, so removing it
would open the endpoint:

| Module | Scope | Notes |
|---|---|---|
| `User` (core) | `permissions()`, `permission()` | after `#[RequireLogin]` |
| `Test` (core) | class level, and `auth()` | dev/diagnostic module |
| `Sitemap` (core) | class level | the sole gate |
| `HelloWorld` (core) | `secureHello()` | demo endpoint |
| `Sandbox` (site) | class level | the site's scratch endpoints |

`User` holds the one real access decision. It wants a named `allow.*`
capability instead.

Three site classes import the attribute without declaring it, and carry
`#[RequirePermission]`: `Sqrlcam`, `ApiConfig\Admin\ApiConfigAdmin` and
`Plugins\Admin\PluginsAdmin`.

## Usage

```php
use Zero\Core\Attribute\RequireAuthLevel;

#[RequireAuthLevel(1)]
public function dashboard() {}     // auth.level >= 1
```

## Parameters

- `$level` (int, default `9`): the minimum `auth.level`. Higher is more
  privileged.

## The check

1. No active session (`session_status() == PHP_SESSION_NONE`): deny, before
   any lookup.
2. `Session::user()?->authLevel() ?? 0`. `User::authLevel()` is the
   `auth.level` key of `user_settings`, read from the database and memoized per
   request. It is not a session value, so a changed level takes effect on the
   user's next request.
3. Deny when the level is `0` or below `$level`.

A denial throws `HTTPError(401, "Authentication required")` for every caller,
and logs at warn:

```
RequireAuthLevel attribute blocked request: required level 5, user level: 3
RequireAuthLevel attribute blocked request: required level 5, user level: none
```

Tests: `core/tests/Attribute/RequireAuthLevelTest.php`
