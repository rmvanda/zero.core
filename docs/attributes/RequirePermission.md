# RequirePermission

**Namespace:** `\Zero\Core\Attribute\RequirePermission`

**Purpose:** Require specific named permission(s). More granular than auth levels.

## Usage

```php
use Zero\Core\Attribute\RequirePermission;

#[RequirePermission('allow.receipt.edit')]
public function edit($id = null) {
    // Single permission required
}

#[RequirePermission(['allow.admin', 'allow.receipt.delete'])]
public function delete($id) {
    // User must have BOTH permissions
}

// Combined
#[RequirePermission('allow.comfyui')]
#[AllowedMethods('POST')]
#[RequiredParams('action')]
public function apiEndpoint() {
    // Permission + POST + params
}
```

Keys follow an `allow.{module}.{verb}` convention — `allow.admin`,
`allow.receipt.view`, `allow.aws.bill.read`. A key is a **capability**: a row in
the core `permission` table on `entity_id` 0, held by the user directly or by an
active group they belong to. See
[../../core/PERMISSIONS.md](../../core/PERMISSIONS.md).

## Parameters

- `$permissions` (string|array) - Single permission or array (all required)

## Class level and method level are ADDITIVE

This is the one attribute whose method-level declaration **adds to** the
class-level one instead of replacing it. Both run, and the effective
requirement is the union:

```php
#[RequirePermission('allow.receipt.view')]   // every endpoint needs this
class Receipts extends Module {

    #[RequirePermission('allow.receipt.edit')]
    public function update() {
        // needs allow.receipt.view AND allow.receipt.edit
    }
}
```

Every other attribute uses replace semantics, because they name a *destination*
or a *constraint* and two of those contradict each other. Permissions do not: two
of them are a longer list. `Application::isAdditive()` names this class as the
sole exception.

Consequence: there is no way to **drop** or **narrow** the gate for a single
endpoint, so a class with any deliberately public endpoint must gate per-method
rather than at class level.

## Permission Check

1. Delegates to `RequireLogin::handler()` first — if the user is not logged in,
   they are redirected to `/user/login?r=y`. **RequirePermission implicitly
   requires login;** declaring `#[RequireLogin]` alongside is redundant but
   harmless.
2. For each permission, `Session::can($key)` →
   `Session::user()?->can($key)` → `User::capabilities()`:
   - Read through to `permission` on every request, memoized **per `User`
     instance** for the life of that request only. A grant or revocation takes
     effect on the user's next request; they do not need to log out and back in.
   - The user holds the key if their own row or an active group's row grants it.
   - Any lookup failure denies — `capabilities()` catches `\Throwable` and
     returns an empty list rather than propagating.
3. User must have ALL listed permissions.

## Database Query

One query per user per request loads every capability the user holds:

```sql
SELECT DISTINCT permission_key
FROM permission
WHERE entity_id = 0 AND can_read = 1
  AND (user_id = ? OR group_id IN (/* the user's active groups */))
```

Permission granted only if the key is in that list.

Grant and revoke through the user object, or on the Admin module's user screen:

```php
$user->grant('allow.polly', $adminId);
$user->revoke('allow.polly');
```

## When to Use

- Fine-grained access control
- Feature flags
- User-specific capabilities
- Permission-based systems (vs role-based)

## Auth Workflow

`#[RequirePermission]` alone is the whole chain. It calls `RequireLogin::handler()`
itself, so this is all an endpoint needs:

```php
#[RequirePermission('allow.aws.bill.read')]
public function bill() { }
```

**Never pair it with `#[RequireAuthLevel]`.** `auth.level` is a setting in
`user_settings`, separate from the `allow.*` grants, so a level gate beside a permission
gate silently vetoes permissions that were deliberately granted — only user 1 holds
an `auth.level` row, so user 9 holds `allow.admin` and is refused anyway. A second,
invisible tier that overrides a permission grant is a footgun. `RequireAuthLevel`
is shelved; see
[../../core/attribute/AUTH_GATE_CONSISTENCY.md](../../core/attribute/AUTH_GATE_CONSISTENCY.md)
§ "The third gate" and [RequireAuthLevel.md](RequireAuthLevel.md).

**Access Request Flow:**
- User is logged in and has RequireAuthLevel (if specified)
- User lacks specific permission
- Redirects to `/access-request/permission?p={permission}`
- User can submit access request form
- Admin reviews and grants the permission (`$user->grant()`)

## Error Response

The response shape follows the caller, not the denial. `Request::$acceptsJSON`
decides:

| Caller | Anonymous (via `RequireLogin`) | Logged in, missing permission |
|---|---|---|
| Browser (`Accept: text/html`) | `302` → `/user/login?r=y` | `302` → `/access-request/permission?p={permission}` |
| JSON (`*/json` or `*+json`) | `401` "Authentication required" | `403` "Permission required: {permission}" |

A JSON caller gets a real status because an XHR follows a redirect
transparently and hands its caller the access-request page's HTML, which then
fails to parse — the failure surfaces as a parse error rather than "you do not
have access". The `HTTPError` bubbles to `Application::run()` and renders
through the live module, so the 403 comes back in whatever shape that module
already speaks.

Naming the permission in the detail is not a leak: the caller is authenticated,
and the redirect URL it replaces carried the same key as `?p=`.

**Access request flow (HTML only):**
- Redirects to `/access-request/permission?p={permission}`
- Shows the access request form, auto-populated with the requested permission
- Admin reviews and grants it (`$user->grant()`)

## Debug Output

```
RequirePermission attribute blocked request: user 42 missing permission 'allow.admin'
```

The anonymous case is logged by the delegate instead, as
`RequireLogin attribute blocked request: user not logged in`. There is no
success line.

## Notes

- Guest users always denied
- Read through to the database on every request, memoized per `User` instance
- Permissions checked in order; first failure stops
- Permissions are rows in the `permission` table, held by a user or a group

## Implementation

Location: `core/attribute/RequirePermission.php`
