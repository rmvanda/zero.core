# RequireLogin

**Namespace:** `\Zero\Core\Attribute\RequireLogin`

**Purpose:** THE authentication gate. An endpoint that needs a signed-in user
carries this attribute; it does not re-implement the check in its own body.

## Usage

```php
use Zero\Core\Attribute\RequireLogin;

#[RequireLogin]
public function profile() {
    // User must be logged in
}

// Send anonymous visitors somewhere other than the login page
#[RequireLogin('/notes/welcome')]
public function dashboard() {
    // Anonymous → /notes/welcome instead of /user/login?r=y
}

// Class-level
#[RequireLogin]
class UserDashboard extends Module {
    // All endpoints require login
}

// Combined with other attributes
#[RequireLogin]
#[AllowedMethods('POST')]
#[RequiredParams('data')]
public function saveSettings() {
    // Login + POST + params required
}
```

## Parameters

| Parameter | Type | Default | Description |
|---|---|---|---|
| `$redirect` | `?string` | `null` | Site-relative path to send anonymous visitors to, instead of `/user/login?r=y`. |

`$redirect` must pass `Request::isSiteRelative()` — see "Denial behaviour" below
for exactly what that admits. Anything else is ignored, logged via
`Console::warn()`, and the default login page is used. The value comes from
source rather than from the request, so this is a typo guard rather than a
security boundary, but a stray `https://…` would still be an open redirect and
falling back is the harmless failure.

## Authentication check

Calls `Session::isLoggedIn()`, which requires **a session for a verified account**:

```php
isset($_SESSION['email']) && isset($_SESSION['verified']) && $_SESSION['verified']
```

Testing `isset($_SESSION['user_id'])` instead would be strictly weaker: a session
holding a `user_id` for an *unverified* account would pass. `AllowWithToken`
produces exactly that shape, because it calls `Session::establish()` for any token
owner without checking `verified`. Gating on `isLoggedIn()` keeps the attribute
and the helper to a single predicate; see
[core/attribute/AUTH_GATE_CONSISTENCY.md](../../core/attribute/AUTH_GATE_CONSISTENCY.md).

## Denial behaviour

| Request | Result |
|---|---|
| `Accept: application/json` | `HTTPError(401, "Authentication required")` |
| Anything else | `302` to `$redirect` (or `/user/login?r=y`), with `$_SESSION['return_url']` set to the current URI |

Both URLs in that row — the `$redirect` override and the stored `return_url` —
are checked with `Request::isSiteRelative()`, and an unsafe one is dropped
rather than rewritten: the override falls back to `/user/login?r=y`, and
`return_url` is simply not stored, which leaves `Auth::complete()` /
`Auth::token()` on their existing `/` default.

"Site-relative" means one leading slash and nothing a browser can read a
hostname out of — so `//evil.example/x`, `/\evil.example/x` (a browser
normalizes `\` to `/`) and a tab- or newline-split equivalent (the URL parser
*removes* those characters) are all refused, not just absolute URLs. The
override is authored in source, so for it this is a typo guard; `return_url`
comes from `REQUEST_URI` and is chosen by the requester, so for that one it is
the write end of an open-redirect boundary.

A JSON denial throws before storing anything: an XHR has no bounce to return
to, and a `return_url` left behind would send the *next* interactive login on
that session somewhere the visitor never asked to go.

JSON callers get a real 401 rather than a 302 because an XHR follows a redirect
transparently and hands its caller the login page's HTML, which then fails to
parse as JSON — surfacing as a confusing parse error rather than "you are not
logged in". The `HTTPError` bubbles to `Application::run()` and renders through
the live module, so the 401 comes back in whatever shape that module speaks.

The login page shows a "Login Required" message when `?r=y` is present.

**Already logged in:** the attribute passes silently and execution continues to
the next attribute/endpoint.

## `RequireLogin` vs `Session::isLoggedIn()`

Use the attribute to **gate**. Use `Session::isLoggedIn()` only where the endpoint
is genuinely public and its *behaviour* differs by login state:

```php
// Gate — anonymous visitors have no business here
#[RequireLogin]
public function create() { … }

// Behaviour branch — the page renders either way
public function index() {
    if (Session::isLoggedIn()) {
        $this->links = $this->model->getByUser(Session::userId());
    }
    $this->respond($this->viewPath . 'index.php');   // view shows a sign-in prompt
}
```

An `if (!Session::isLoggedIn()) { redirect/error; return; }` at the top of an
endpoint is a gate written by hand — replace it with the attribute.

## Class level vs method level

A method-level `RequireLogin` **replaces** the class-level one for that endpoint
(see `Application::checkAttributes()`), so a class can be gated wholesale and
still route one endpoint to a different landing page. There is no way to *drop*
the gate for a single endpoint, so **a class with any deliberately public
endpoint must gate per-method rather than at class level**.

Class-level attributes are not inherited, so a subclass of a gated class is
ungated unless it declares its own. See
[../ATTRIBUTES.md](../ATTRIBUTES.md#inheritance--class-level-attributes-are-not-inherited).

### `__call()` endpoints

`Application` resolves attributes against the URL endpoint name, and a
`__call`-dispatched endpoint has no `ReflectionMethod` of its own, so it can
carry no attributes itself. `checkAttributes()` reads the attributes on
`__call()` instead, which lets a class gate everything it dispatches
dynamically. Those are **additive** on top of the class-level ones — they cannot
express "class gated, `__call` deliberately public".

**A class whose `__call()` serves anonymous traffic must not carry a
class-level `RequireLogin`.** Gate each real method instead, which is what
`modules/TheButton` does — its `allow_anonymous` buttons and guest invite links
are served through `__call()`.

## Auth workflow

RequireLogin is the first auth check in a chain:

```php
#[RequireLogin]                    // 1. Ensure user logged in
#[RequirePermission('allow.api')]  // 2. Check specific permission
public function secureEndpoint() {
    // All checks passed
}
```

In fact `RequirePermission::handler()` calls `RequireLogin::handler()` itself,
so the anonymous case has one implementation rather than two, and declaring both
is belt-and-braces rather than required.

**Do not add `#[RequireAuthLevel]` to this chain.** It is shelved, and pairing it
with a permission gate silently vetoes grants — see
[RequireAuthLevel.md](RequireAuthLevel.md).

**Why this order?**
- RequireLogin: bounces anonymous users to the login page (401 for JSON)
- RequirePermission: redirects to the access-request page (403 for JSON) once the
  caller is known to be signed in

## Debug output

```
RequireLogin attribute blocked request: user not logged in
RequireLogin ignoring non-relative redirect 'https://example.com/'
```

## Notes

- Checks login status only, not permissions
- Use `RequirePermission` for anything finer — it gates on login too
- Combine with `AllowedMethods` / `RequiredParams` / `Sanitize` for a secure endpoint

## Implementation

Location: `core/attribute/RequireLogin.php`
Tests: `core/tests/Attribute/RequireLoginTest.php`
