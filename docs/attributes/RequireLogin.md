# RequireLogin

**Class:** `Zero\Core\Attribute\RequireLogin` (`core/attribute/RequireLogin.php`)

The authentication gate. An endpoint that needs a signed-in user carries this
attribute; it does not re-implement the check in its own body.

## Usage

```php
use Zero\Core\Attribute\RequireLogin;

#[RequireLogin]
public function profile() {}

// Send anonymous visitors somewhere other than the login page
#[RequireLogin('/notes/welcome')]
public function dashboard() {}

// Class level: every endpoint
#[RequireLogin]
class UserDashboard extends Module {}
```

## Parameters

| Parameter | Type | Default | Description |
|---|---|---|---|
| `$redirect` | `?string` | `null` | Site-relative path to send anonymous visitors to, instead of `/user/login?r=y`. |

## The check

The request passes when a session is active and `Session::isLoggedIn()` is
true, which requires **a session for a verified account**:

```php
isset($_SESSION['email']) && isset($_SESSION['verified']) && $_SESSION['verified']
```

A `user_id` in the session is not enough. `AllowWithToken` calls
`Session::establish()` for any token owner without checking `verified`, and
such a session is refused here. The attribute and the helper share this one
predicate; see
[core/attribute/AUTH_GATE_CONSISTENCY.md](../../attribute/AUTH_GATE_CONSISTENCY.md).

## Denial

| Request | Result |
|---|---|
| Accepts JSON (`Request::$acceptsJSON`: an `Accept` subtype of `json` or `…+json`) | `HTTPError(401, "Authentication required")`. Nothing is stored. |
| Anything else | `$_SESSION['return_url']` is set to the request URI, then a `302` to `$redirect`, or to `/user/login?r=y`. |

A JSON caller gets a status rather than a redirect because an XHR follows a
redirect and hands its caller the login page's HTML. It stores no `return_url`
because an XHR has nothing to return to, and one left behind would misdirect
the next interactive login on that session.

The login page shows a "Login Required" message when `?r=y` is present. After
login, `Auth::complete()` and `Auth::token()` send the visitor to `return_url`,
or to `/` when there is none.

Both URLs are checked with `Request::isSiteRelative()` and an unsafe one is
dropped, not rewritten:

- `$redirect` falls back to `/user/login?r=y`. It is written in source, so this
  is a typo guard.
- `return_url` is not stored. It comes from `REQUEST_URI`, which the requester
  chooses, so this is the write end of an open-redirect boundary.

Site-relative means one leading slash and nothing a browser can read a hostname
out of. `//evil.example/x`, `/\evil.example/x` (a browser reads `\` as `/`),
anything containing a tab, CR or LF (the URL parser removes them), and absolute
URLs are all refused.

## Log lines

All at warn:

```
RequireLogin attribute blocked request: user not logged in
RequireLogin ignoring non-relative redirect 'https://example.com/'
RequireLogin refusing to store non-relative return_url '//evil.example/x'
```

## `RequireLogin` or `Session::isLoggedIn()`

Use the attribute to **gate**. Use `Session::isLoggedIn()` only where the
endpoint is public and its behaviour differs by login state:

```php
// Gate: anonymous visitors have no business here
#[RequireLogin]
public function create() { … }

// Behaviour branch: the page renders either way
public function index() {
    if (Session::isLoggedIn()) {
        $this->links = $this->model->getByUser(Session::userId());
    }
    $this->respond($this->viewPath . 'index.php');   // view shows a sign-in prompt
}
```

An `if (!Session::isLoggedIn()) { redirect/error; return; }` at the top of an
endpoint is a gate written by hand. Replace it with the attribute.

## Class level and method level

A method-level `RequireLogin` replaces the class-level one for that endpoint,
so a class can be gated wholesale and still send one endpoint to a different
landing page. The gate cannot be dropped for one endpoint, for a `__call()`
endpoint or otherwise, and a subclass does not inherit it. The rules are in
[../ATTRIBUTES.md](../ATTRIBUTES.md#class-level-and-method-level).

## With other auth attributes

- `#[RequirePermission]` calls `RequireLogin::handler()` itself (with the
  default login page), so an endpoint that declares it does not also need
  `#[RequireLogin]`. See [RequirePermission.md](RequirePermission.md).
- `#[AllowWithToken]` goes before it; see [../ATTRIBUTES.md](../ATTRIBUTES.md#order).
- Do not add `#[RequireAuthLevel]`. It is shelved; see
  [RequireAuthLevel.md](RequireAuthLevel.md).

Tests: `core/tests/Attribute/RequireLoginTest.php`
