# Auth Module

Third-party (OAuth2/OIDC) sign-in, provider connection management, and logout.

**Route base:** `/auth` — `core/modules/Auth/Auth.php`
**Admin:** `/admin/auth` — `core/modules/Auth/Admin/AuthAdmin.php`
**Login UI lives elsewhere:** `core/modules/User/view/login.php` + `app/frontend/frame/_oauth-buttons.php`

## Endpoints

| URL | Method | Purpose |
|-----|--------|---------|
| `/auth/user?via={code}` | GET | Start a login with a provider |
| `/auth/connect?via={code}` | GET | Attach an *additional* provider to the logged-in account (`#[RequireLogin]`) |
| `/auth/complete` | GET/POST | Provider callback — the only place a session identity is created |
| `/auth/register` | GET/POST | Manual registration — creates the account `verified = 0`, emails a verification link |
| `/auth/signin` | GET/POST | Request a one-time sign-in link by email |
| `/auth/token` | GET/POST | GET confirms, POST consumes the emailed token and establishes the session |
| `/auth/disconnect` | POST | Forget a provider's stored tokens (`#[RequireLogin]`, `#[AllowedMethods('POST')]`) |
| `/auth/keycloak-test` | GET | Keycloak OIDC test page — deliberately unlinked from the normal UI |
| `/auth/logout` | GET | Destroy session, redirect to `/` |

`/auth/complete` takes **no `?via`** in the normal flow: the provider's via-code is prefixed onto
the OAuth `state` as `"{via}:{random}"` and recovered in `complete()`. Google one-tap (a `credential`
POST) and the Keycloak test path still arrive with `?via`, so `loadAdapter()` falls back to the query
param.

## Adapter architecture

Adapters are **global-namespace classes**, `require`d by filename — *not* autoloaded:

```php
$ac = $this->smids[$via] . "Adapter";   // e.g. 12 => "GitHub" => "GitHubAdapter"
require __DIR__."/adapters/$ac.php";
$this->auth = new $ac();
```

`Auth::$smids` is the via-code → provider-name map and is the source of truth for that filename.
Two things read it: `loadAdapter()` and the provider-slug lookup in `complete()`.

Three shapes of adapter live in `adapters/`:

- **`OAuth2Adapter`** (abstract base) — the standard path. Owns CSRF `state`, code→token exchange,
  token normalization, and `refresh()`. It does *not* own the verified-email gate — that moved
  to `Auth` (see below). Subclasses supply endpoints, scopes,
  `$via`, `$provider`, and two methods: `fetchIdentity()` and `format()`.
  Implementations: `GitHubAdapter`, `DiscordAdapter`, `GitLabAdapter`.
- **`GoogleAdapter`** — extends the vendored `Google_Client`, not `OAuth2Adapter`. Config comes from
  `adapters/config/google_api.json` (untracked) and `GP_REDIRECT_URI`. It also serves Google Sign-In
  one-tap via `verifyIdToken()`. Guard any use of `GP_REDIRECT_URI` — that constant only exists when
  the Auth module is the live module (see below).
- **`KeycloakAdapter`** — **DEPRECATED (2026-08-08), unmaintained.** Standalone OIDC
  implementation (ID-token validation, nonce, azp). Config from
  `adapters/config/keycloak.json` (untracked, server-local). Reachable only via
  `/auth/keycloak-test`, unlinked from the login UI, absent from `oauth_provider` — so
  nothing routes to it in normal use. Kept rather than deleted in case the OIDC surface
  is wanted again. It is also the one adapter still enforcing the verified-email
  invariant itself; see below.

### Adding a provider

1. Add the display name to `Auth::$smids` at a free index — **that index is the public `via` code
   and is permanent**; it is baked into `oauth_provider.via_code` and into issued `state` values.
2. Create `adapters/{Name}Adapter.php` extending `OAuth2Adapter`; set `$provider` (lowercase slug),
   `$via`, `$authEndpoint`, `$tokenEndpoint`, `$scopes`; implement `fetchIdentity()` + `format()`.
3. Put credentials in `app/config/{slug}.ini` as `{SLUG}_CLIENT_ID` / `{SLUG}_CLIENT_SECRET` —
   `OAuth2Adapter::loadConfig()` derives the constant names from `$provider`, and `redirect_uri`
   from `PRIMARY_DOMAIN`.
4. Insert a row into `oauth_provider` (slug, name, via_code, enabled, sort_order). The login buttons,
   `/user/profile`, and the admin panel are all driven by that table — nothing else needs editing.
5. If the provider's tokens should be refreshable outside the login flow, add its display name to
   `Auth::PERSISTED_PROVIDERS` (keep it spelled exactly as in `$smids`).

### Deprecated / dormant providers

