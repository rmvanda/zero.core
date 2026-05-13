# Session Improvements: Silent Rehydration via Remember-Me Token

## Goal

When a returning user navigates to a protected URL (e.g. `/notebook`) and their PHP session has expired, the request should rehydrate the session **server-side** before any HTML is rendered — no redirect, no Google JS round-trip, no visible bounce. The user simply sees the page they asked for.

This replaces the current behavior, where `RequireLogin` redirects to `/user/login` whenever `$_SESSION['user_id']` is missing.

## Non-goals

- Removing Google as the identity provider. Google remains the only login UI; this just lets us *resume* a Google-authenticated session without re-running Google's flow on every browser restart.
- Changing the session itself (lifetime, regeneration, cookie name). All of that stays.
- Replacing the current session cookie with the remember-me cookie. They serve different purposes and coexist.

## Design summary

Standard "selector + validator" remember-me pattern:

- On successful Google login, server issues a long-lived cookie containing `selector:validator`, both random.
- Server stores `(selector, user_id, hash(validator), expires_at, ...)` in a new `remember_tokens` table.
- On a request with no session but with a valid remember-me cookie, `RequireLogin` looks up by `selector`, constant-time compares `hash(submitted_validator)` against the stored hash, rebuilds `$_SESSION` inline, **rotates the validator**, and lets the request proceed.
- On logout, the cookie is cleared and that token row is deleted.
- If a `selector` is presented with a wrong validator, that's a theft signal: invalidate all of that user's tokens.

## Cookie

