# RequireAuthLevel

**Namespace:** `\Zero\Core\Attribute\RequireAuthLevel`

**Status: SHELVED (2026-08-16). Do not reach for this on new endpoints — use
`#[RequirePermission]`.** It still works and the existing call sites are staying;
what follows records why it is not the gate to pick, so the decision does not have
to be re-derived.

**Purpose:** Require a minimum blanket authentication level to access an endpoint.

## Why it is shelved

`RequireAuthLevel` was designed as the coarse-grained alternative to
`RequirePermission`: one numeric tier per user instead of a set of named grants.
That alternative has never earned its keep.

**1. There is only ever one tier.** Across the whole install, `user_settings`
holds exactly one `auth.level` row — user 1, value `1`. Every `#[RequireAuthLevel]`
in the codebase asks for level `1`. The tiering the attribute exists to express has
no second rung, so in practice it degrades to "is this user special", which
`allow.admin` already answers more precisely.

**2. Beside `#[RequirePermission]`, it does the wrong job.** It is not redundant
with the permission check — it silently vetoes permission grants. `auth.level` is
a setting in `user_settings`, separate from the `allow.*` grants, so a user can hold
`allow.admin` and still be refused by `RequireAuthLevel(1)` for want of an
`auth.level` row. That was live: user 9 held both `allow.admin` and
`network.mapper` yet was locked out of Admin, System, Postgres, Bingo and
`NetworkMap::save()`/`load()`, because only user 1 has ever had an `auth.level`.

Granting a permission is supposed to grant access. A second, invisible tier that
overrides it is a footgun. **Never pair the two attributes.**

**3. It is the only auth gate that does not enforce a verified email.**
`RequirePermission::handler()` opens by delegating:

```php
// core/attribute/RequirePermission.php — handler() opens with this
(new RequireLogin())->handler();
```

so login *and* verified-email are enforced for free, from a single source of
truth. `RequireAuthLevel::handler()` does not delegate. It checks
`session_status()`, then `Session::user()?->authLevel()` — and `authLevel()` reads
`user_settings['auth.level']`, never `users.verified`. A session established for an
unverified account (which `#[AllowWithToken]` can produce — see
`core/attribute/AUTH_GATE_CONSISTENCY.md`) therefore passes `RequireAuthLevel`
while failing `RequireLogin` and `RequirePermission`.

That gap is **latent, not live**: none of the four remaining call sites carries
`#[AllowWithToken]`, and no module anywhere pairs the two attributes. But it is
the reason not to add new bare uses.

### The condition for un-shelving

A genuine need for **blanket** privilege tiers that are not expressible as named
`allow.*` grants — i.e. more than one populated `auth.level`, and a meaningful
ordering between them where "level 5 implies everything level 3 can do". Until
then, granular permissions are both narrower and better enforced.

If it is un-shelved, fix the delegation first: make `handler()` open with
`(new RequireLogin())->handler();` exactly as `RequirePermission` does, so all
three auth gates share one definition of "logged in".

## Current usage

Four modules declare it, and in each one it is a **bare** gate with no
`#[RequirePermission]` behind it — removing it would leave the endpoint open:

| Module | Scope | Notes |
|---|---|---|
| `User` | `permissions()`, `permission()` | paired with `#[RequireLogin]`; the admin-only half of the permission editor |
| `Test` | class-level + `auth()` | dev/diagnostic module |
| `Sitemap` | class-level | the sole gate |
| `HelloWorld` | `secureHello()` | demo endpoint, exists to show the attribute |

`User` is the only one holding a real access decision. It wants a named `allow.*`
grant instead — that is the migration this attribute is waiting on, not a rewrite.

The gates that once sat *beside* a `#[RequirePermission]` are listed in
`DEPRECATING_CHANGES.md`, along with why they had to go.

**Dead imports** (`use` with no corresponding attribute): `Sqrlcam.php:5`,
`ApiConfig/Admin/ApiConfigAdmin.php`, and
`Plugins/Admin/PluginsAdmin.php` (whose `//#[RequireAuthLevel(9)]` at :16
is commented out). All three classes already carry `#[RequirePermission]`.

## Usage

```php
use Zero\Core\Attribute\RequireAuthLevel;

#[RequireAuthLevel(1)]
public function dashboard() {
    // auth.level >= 1 required
}

// Class-level — gates every endpoint
#[RequireAuthLevel(1)]
class AdminPanel extends Module { }
```

## Parameters

- `$level` (int, default `9`) - Minimum required auth level

## Authentication Check

`RequireAuthLevel::handler()`, in order:

1. `session_status() == PHP_SESSION_NONE` → deny. Checked **first**, before
   touching `Session::user()`, because `authLevel()` is a database-backed call and
   there is no sense paying for it just to be denied a line later.
2. `$userLevel = \Zero\Core\Session::user()?->authLevel() ?? 0` — reads
   `user_settings` where `setting_key = 'auth.level'`, memoized per instance.
   **Not** a session value: a revoked or raised level takes effect on the very
   next request rather than waiting for logout.
3. Deny when `!$userLevel || $userLevel < $this->level`.

It does **not** check `users.verified`. See "Why it is shelved" above.

## Error Response

Throws `HTTPError(401, "Authentication required")`, which bubbles to
`Application::run()` and renders through the live module. It does not return an
`Error` object, and it does not redirect — an anonymous visitor hitting a
`RequireAuthLevel`-only endpoint gets a bare 401 rather than the login page.
That asymmetry with `RequireLogin` is another reason to prefer the other gates.

## Debug Output

```
RequireAuthLevel attribute blocked request: required level 5, user level 3
RequireAuthLevel attribute blocked request: required level 5, user level: none
```

## Notes

- Higher numbers = higher privileges. Default is `9` when the level is omitted.
- Levels live in `user_settings` under the key `auth.level`, **not** in `$_SESSION`.
  `User::authLevel()` reads through to the database and memoizes per instance, so a
  change takes effect on the user's next request.

## Implementation

Location: `core/attribute/RequireAuthLevel.php`

## See also

- [RequirePermission](RequirePermission.md) — the gate to use instead
- [RequireLogin](RequireLogin.md) — the single definition of "logged in"
- `core/attribute/AUTH_GATE_CONSISTENCY.md` — why the gates were unified