`$smids` reserves indices for providers that have no adapter file: Facebook (0), Twitter (1),
Instagram (2), Foursquare (4), Yelp (5), PayPal (7), LinkedIn (8), Pinterest (9), Tumblr (10).
`loadAdapter()` 404s on every one of them. The names stay in the map so their via codes remain
reserved and a revival keeps its old URLs — **do not renumber around them.**

Leftover Twitter credentials still sit in `config/config.ini` (`X_CONSUMER_*`).
Recovering an adapter: see `DEPRECATING_CHANGES.md`.

Live adapters: Google (3), Keycloak (11), GitHub (12), Discord (13), GitLab (14).

## The verified-email invariant

`Auth::complete()` matches accounts by **email alone** (`User::findBy('email', ...)`).
That is only safe because no identity reaches the matching step without a provider-verified email:

```php
Auth::enforceVerifiedEmail($identity)   // 401 missing email, 403 unverified
```

**The gate lives in `Auth`, not in the adapters.** `Auth::enforceVerifiedEmail()` is called from
`complete()` on the identity every adapter produces — both the full-OAuth and Google one-tap
branches converge on it — and again from `completeConnect()`. It is checked *before*
`findOrCreateUser()`, which is what matches an account by email and what promotes a
pre-registered row to verified.

It is enforced at the consumer rather than the producer because the invariant belongs to Auth:
`complete()` is what matches by email, so Auth is what needs the address proven. An adapter
reporting `verified => false` is behaving correctly — it has no business throwing an HTTP error
about a policy it does not own. It also cannot be forgotten: a new adapter can omit a check of
its own, but not one applied to its output.

Adapters must therefore report `verified` **faithfully**, including `false`. Normalize it if the
provider's shape is loose — `GoogleAdapter` does, because Google returns `verified_email` on the
userinfo path and `email_verified` on the ID token and neither is guaranteed to be a bool.

The one exception is the deprecated `KeycloakAdapter`, which still enforces inline during
ID-token validation alongside its nonce/azp claim checks. Deliberately not migrated, but it does
mean the rule exists in two places — reconcile before reviving that adapter.

## Provider registry (`oauth_provider`)

`Zero\Module\Auth\OAuth\Provider` (`OAuth/Provider.php`; schema `core/sql/migrations/auth_oauth_provider.sql`, created by `core/sql/core.sql`) — a plain table, not an EAV entity.

- `available()` = enabled **and** configured (credentials present). Drives the login buttons.
- `Auth::assertProviderAvailable()` re-checks the same gate server-side, so a disabled provider
  cannot be used via a hand-typed `/auth/user?via=` link. It **fails open** on a registry error;
  missing credentials are the backstop.
- Unregistered providers (Keycloak) are not gated by this at all.
- `/admin/auth` toggles `enabled`; the flag takes effect everywhere immediately.

## Token persistence (`oauth_token`)

`Zero\Module\Auth\OAuth\Token` (`OAuth/Token.php`; schema `core/sql/migrations/auth_oauth_token.sql`, created by `core/sql/core.sql`) — one row per `(user_id, provider)`, `access_token`/`refresh_token`
encrypted at rest with `Zero\Lib\Crypto` (AES-256-GCM, key `APP_ENCRYPTION_KEY` from
`app/config/crypto.ini`). Callers always see plaintext.

- `complete()` stores the bundle after login, wrapped in try/catch — **a persistence failure must
  never break a login**.
- `store()` writes only the keys present, so a refresh that omits `refresh_token` preserves the
  stored one (Google/Discord) while GitLab's rotated token overwrites.
- `Zero\Module\Auth\OAuth\Broker` refreshes on demand for other modules (Calendar, Dashboard) via
  `Auth::adapterForProvider($slug)`.

See `docs/superpowers/specs/2026-07-07-oauth-token-persistence-design.md`.

## Session contract

`Zero\Core\Session::establish()` is the single writer of the login session. All three sign-in
paths call it — `Auth::complete()` (OAuth), the `/auth/token` consumer (magic link), and
`core/attribute/AllowWithToken.php` (API tokens) — so a session is identical regardless of how
it was created, and the session id is rotated at the privilege boundary in every case. It sets
every key below. `AllowWithToken` additionally sets `$_SESSION['_api_token_auth']`.

```
$_SESSION['user_id']        // users.id — RequireLogin gates on this
$_SESSION['email']          // } Zero\Core\Session::isLoggedIn() requires
$_SESSION['verified']       // } email + truthy verified
$_SESSION['name'], ['pic']
$_SESSION['user']           // normalized provider identity — but ['id'] is overwritten
                            // by establish() with OUR users.id, never the provider's
$_SESSION['created_at']
$_SESSION['login_provider'] // set by OAuth2Adapter
```

Settings and permissions are **not** in the session. `$user->can()` and `$user->authLevel()`
read through to the database (`permission` for capabilities, `user_settings` for settings) and memoize per *instance*, so a permission revoked
mid-session denies on the very next request. Ask `Session::can($key)` /
`$user->can($key)` for a grant, `Session::user()?->authLevel()` for the level, and
`$user->settings()` for the whole map.

