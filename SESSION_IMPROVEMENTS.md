# Session Improvements: Silent Rehydration via Remember-Me Token

**Status: PROPOSAL — not implemented.** There is no `RememberToken` class and no
`remember_tokens` table. `RequireLogin` redirects to `/user/login?r=y` when the session is
gone. Read this as a design, not as behaviour. Checked against the code 2026-11-21.

**Related:** `core/docs/TOKEN_ISSUE.md` changes how sessions start for API-token requests and
touches the same code (`Application::__construct()`, `Session::establish()`); plan the two
together. `SESSION_LIFECYCLE_HANDOFF.md`, an untracked file in the root of the unisolu.com
site checkout (outside this repository), covers session start and lifetime more broadly.

## Goal

A returning user whose PHP session has expired opens a protected URL (e.g. `/notebook`). The
server rebuilds the session before any HTML is rendered: no redirect, no provider round-trip.

## Non-goals

- Changing how people sign in. OAuth (Google, GitHub, GitLab, Discord) and the emailed
  sign-in link stay as they are; this only resumes a session that one of them created.
- Replacing the session cookie. The two cookies coexist.
- Replacing PHP sessions with JWTs, provider revocation hooks, a user-facing "active
  sessions" page (easy to add later given the schema).

## What exists already

- **`Session::establish(User $user)`** is the single writer of the login session and
  `Session::end()` the single teardown. Rehydration must go through `establish()`, not build
  `$_SESSION` itself. The session contract is in `core/modules/Auth/CLAUDE.md`;
  `core/tests/SessionEstablishTest.php` pins it.
- **`Zero\Module\Auth\Model\AuthToken`** (`core/modules/Auth/model/AuthToken.php`, table
  `auth_token`) already implements selector + validator tokens for one-time sign-in links:
  `random_bytes`, base64url, validator stored hashed, `expires_at` computed by the database.
  Copy its approach. It is one-time by design, so a remember token needs its own table and
  rotation.
- **The hint cookie.** `establish()` sets a non-expiring cookie named by
  `Session::hintCookieName()` (`SESSION_HINT_COOKIE` in `app/config/constants.ini`, default
  `zero_user_id`) holding the plaintext user id. Nothing in core or the site's modules reads
  it; `core/modules/Push`, the last reader, now takes the id from the session only. It is not
  authentication and must not become it.
- **Persistent login by configuration.** `SESSION_LIFECYCLE_HANDOFF.md` records
  `session.cookie_lifetime = 604800` and `session.gc_maxlifetime = 604800` on the live host
  (measured 2026-08-16): the session cookie already survives browser restarts for seven days.
  This proposal extends that; it does not introduce it.

## Design

Standard selector + validator remember-me:

- On interactive sign-in the server sets a long-lived cookie `selector:validator`, both
  random, and stores `(selector, user_id, hash(validator), expires_at, …)`.
- On a request with no login session but a remember cookie: look up by `selector`,
  constant-time compare `hash(validator)`, call `Session::establish()`, **rotate the
  validator**, continue the request.
- On logout: clear the cookie, delete that row.
- A known selector with a wrong validator is a theft signal: delete all of that user's
  tokens.

### Cookie

- Value `{selector}:{validator}`: 16 and 32 random bytes, base64url.
- `Path=/; HttpOnly; Secure; SameSite=Lax; Expires=<configurable, default 90 days>`. Lax, not
  Strict, so the cookie is sent on a top-level navigation from another site.
- Name: the original draft used `unisolu_remember`. Core serves more than one site, so the
  name should come from configuration, as `SESSION_HINT_COOKIE` does.
- Selector + validator rather than one opaque token: the selector is the indexed lookup key,
  the validator is the secret and only its hash is stored.
- Not an HMAC-signed user id: that cannot be revoked per device, rotated on use, or detect
  theft.

### Table

```sql
CREATE TABLE IF NOT EXISTS remember_tokens (
    selector        CHAR(22)        NOT NULL PRIMARY KEY,   -- base64url of 16 bytes
    user_id         INT UNSIGNED    NOT NULL,
    validator_hash  CHAR(64)        NOT NULL,               -- sha256 hex of validator
    expires_at      DATETIME        NOT NULL,
    created_at      DATETIME        NOT NULL,
    last_used_at    DATETIME        NULL,
    user_agent      VARCHAR(255)    NULL,                   -- diagnostic hint, not trusted
    ip_created      VARCHAR(45)     NULL,                   -- diagnostic hint, not trusted
    INDEX idx_user (user_id),
    INDEX idx_expires (expires_at)
);
```

- Goes in `core/sql/core.sql` (see `core/sql/README.md` for its rules) with a file in
  `core/sql/migrations/`.
- One row per device. Logout deletes only the current device's row.
- sha256 is right for a 256-bit random validator; slow hashes are for passwords.
- Compute and compare `expires_at` in the database (`NOW()`), not in PHP. PHP runs UTC and
  MySQL local time on the live host; `AuthToken::issue()` documents the bug this caused.
- Expired rows fail the expiry check anyway; a periodic delete is housekeeping only.

## Code changes

### 1. `RememberToken`

```php
class RememberToken {
    public static function issue(int $userId): void;          // new row, set cookie
    public static function tryRehydrate(): bool;              // validate, establish(), rotate
    public static function revokeCurrent(): void;             // this device: delete row, clear cookie
    public static function revokeAllForUser(int $userId): void;
}
```

`tryRehydrate()`:

