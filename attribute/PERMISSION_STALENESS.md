# Known Limitation: Stale Session-Cached Permissions

## Summary

`RequirePermission` consults `$_SESSION['user_settings']` before falling back
to the database. The session copy is populated at login (`Auth::login`) or
token-auth (`AllowWithToken`) and is **not** refreshed during a session. A
permission revoked in the database takes effect for that user only after
their next login or session expiry — not immediately.

## Why This Is Currently Accepted

- The site's current permission set is low-stakes; a revoke-latency window
  of "until next login" is tolerable.
- The session-first check keeps hot paths fast (avoids a DB round-trip per
  gated endpoint).
- No stakeholder has asked for instant revocation.

## Mitigations Available Today

Admins who need a revocation to take immediate effect can:

1. **Force the user to re-login.** Destroy the target user's server-side
   session (or bump a global session-version counter the app invalidates on).
2. **Wait for session expiry.** Sessions regenerate on
   `SESSION_REGENERATE_INTERVAL` and expire by PHP's configured lifetime.

There is currently no single-call "flush permissions for user X" API.

## When To Revisit

Revisit this decision if any of the following become true:

- A permission starts gating something financial, destructive, or
  irreversible (admin-only actions, billing, data export, user management,
  deletion of shared resources).
- Compliance or audit requirements demand bounded revocation latency.
- A specific incident lands where stale permissions cause real damage.

## Implementation Options (For Future Reference)

When this does need fixing, the three reasonable paths are:

### Option A — TTL on session cache
Track `$_SESSION['user_settings_loaded_at']`. If older than N seconds (e.g.
60), re-hydrate from the DB before the session check. Bounded staleness,
one DB query per user per N seconds.

*Pro:* Works invisibly, no caller changes.
*Con:* Still has a staleness window; small periodic DB cost.

### Option B — Explicit refresh hook + version column
Add a `user_settings_version` column bumped whenever permissions change.
`RequirePermission` reads the version cheaply (one small query) and only
rehydrates `user_settings` on mismatch. Admin code that mutates permissions
bumps the version.

*Pro:* Instant propagation; cheap on the hot path.
*Con:* Requires discipline — every permission-mutating code path must
remember to bump the version, or revocations silently fail to propagate.

### Option C — Always hit the DB
Drop the session cache. Every `RequirePermission` check queries the DB.

*Pro:* Zero staleness, simplest semantics.
*Con:* Extra DB query per gated endpoint per request. Multiplied when
endpoints stack multiple `#[RequirePermission]` declarations.

## Recommendation For When This Is Revisited

Option A (60-second TTL) is the simplest upgrade path: contained change,
no changes to calling code, bounded worst case. Move to Option B only if a
60-second window is unacceptable for a specific permission class.