- Name: `unisolu_remember`
- Value: `{selector}:{validator}`, where `selector` is 16 random bytes and `validator` is 32 random bytes, both base64url-encoded
- Attributes: `Path=/; HttpOnly; Secure; SameSite=Lax; Expires=<configurable, default 90 days>`
- Why selector+validator instead of one opaque token: the selector is the DB lookup key (indexable, no need to hash), and the validator is the secret (only its hash is stored, so a DB leak doesn't grant impersonation). Splitting them lets us do constant-time hash comparison after a single indexed lookup.
- Why not just sign a `user_id` with HMAC: signed cookies can't be revoked individually, can't be rotated on use, and can't detect theft. A DB-backed token can do all three.

## Database schema

New table:

```sql
CREATE TABLE remember_tokens (
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

Notes:
- One row per device. A user can have many active tokens (laptop, phone, tablet), each with its own selector. Logout deletes only the current device's row.
- `validator_hash` uses sha256 — fast is fine here because the validator is 32 random bytes (256 bits of entropy). Bcrypt-style slow hashing is for low-entropy passwords; it's the wrong tool for high-entropy random tokens.
- Periodic cleanup: a cron or on-write sweep that deletes rows where `expires_at < NOW()`. Cheap and not load-bearing for security (expired rows fail the expiry check anyway).

## Code changes

### 1. New helper class: `core/RememberToken.php`

A small static class encapsulating the token lifecycle. Public surface:

```php
namespace Zero\Core;

class RememberToken {
    // Issue a fresh token for a user; sets the cookie. Called from Auth::complete().
    public static function issue(int $userId): void;

    // Read the cookie, validate it, rebuild $_SESSION on success, rotate the validator.
    // Returns true if session was rehydrated. Called from RequireLogin::handler() and
    // anywhere else (e.g., Push) that needs "resolve current user from cookie".
    public static function tryRehydrate(): bool;

    // Delete the current device's token row and clear the cookie. Called from Auth::logout().
    public static function revokeCurrent(): void;

    // Nuke all tokens for a user (theft response, "log out everywhere" feature).
    public static function revokeAllForUser(int $userId): void;
}
```

Internal logic for `tryRehydrate()`:

1. Read cookie. If absent or malformed → return false.
2. Split into `selector`, `validator`. Validate format strictly.
3. SELECT row by selector. If no row → return false.
4. If `expires_at < NOW()` → delete row, clear cookie, return false.
5. `hash_equals(stored_validator_hash, sha256(validator))` — constant-time.
   - If mismatch → **theft signal**: call `revokeAllForUser($row->user_id)`, clear cookie, return false. Optionally log/alert.
6. Load the user record (`User::getInstance()->withId(...)`). If user doesn't exist → delete row, clear cookie, return false.
7. Rebuild `$_SESSION` exactly the way `Auth::complete()` does: `user_id`, `name`, `email`, `verified`, `pic`, `user_settings`, `auth_level`, `created_at`. Extract this into a shared helper (see §3) to avoid divergence.
8. Rotate: generate new validator, update `validator_hash` and `last_used_at` in DB, set new cookie with extended expiry.
9. Return true.

### 2. `core/attribute/RequireLogin.php`

Modify `handler()`:

```php
public function handler() {
    if (session_status() == PHP_SESSION_NONE || !isset($_SESSION['user_id'])) {
        // Try silent rehydration via remember-me cookie before redirecting.
        if (\Zero\Core\RememberToken::tryRehydrate()) {
            return $this->approved = true;
        }
        Console::warn("RequireLogin attribute blocked request: user not logged in");
        $this->redirectToLogin();
    }
    return $this->approved = true;
}
```

`RequireAuthLevel` and `RequirePermission` should get the same treatment (or, cleaner: have them all delegate the "is there a logged-in user" check to one place).

### 3. `modules/Auth/Auth.php`

Two changes:

**a. Extract the "load user into session" block (currently lines ~96–123) into a helper** so both `complete()` and `RememberToken::tryRehydrate()` use the same code path. Candidate location: a new private method on `Auth`, or a new method on the `User` entity (`User::populateSession($user)`), or a small `core/SessionState.php` helper. Whichever fits the codebase best.

**b. In `complete()`, replace the existing `setcookie('unisolu_user_id', ...)` block (lines 125–135) with `RememberToken::issue($user->id);`.** This is the single most important change — it removes the forgable plaintext-user-id cookie.

**c. In `logout()`, call `RememberToken::revokeCurrent()` before destroying the session.**

### 4. `modules/Push/Push.php`

Currently uses `$_COOKIE['unisolu_user_id']` as a fallback identifier in four places. Two options, in order of preference:

- **Preferred:** replace the `?? $_COOKIE['unisolu_user_id']` fallback with a call to `RememberToken::tryRehydrate()` if `$_SESSION['user_id']` is absent. This makes Push consistent with the rest of the system: identity always comes from a verified source, never from a forgable cookie.
- **Fallback:** if there's a real reason Push needs to work in a context where `tryRehydrate()` can't run (e.g., service-worker-initiated request with no cookies), accept `user_id` only via a signed payload, never raw.

The plain `unisolu_user_id` cookie should be deleted entirely once Push is converted.

### 5. Config

Add to `app/config/constants.ini`:

```ini
; Remember-me cookie lifetime in seconds (default: 90 days)
REMEMBER_TOKEN_LIFETIME=7776000
```

Read in `RememberToken::issue()` and rotation. Configurable so we can tune without code changes.

## Migration / rollout

1. Ship the schema migration (new `remember_tokens` table). Idempotent CREATE.
2. Ship the code. Existing users won't have a remember-me cookie yet; they continue to behave as today (session works while alive, redirect to Google when it dies). The first time each user logs in after the deploy, they get a remember-me cookie and the new behavior kicks in.
3. Once Push is converted (§4), delete leftover `unisolu_user_id` cookies on next visit (`setcookie('unisolu_user_id', '', time() - 3600, '/')` somewhere in the bootstrap, for one or two weeks, then remove).

No data migration needed. No flag day. The system is strictly additive until step 3's cleanup.

## Security considerations

- **Cookie attributes are mandatory:** `HttpOnly` + `Secure` + `SameSite=Lax`. Lax (not Strict) so the cookie is sent on top-level navigations from external links — required for the silent-rehydration UX to work when a user clicks a link to your site from elsewhere.
- **Constant-time compare on the validator** (`hash_equals`). Don't use `===` or `strcmp`.
- **Random source must be `random_bytes()`** — never `mt_rand`/`uniqid`.
- **Rotate on every successful use.** This is what makes theft detectable: the legitimate user and the thief can't both hold a valid validator at the same time.
- **Theft response should be loud.** When mismatch is detected, revoke all tokens for that user and consider sending them an email ("a session on your account was invalidated for security; if this wasn't you, change your Google password"). Log it.
- **Don't log the validator anywhere** — not in error logs, not in DEVMODE dumps. The hash is fine; the raw value is a credential.
- **Rate limit lookup attempts** by IP (or globally) to make brute-forcing the selector space impractical. A 16-byte selector has 128 bits of entropy, so brute force isn't realistic, but a misconfigured lookup that doesn't error on garbage selectors could be used to probe DB performance. Worth a `try/catch` and quiet failure.
- **The remember-me cookie cannot be created by JavaScript** (HttpOnly). Document this so no one tries to "fix" it later.

## Edge cases & decisions

| Case | Behavior |
|------|----------|
| User changes their Google email | Out of scope — current login already handles this by matching on `email`. Existing tokens remain valid for the user_id they were issued for. |
| User deleted from DB | `tryRehydrate()` deletes the orphan token and falls through to the normal redirect. |
| Cookie tampered (bad format) | Return false silently, do not log noisily. Garbage cookies happen. |
| Cookie tampered (valid format, unknown selector) | Return false silently. Same reason. |
| Cookie tampered (known selector, wrong validator) | Theft signal. Revoke all tokens for that user_id. Log. |
| User revokes app access in Google | The remember-me cookie still works until it expires or is rotated. **This is a deliberate tradeoff** — by design, our session outlives the Google session. If you want Google revocation to invalidate our session immediately, that requires Google's revocation webhooks (out of scope). |
| Multi-device | Each device gets its own selector/row. Logout on one device doesn't log out the others. |
| "Log out everywhere" feature | `RememberToken::revokeAllForUser($userId)`. Easy to expose in user settings later. |

## Open questions for review

1. **Cookie lifetime.** Default 90 days. Some sites go a year. What's the right number for unisolu? Tradeoff is convenience vs. blast radius if a device is lost.
2. **Where does `populateSession()` live?** `Auth.php` private method, `User` entity method, or a new `core/SessionState.php`? The third is probably cleanest because it's not really an Auth concern *or* a domain-entity concern — it's session shape.
3. **Should `RequireAuthLevel` and `RequirePermission` also call `tryRehydrate()`?** Probably yes (otherwise rehydration only works on `RequireLogin`-protected endpoints). Might be cleanest to have all three attributes share a base class or trait that does the check.
4. **Should we add a "remember me" checkbox** on the Google sign-in page, or always remember? Always-remember is simpler and matches the user's stated goal ("a cookie that never expires"). A checkbox is more polite to shared-device users. Recommend always-remember for now and add a checkbox later if it becomes an issue.
5. **Handling of the existing `unisolu_user_id` cookie.** Confirm: are there *any* clients (mobile app, integrations) that read it? If yes, we need a deprecation plan instead of a delete.

## Test plan

- New session, no cookies → behaves as today (redirect to login).
- Login → cookie set, session works.
- Delete session cookie only, refresh protected page → session rehydrates silently, no redirect, page renders, validator rotated (verify in DB).
- Delete session cookie, tamper with validator portion, refresh → theft response triggers, all user's tokens deleted, cookie cleared, redirect to login.
- Delete session cookie, wait past `expires_at`, refresh → falls through to redirect, expired row cleaned up.
- Logout → cookie cleared, row deleted, other devices' tokens unaffected.
- Two browsers logged in as same user → both have independent tokens, logging out one doesn't affect the other.
- Push subscribe with no session but valid remember-me cookie → rehydrates and succeeds.
- Push subscribe with no session and no cookie → 401 as today.

## Out of scope (intentionally)

- Replacing PHP sessions with JWTs.
- Adding non-Google identity providers.
- Server-side push of "you've been logged out" notifications.
- Google revocation hooks.
- A user-facing "active sessions" management page (easy to add later given the schema).
