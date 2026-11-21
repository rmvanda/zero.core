# Attribute System TODO

**Status:** Open. Lists what is not built or not fixed; finished work is not kept here.
Checked against the code 2026-11-21.

Implemented attributes are documented in `core/docs/ATTRIBUTES.md` and
`core/docs/attributes/`. Proposals with their own file:

- `ALLOW_PUBLIC.md` — `#[AllowPublic]`, an opt-out from a class-level auth gate. Proposed, not built.
- `TODO_BRANCH_ON_LOGIN.md` — `#[BranchOnLogin]`, a setup callback for public endpoints that
  behave differently when signed in. Deferred.
- `AUTH_GATE_CONSISTENCY.md` — the ruling on `#[RequireLogin]` vs `Session::isLoggedIn()`,
  and what is left open from that audit.

## Rules for a new attribute

- Namespace `Zero\Core\Attribute`, file in `core/attribute/`. `Application::gates()` runs only
  attributes in that namespace.
- `handler()` takes no arguments, returns `true` to continue and throws
  `Zero\Core\Exception\HTTPError` to halt.
- It must be given a place in `ORDER` in `core/tests/module/AttributeOrderTest.php`;
  `testEveryCoreAttributeIsRanked()` fails otherwise.
- An attribute does not receive the module instance, so it cannot set `$this->…` on the
  module. It can read and write the request (`$_POST`, `$_GET`, `$_SESSION`, headers).
- Document it in `core/docs/attributes/` and add it to `core/docs/ATTRIBUTES.md`.

## Planned attributes

In rough order of value.

### RequireOwnership

Verify the logged-in user owns a resource before the method runs.

```php
#[RequireOwnership(resource: 'receipt', param: 'receiptId', field: 'user_id')]
public function editReceipt($args) { … }
```

- Look the resource up, compare its owner column to `Session::userId()`, throw
  `HTTPError(403)` when they differ.
- Compare as integers and refuse when `Session::userId()` is null. An anonymous paste stores
  `user_id` NULL, and a loose `!=` against a null id passes; see `Paste::ownsPaste()`.
- Known call sites (counted 2026-08-16): `modules/Paste` `edit`/`update`/`delete` and six
  endpoints in `modules/TheButton` each repeat resolve → compare owner → reject by hand.
- Open design point: how the attribute finds the row. The id arrives as a URL argument in
  those modules, not as a request parameter, and attributes are not passed the endpoint's
  arguments.

### RequireJSON

Require a JSON request body.

`Request::convertJSONtoPOST()` already decodes a body sent with
`Content-Type: application/json` into `$_POST`, and answers 400 on malformed JSON. So
`#[RequiredParams]` and `#[Sanitize]` already see JSON keys, and an endpoint can read `$_POST`
instead of decoding again. What is missing is only the refusal: an endpoint that wants JSON
and gets a form post, or JSON without the content type, is not told so.

- The attribute checks the content type and throws `HTTPError(400)` (or 415) when it is not
  `application/json`. It does not need to decode anything.