1. Read the cookie. Absent or malformed → false.
2. Split and validate the format strictly.
3. Select the row by selector. None → false.
4. Expired → delete row, clear cookie, false.
5. `hash_equals(stored_hash, sha256(validator))`. Mismatch → `revokeAllForUser()`, clear
   cookie, log, false.
6. `Zero\Core\Access\User::find($row['user_id'])`. No user → delete row, clear cookie, false.
7. `Session::establish($user)`. Then require `Session::isLoggedIn()`: `establish()` copies
   `verified` from the row, and an unverified account is not logged in.
8. Rotate: new validator, update `validator_hash` and `last_used_at`, set the cookie again.
9. Return true.

### 2. Where rehydration runs

`RequireLogin::handler()`, before it gives up:

```php
if (session_status() == PHP_SESSION_NONE || !Session::isLoggedIn()) {
    if (RememberToken::tryRehydrate()) {
        return $this->approved = true;
    }
    Console::warn("RequireLogin attribute blocked request: user not logged in");
    $this->redirectToLogin();
}
```

`RequirePermission::handler()` delegates to `RequireLogin`, so it is covered.
`RequireAuthLevel` does not delegate and is shelved (`core/docs/attributes/RequireAuthLevel.md`);
leave it. `core/modules/Push` gates its acting endpoints with `#[RequireLogin]`, so it needs
no change of its own.

### 3. Where tokens are issued and revoked

- **Issue** after `Session::establish()` in the two interactive sign-ins: `Auth::complete()`
  (OAuth) and `Auth::token()` (emailed link). **Not** inside `establish()`:
  `AllowWithToken` is its third caller and an API-token request must not be handed a
  remember cookie.
- **Revoke** in `Auth::logout()`, before `Session::end()`.

### 4. The hint cookie

Remove the `setcookie(self::hintCookieName(), …)` call from `Session::establish()` and
`hintCookieName()` with it, once it is confirmed that no client outside the PHP code (a
mobile app, an integration) reads the cookie. It is `HttpOnly`, so page script cannot.
`core/tests/SessionHintCookieTest.php` covers the current behaviour and goes with it. Expire
leftover cookies for a couple of weeks after.

### 5. Config

`app/config/constants.ini` (and the templates in `core/skeleton/`):

```ini
; Remember-me cookie lifetime in seconds (default: 90 days)
REMEMBER_TOKEN_LIFETIME=7776000
```

## Rollout

1. Ship the table. `CREATE TABLE IF NOT EXISTS`.
2. Ship the code. A user without a remember cookie behaves as today and gets one at their
   next sign-in.
3. Remove the hint cookie (§4).

No data migration.

## Security

- `HttpOnly` + `Secure` + `SameSite=Lax` are mandatory. The cookie cannot be created by
  JavaScript; do not "fix" that.
- `hash_equals` for the validator. `random_bytes()` for both halves.
- Rotate on every successful use. That is what makes theft detectable.
- On a mismatch, revoke all of the user's tokens and log it. Consider emailing the user.
- Never log the validator, including in dev-mode output. The hash is fine.
- Fail quietly on garbage cookies (bad format, unknown selector). They happen.
- Rehydration changes state on a GET. `establish()` already rotates the session id, which is
  what is wanted here.

## Edge cases

| Case | Behaviour |
|------|----------|
| User deleted | `tryRehydrate()` deletes the orphan row and falls through to the redirect. Account deletion should also call `revokeAllForUser()`. |
| Bad format, or unknown selector | Return false silently. |
| Known selector, wrong validator | Theft signal. Revoke all tokens for that user. Log. |
| Access revoked at the provider | The remember cookie still works until it expires. Deliberate: the session outlives the provider's. |
| Two tabs rehydrate at once | The second presents the validator the first just rotated away and trips the theft response. Needs a decision: a short grace window for the previous validator, or accept the logout. |
| Multi-device | One row per device. Logout on one leaves the others. |
| "Log out everywhere" | `RememberToken::revokeAllForUser($userId)`. |
| JSON caller | `redirectToLogin()` throws 401 for JSON callers. Rehydration runs before that, so an XHR with a valid remember cookie succeeds. |

## Open questions

1. **Cookie lifetime.** Default 90 days. Convenience against blast radius if a device is
   lost.
2. **Where does `RememberToken` live?** `Zero\Core` next to `Session`, or the Auth module
   next to `AuthToken`. `RequireLogin` is in core and must not depend on a module that could
   be absent; Auth is a required module, but core calling into it is still the wrong
   direction.
3. **Public pages.** Rehydrating only in `RequireLogin` means a returning user on a public
   page (the home page, anything that branches on `Session::isLoggedIn()`) looks signed out
   until they open a gated one. Rehydrating in `Application::__construct()` after
   `session_start()` fixes that at the cost of a cookie check on every anonymous request.
4. **A "remember me" checkbox, or always remember?** Always is simpler. A checkbox is kinder
   on shared devices.
5. **Interaction with `core/docs/TOKEN_ISSUE.md`.** A request carrying a Bearer token must
   never rehydrate from a remember cookie.

## Test plan

- No cookies → redirect to login, as today.
- Sign in (OAuth, and emailed link) → remember cookie set, row created.
- Delete the session cookie, open a protected page → page renders, no redirect, validator
  rotated in the table.
- Delete the session cookie, tamper with the validator → all of the user's rows deleted,
  cookie cleared, redirect to login.
- Past `expires_at` → redirect, row removed.
- Logout → cookie cleared, row deleted, other devices unaffected.
- Unverified account with a valid remember cookie → not logged in.
- API-token request → no remember cookie issued.
- `/push/subscribe` with no session but a valid remember cookie → succeeds; with neither →
  401 for a JSON caller.
