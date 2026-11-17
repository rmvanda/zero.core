# API Token Auth: Session Cookie Leakage

**Status: PROPOSAL — not implemented.** This describes a live problem and a fix that has
not shipped. Everything below the "Proposed" heading is a design, not behaviour you can
rely on. Tracked as finding 7 in `IMPORTANT_AUTH_POLISH.md` and handed off in
`SESSION_LIFECYCLE_HANDOFF.md`.

## Summary

The `AllowWithToken` attribute (`core/attribute/AllowWithToken.php`) authenticates
API requests via `Authorization: Bearer zk_...` headers. However, a successful token
authentication also causes the server to issue a standard PHP session cookie
(`PHPSESSID`) to the client. Once that cookie exists, it becomes an independent
bearer credential that grants the same access as the original token — without
needing the `Authorization` header on subsequent requests.

This blurs the boundary between **stateless token auth** (what API tokens are
supposed to be) and **stateful session auth** (what OAuth login produces).

## How it happens

The behavior is not a single bug but an emergent property of three design
decisions that each make sense in isolation:

1. **Sessions start on every request.** `core/Application.php` calls
   `session_start()` in its constructor for all requests, regardless of auth
   method. PHP responds by emitting a `Set-Cookie: PHPSESSID=...` header on any
   request where a session cookie was not already present.

2. **`AllowWithToken` populates `$_SESSION` on success.** After validating the
   bearer token, the attribute writes user identity and permissions into
   `$_SESSION` using the same keys that `Auth::complete()` uses for OAuth login.
   The stated rationale (in the attribute's docblock) is so that downstream
   attributes like `RequireLogin`, `RequireAuthLevel`, and `RequirePermission`
   work transparently without needing to know how the user authenticated.

3. **PHP persists session data by session ID.** Once `$_SESSION` has been
   written under a given session ID, any future request presenting that session
   ID cookie will reconstitute the same `$_SESSION` contents server-side —
   including the authenticated user identity.

The net effect: the first token-authenticated request hands the client a
long-lived session cookie. Subsequent requests from that client — even to
endpoints *without* `AllowWithToken`, and without any `Authorization` header —
are treated as fully authenticated requests for the token's owner.

## Why it matters

The token system has properties designed for API use:

- Tokens are stored as SHA256 hashes (cannot be stolen from the DB)
- Tokens support expiration
- Tokens can be individually revoked via `/user/delete-token/{id}`
- Token usage is tracked via `last_used_at`

**The session cookie issued alongside a token has none of these properties.**
It cannot be revoked without invalidating the server-side session, it has no
server-side expiration tied to the token's expiration, and its usage is not
tracked in `api_tokens.last_used_at`. Revoking the underlying token does
**not** invalidate any session cookies that were issued while the token was
valid; those sessions continue to work until PHP's own session GC expires
them.

In effect: **a client that authenticates once with a token can continue acting
as the token's owner indefinitely, even after the token is revoked, for as
long as the session cookie and server-side session data survive.**

## Scenarios where this surfaces

- **Any HTTP client that retains cookies across requests** (browsers, headless
  browser contexts, `curl` with a cookie jar, language HTTP libraries with
  default cookie persistence). These clients will silently "upgrade" from
  token auth to session auth on the first request and stop sending the token.

- **Shared or long-lived client contexts.** If a client context used for token
  auth is later reused for another purpose — or shared across processes — the
  session cookie carries the authenticated identity with it.

- **Token revocation scenarios.** An operator revokes a leaked or expired
  token expecting all access to be cut off. Access continues via the session
  cookie that was issued while the token was valid.

Stateless clients (e.g. `curl` without `-c/-b`, `fetch()` without a cookie
jar, one-shot HTTP calls that discard response cookies) are unaffected
because they never store the cookie in the first place.

## Scope

This is a **design-level** issue, not a bug in a specific endpoint. It applies
uniformly to every endpoint decorated with `#[AllowWithToken]` and every
client that accepts `Set-Cookie` headers. The current deployment happens to
have few such endpoints, but the issue scales with every new use of the
attribute.

---

## SUGGESTED SOLUTION

The cleanest fix preserves `AllowWithToken`'s transparency goal (downstream
attributes keep working unchanged) while preventing the session from outliving
the token.

### Approach: make token auth session-less

When `AllowWithToken` authenticates a request, that request should populate
`$_SESSION` for the duration of the request only — no cookie issued, no
server-side session persisted.

Implementation outline:

1. **Detect token auth before `session_start()`.**
   In `core/Application.php`, before calling `session_start()`, peek at the
   `Authorization` header. If it looks like a Bearer `zk_` token, mark the
   request as token-auth-bound:
   ```php
   $isTokenRequest = !empty($_SERVER['HTTP_AUTHORIZATION'])
       && preg_match('/^Bearer\s+zk_/i', $_SERVER['HTTP_AUTHORIZATION']);
   ```

2. **Suppress the session cookie for token requests.**
   When `$isTokenRequest` is true, disable cookie-based session propagation
   for this request only:
   ```php
   if ($isTokenRequest) {
       ini_set('session.use_cookies', '0');
       ini_set('session.use_only_cookies', '0');
       ini_set('session.cache_limiter', '');
   }
   session_start();
   ```
   With `session.use_cookies = 0`, PHP will not emit `Set-Cookie` for this
   response. `$_SESSION` still works normally for the lifetime of the
   request, so `AllowWithToken` can populate it and downstream attributes
   still read it.

3. **Prevent server-side session persistence for token requests.**
   Add a shutdown step (or explicit call at the end of `Application::run()`)
   that, for token-authed requests, discards the server-side session data
   rather than writing it to disk:
   ```php
   if ($isTokenRequest) {
       $_SESSION = [];
       session_abort(); // discards changes without writing
   }
   ```
   This ensures no persistent session file is created on the server for token
   requests — even if a client somehow sends a crafted `PHPSESSID` cookie,
   there's nothing on the server side to resume.

### Invariants this preserves

- `AllowWithToken`, `RequireLogin`, `RequireAuthLevel`, `RequirePermission`
  all continue to function unchanged (they just read `$_SESSION` during the
  request).
- Normal OAuth session login is untouched (the `Authorization` header is
  absent for those requests, so none of the above code paths fire).
- Token revocation becomes immediately effective: with no session
  persistence, every request re-validates the token against `api_tokens`.

### Invariants this breaks (intentionally)

- Clients can no longer "log in with a token" and then stop sending it. Every
  authenticated request must include the `Authorization` header. This is the
  correct behavior for a token auth system.

### Alternative considered

A narrower fix is to have `AllowWithToken` call `session_regenerate_id(true)`
followed by immediate `session_destroy()` at the end of the request. This
prevents a persisted session but leaves a `Set-Cookie` header on the response
for a session ID that is immediately invalid — wasted bytes and confusing
behavior. The approach above avoids emitting the header in the first place.

### Testing

Verification should confirm:

1. A request with a valid Bearer token returns the expected response and
   does **not** include a `Set-Cookie: PHPSESSID` header.
2. A second request with only the cookie from step 1 (no `Authorization`
   header) is rejected as unauthenticated.
3. An OAuth login flow still issues a session cookie and subsequent requests
   to session-only endpoints still work.
4. Revoking a token immediately blocks further access, even from a client
   that had just authenticated successfully.