- Separate cleanup that needs no attribute: about 24 endpoints still call
  `json_decode(file_get_contents('php://input'), true)` by hand (`core/modules/Push` ×3, and
  in the site's modules Receipts ×8, Comfy ×5, Sqrlcam ×3 and others). Where the client sends
  the JSON content type these can read `$_POST`.

### RequireAjax

Refuse a request that did not come from script, so a destructive endpoint cannot be reached
by typing its URL.

- `Request::$madeWithAJAX` is already computed (`X-Requested-With: XMLHttpRequest`).
  `fetch()` does not send that header unless the caller adds it, so the check also needs
  `Request::$acceptsJSON` as a fallback, or it will refuse the site's own `fetch()` calls.
- Throw `HTTPError(403)`.
- This is a guard against accidents, not a security boundary: both headers are set by the
  client.

### CacheControl

Set `Cache-Control` declaratively.

```php
#[CacheControl('public, max-age=3600')]
```

- Setting the header is simple. `ETag`/`Last-Modified` and 304 handling are the hard part
  (content hashing, modification times, interaction with the CDN) and should be left out of a
  first version.
- `Cache-Control` is set by hand in 13 places across `core/` and the site's modules today.

### Deprecated

Mark an endpoint for removal: send `Deprecation: true`, a `Sunset` date and optionally a
`Link: <…>; rel="alternate"` header, and log each use.

```php
#[Deprecated(message: 'Use /api/v2/users instead', sunset: '2027-01-01')]
```

### CORS

Send `Access-Control-Allow-Origin` / `-Methods` / `-Headers` and answer preflight `OPTIONS`.

```php
#[CORS(origins: ['https://example.com'], methods: ['GET', 'POST'])]
```

- One endpoint sets these by hand today (the site's `modules/Thingiverse`).
- Watch the order: `#[AllowedMethods('POST')]` refuses the preflight `OPTIONS` request unless
  CORS runs first and ends the request there.

### ValidateCSRF

**Not to be built.** Don't waste time thinking about CSRF, it's handled by SameSite and competent programming (see `modules/Issue/CSRF.md`).

The framework has no CSRF token and gets none. The `state` check in `core/modules/Auth` is
OAuth's own and is a different thing.

Not needed as attributes: HTTPS enforcement and rate limiting are handled earlier in the
stack.

## Open work on existing attributes

### RequireAuthLevel — retire the remaining bare gates

`RequireAuthLevel` is shelved: existing call sites stay, no new ones. Reasoning and the
un-shelving condition are in `core/docs/attributes/RequireAuthLevel.md`.

- **Mint a named grant for the two endpoints that hold a real access decision:**
  `User::permissions()` and `User::permission()` in `core/modules/User/User.php`. They carry
  `#[RequireLogin]` + `#[RequireAuthLevel(1)]`; they want `#[RequirePermission('allow.…')]`.
  Both also open with a hand-written `if (!isset($_SESSION['user_id']))` check that the
  attribute already covers; delete it in the same change.
- The other uses are example or test modules: `core/modules/Test` (class and one method),
  `core/modules/Sitemap` (class), `HelloWorld::secureHello()`, and the site's
  `modules/Sandbox` (class). Once all of these move, the attribute has no callers.
- `RequireAuthLevel::handler()` does not delegate to `RequireLogin`, so it does not require a
  verified email. No module pairs it with `#[AllowWithToken]`, so this is latent. If the
  attribute is ever un-shelved, add the delegation first.
- Dead imports, safe to drop: `use …RequireAuthLevel` in the site's
  `modules/Sqrlcam/Sqrlcam.php`, `modules/ApiConfig/Admin/ApiConfigAdmin.php` and
  `modules/Plugins/Admin/PluginsAdmin.php` (its `//#[RequireAuthLevel(9)]` is commented out),
  and `AllowWithToken` in the `use` list of `core/modules/User/User.php`.

### Verified-email invariant — enforce it where it is written

**Priority:** Medium.

Two invariants hold by coincidence of data, not by construction.

**1. "No unverified user has permissions."** True today: every `permission` row held by a
user belongs to a verified account. Nothing enforces it. `grant()` in
`core/access/HoldsCapabilities.php` has no `verified` check, and neither caller adds one
(`core/modules/Admin/Admin.php`, `core/modules/AccessRequest/AccessRequest.php`).
**Fix:** check in `grant()` when the holder is a user — one place, both callers covered.
A `Group` uses the same trait and has no `verified`; the check must not apply to it.

**2. "`verified` never goes 1 → 0."** True in PHP: the two writes are in
`Auth::findOrCreateUser()` and `Auth::token()`, both `= 1`, both guarded on `!== 1`, and
`Model::save()` writes only dirty columns. Admin has no toggle. Outside PHP:

- **`user_view` is updatable** (`information_schema.VIEWS.IS_UPDATABLE = YES`). It is a
  single-table projection of `users`, so `UPDATE user_view SET verified = 0` writes through.
  Nothing does this.
- **`core/sql/migrations/user_view_eav_rollback.sql`** reverts user rows to the 2026-08-08
  snapshot. Documented in that file.
- Manual SQL, or a restore from an older backup.

**Do not split a `RequireVerified` out of `RequireLogin`.** `RequirePermission` delegates to
`RequireLogin` for its whole login check; two predicates to compose is the drift
`AUTH_GATE_CONSISTENCY.md` closed. Revisit only if an endpoint needs "logged in, unverified
is fine". None does.

### RequireLogin — the `verified` TODO

`RequireLogin::handler()` carries
`// TODO: make this configurable on whether verified should be a gate or not`.
Nothing needs it. Resolve by deciding and removing the comment, or leave until an endpoint
asks for it. See the item above before making it configurable.

### Stale comments: Google adapter normalization

**Priority:** Trivial. **Where:** `core/modules/Auth/Auth.php`, the docblock of
`enforceVerifiedEmail()` and the comment in `findOrCreateUser()`.

Both say `GoogleAdapter` passes Google's `verified` value through with no normalization.
`GoogleAdapter::format()` now returns
`filter_var($user['verified_email'] ?? $user['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN)`.
No adapter passes a raw value. The `filter_var` in `Auth` is right as a guard at the
boundary; the comments give the wrong reason for it.

How each adapter derives `verified`:

| Adapter | Source | Normalization |
|---|---|---|
| GitHub | `/user/emails`, the entry with `primary && verified` | `=== true` |
| GitLab | `confirmed_at` from `/api/v4/user` | `!empty()` |
| Discord | `verified` | `=== true` |
| Google | `verified_email ?? email_verified` | `filter_var(…, FILTER_VALIDATE_BOOLEAN)` |

### Not doing: AllowWithToken refusing an unverified token owner

`AllowWithToken` calls `Session::establish()` for the token's owner without checking
`verified`. The owner declined to change this (2026-08-16, finding 8 in
`IMPORTANT_AUTH_POLISH.md`, an untracked file in the site root, outside this repository):
every use of `#[AllowWithToken]` is paired with `#[RequireLogin]` or `#[RequirePermission]`,
and that gate refuses the unverified session. Listed here so it is not re-raised. The session
that token auth creates is the subject of `core/docs/TOKEN_ISSUE.md`.