Also sets a non-expiring hint cookie named by `Session::hintCookieName()` (`SESSION_HINT_COOKIE`, default `zero_user_id`; Secure+HttpOnly) holding the **plaintext** user
id — a "probably returning user" hint only. It is not authentication and must never be trusted as
such.

`$_SESSION['return_url']` is set by `#[RequireLogin]` and consumed once — by `complete()` on the
OAuth path and by `token()` on the magic-link path. Both consumers emit it as a `Location`, so both
re-check it with `Request::isSiteRelative()` and fall back to `/`, even though the attribute already
guards what it stores: `$_SESSION` is a shared bucket and a future writer of the key would not pass
through the attribute. The key is burned on read either way — a value we refuse to use must not
survive to the next login.

## Connect vs. login

`/auth/connect` sets `$_SESSION['oauth_connect']`, and `complete()` branches to `completeConnect()`,
which stores tokens against the **current** `user_id` and never touches session identity. The branch
additionally requires `empty($_POST['credential'])` so a Google one-tap POST can't be misread as a
connect through a stale marker. Most mutating endpoints have no CSRF token — they rely on
`#[RequireLogin]` + POST-only + the SameSite=Lax session cookie, which is sufficient because
they require an existing authenticated session. `/auth/token` is the exception: it runs
**unauthenticated and creates a session**, so `#[RequireLogin]` + SameSite protects nothing
there. It instead carries its own session-bound nonce.

## Config

Constants are defined by `Application::defineConstants()` from, in order: every `app/config/*.ini`,
then `modules/{LiveModule}/config.ini`, then every `modules/{LiveModule}/config/*.ini`.

**Gotcha:** module-scoped constants only exist while that module is the live module. `GP_REDIRECT_URI`
comes from `core/modules/Auth/config/config.ini`, so it is undefined when `GoogleAdapter` is constructed
from Calendar/Dashboard — hence the `defined()` guard in its constructor. Prefer `app/config/` for
anything another module might need.

Untracked, server-local secrets: `adapters/config/keycloak.json`, `adapters/config/google_api.json`.

## Tests

PHPUnit, in `core/modules/Auth/tests/` (picked up by the `Modules` suite in `phpunit.xml`):

```bash
vendor/bin/phpunit --testsuite Modules --filter 'Auth|OAuth|Keycloak'
```

Adapters take an injected `$config` array and `setHttpClient()` as test seams, so nothing hits the
network. Ask the user to run tests rather than running them here.

## Manual registration

Email-only, no password. `/auth/register` creates the account with `verified = 0` and
establishes **no session**; `Zero\Core\Session::isLoggedIn()` requires a truthy `verified`, so the
account is inert until the emailed link is clicked. `/auth/signin` issues a one-time login link
for an already-verified account.

Both surfaces respond identically whether or not the address exists — revealing it would be an
enumeration oracle.

Tokens are selector+validator rows in `auth_token`; only the validator's SHA-256 is stored,
comparison is constant-time, and consumption burns the row in the same conditional `UPDATE`
that validates it. `/auth/token` confirms on GET and consumes on POST so mail scanners that
pre-fetch links cannot burn a token before the human clicks.

`registration-fields.json` ships empty (`[]`). `name` and `email` are hardcoded inputs on the
registration form and are collected and written directly to `users`, not as configurable profile
fields — `full_name` is in `RegistrationFields::RESERVED` so it cannot be declared in the config.
The `RegistrationFields` machinery that reads the config and renders extra fields is intact,
just dormant while the config is empty. `user_profile` rows are written and read through the
`Zero\Core\User` instance API: `->storeProfile($values, $fieldDefs)` and `->profile()`.

**Passwords are still not implemented.** `user_credential` is specced but not built — see
`docs/superpowers/specs/2026-08-07-auth-manual-registration-design.md` §4.3.

**`Auth::register()`'s own input validation has no automated coverage** — nothing in the test
suite calls `register()` directly. The following cases are verified by hand only; re-check them
by hand after touching `register()`:

- empty `name`
- `name` over 191 characters
- array-valued `name` (`name[]=x` in the POST body)

## Reference

- `core/` + `docs/superpowers/specs/2026-08-07-zero-model-user-migration-design.md` — the `user` record lives in the `users` table via `Zero\Core\User`; `user_view` is a compatibility view over it
- `entity/CLAUDE.md`, `/entityframework` skill — the EAV framework, which `user` does not use
- `core/ADMIN_ARCHITECTURE.md` — the `/admin/auth` panel
- `docs/superpowers/specs/2026-06-26-keycloak-oidc-test-integration-design.md`
- `docs/superpowers/specs/2026-07-06-multi-provider-oauth-adapters-design.md`
- `docs/superpowers/specs/2026-07-07-oauth-token-persistence-design.md`
