# RequirePermission

**Class:** `Zero\Core\Attribute\RequirePermission` (`core/attribute/RequirePermission.php`)

The authorization gate. Requires the logged-in user to hold one or more named
capabilities.

## Usage

```php
use Zero\Core\Attribute\RequirePermission;

#[RequirePermission('allow.receipt.edit')]
public function edit($id = null) {}

// ALL of them
#[RequirePermission(['allow.admin', 'allow.receipt.delete'])]
public function delete($id) {}
```

A key is a **capability**: `allow.` and then lowercase words joined by dots
(`allow.admin`, `allow.receipt.view`, `allow.aws.bill.read`), held by the user
directly or through an active group. What a capability is, how it is stored and
how to grant one (`$user->grant()`, `$user->revoke()`) are in
[core/PERMISSIONS.md](../../PERMISSIONS.md#capabilities).

## Parameters

- `$permissions` (string|array): one key, or a list of keys that are all required.

## The check

1. `RequireLogin::handler()` runs first, with the default login page. The
   attribute implies login and a verified account, so `#[RequireLogin]`
   alongside it is redundant.
2. Each key is checked in order with `Session::can($key)`. The first one the
   user lacks denies the request.

`Session::can()` reads the user's capabilities from the database once per
request, not from the session, so a grant or revocation takes effect on the
user's next request. A failed lookup denies.

## Denial

`Request::$acceptsJSON` (an `Accept` subtype of `json` or `…+json`) decides the
shape:

| Caller | Anonymous (from `RequireLogin`) | Logged in, missing a key |
|---|---|---|
| Browser | `302` to `/user/login?r=y` | `302` to `/access-request/permission?p={key}` |
| JSON | `401` `Authentication required` | `403` `Permission required: {key}` |

A JSON caller gets a status rather than a redirect because an XHR follows a
redirect and hands its caller the page's HTML. The `HTTPError` renders through
the live module.

`/access-request/permission` is the AccessRequest module's form for asking for
the key. An admin approving the request grants it.

A missing key is logged at warn:

```
RequirePermission attribute blocked request: user 42 missing permission 'allow.admin'
```

The anonymous case is logged by `RequireLogin`.

## Class level and method level add up

This is the one attribute whose method-level declaration **adds to** the
class-level one instead of replacing it. Both run, and the requirement is the
union:

```php
#[RequirePermission('allow.receipt.view')]   // every endpoint needs this
class Receipts extends Module {

    #[RequirePermission('allow.receipt.edit')]
    public function update() {}              // needs view AND edit
}
```

So the gate can be neither dropped nor narrowed for one endpoint: a class with
any deliberately public endpoint, or a `__call()` that serves anonymous
traffic, gates per method. The general rules are in
[../ATTRIBUTES.md](../ATTRIBUTES.md#class-level-and-method-level).

## Never beside `#[RequireAuthLevel]`

`auth.level` is a setting in `user_settings`, separate from the `allow.*`
capabilities, so a level gate beside a permission gate refuses users whose
permission was deliberately granted. `RequireAuthLevel` is shelved; see
[RequireAuthLevel.md](RequireAuthLevel.md).

Tests: `core/tests/Attribute/RequirePermissionTest.php`
