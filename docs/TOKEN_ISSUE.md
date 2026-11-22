# API Token Auth: Session Cookie Leakage

**Status: PROPOSAL — not implemented.** The problem is in the code as of 2026-11-21 (read
from the code, not re-tested against the live site). The fix below is a design, not behaviour
to rely on.

Two related write-ups exist only as untracked files in the root of the unisolu.com site
checkout, outside this repository: `IMPORTANT_AUTH_POLISH.md` (finding 7) and
`SESSION_LIFECYCLE_HANDOFF.md`. This file is the description of the token problem inside the
repository. `core/SESSION_IMPROVEMENTS.md` (remember-me) touches the same code and should be
planned together with it.

## The problem

A request authenticated with `Authorization: Bearer zk_…` leaves the client holding a PHP
session cookie that is a full login for the token's owner. The cookie works without the
token, on every endpoint, and keeps working after the token is revoked or expires.

How it happens:

1. `Application::__construct()` calls `session_start()` for every request. A client with no
   session cookie is sent one.
2. `AllowWithToken::handler()` validates the token and calls `Session::establish($user)`,
   which writes the same login session an interactive sign-in gets (`user_id`, `name`,
   `email`, `verified`, `pic`, `created_at`). This is deliberate: `RequireLogin` and
   `RequirePermission` then work without knowing how the caller authenticated.
3. PHP persists `$_SESSION` under the session id, so any later request presenting that
   cookie is logged in.

`establish()` adds a cost of its own on every token request:

- `session_regenerate_id(true)`: the session is destroyed and recreated, and a new
  `Set-Cookie` is sent, per API call.

## Why it matters

Tokens are stored as SHA-256 hashes, can expire, can be revoked one at a time
(`/user/delete-token/{id}`), and record `last_used_at`. The session cookie issued beside a
token has none of that. Revoking the token does not end the session; it lives until PHP's
session GC removes it.

Permissions are read from the database on each request, so revoking a *permission* still
takes effect at once. Revoking the *token* does not.

Who is affected: any client that keeps cookies (a browser, a headless browser, `curl` with a
cookie jar, an HTTP library with a default cookie store). A client that discards response
cookies is not affected, but still costs a session file per request.

Scope: every endpoint carrying `#[AllowWithToken]`. In core that is none; the site's modules
use it in Plog, Sqrlcam, Lists, Calendar, Kanban, Notebook, Doorbell and Comfy.

## Proposed fix: token auth has no persistent session

A token request fills `$_SESSION` for the length of the request only. No cookie is sent and
nothing is stored.

1. **Detect the token before `session_start()`** in `Application::__construct()`. Read the
   same two headers `AllowWithToken::handler()` reads:
   ```php
   $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
   $isTokenRequest = preg_match('/^Bearer\s+zk_/i', $auth) === 1;
   ```
2. **Send no session cookie for a token request.**
   ```php
   if ($isTokenRequest) {
       ini_set('session.use_cookies', '0');
       ini_set('session.use_only_cookies', '0');
       ini_set('session.cache_limiter', '');
   }
   session_start();
   ```
   The session is active, so `$_SESSION` works and the gates pass, but PHP neither reads nor
   sends a session cookie.
3. **Store nothing.** At the end of a token request, discard the session rather than write
   it:
   ```php
   if ($isTokenRequest) {
       $_SESSION = [];
       session_abort();
   }
   ```


What stays the same: the gates, and interactive login (no `Authorization` header, none of
this runs). What changes on purpose: every token request must carry the token, and
revocation is immediate because every request re-validates against `api_tokens`.

### Watch out for

- **`RequireLogin::handler()` and `RequireAuthLevel::handler()` refuse when
  `session_status() == PHP_SESSION_NONE`.** `SESSION_LIFECYCLE_HANDOFF.md` proposes the
  simpler variant of not calling `session_start()` at all for a Bearer request. With the
  gates as written, that variant denies every token request. It needs those two checks
  changed first. The variant above keeps a session active and avoids this.
- A request with both a Bearer token and a session cookie has the cookie ignored. That is
  correct, but it changes behaviour for someone testing an API from a logged-in browser.
- Check whether `session_start()` + `session_abort()` leaves an empty session file behind
  with the files handler. If it does, use `session_destroy()` in step 3.
- `establish()`'s `session_regenerate_id(true)` still runs under this variant. It sends no
  cookie, but it is wasted work; skip it for token auth.
- `AllowWithToken` sets `$_SESSION['_api_token_auth'] = true`. It stays per-request.
- `core/tests/SessionEstablishTest.php` pins what `establish()` writes.

### Alternative considered

Have `AllowWithToken` destroy the session at the end of the request. That stops the session
persisting but still sends a `Set-Cookie` for a session id that is already dead.

## Verification

1. A request with a valid Bearer token succeeds and the response has no `Set-Cookie` for the
   session.
2. A second request carrying only cookies from the first (no `Authorization` header) is
   refused as unauthenticated.
3. Interactive login still issues a session cookie and session-only endpoints still work.
4. Revoking a token blocks the next request from a client that had just authenticated.
5. The number of files in the session save path does not grow with token requests.
