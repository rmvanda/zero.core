# Attribute System TODO

This document tracks planned attributes for the Zero Framework's attribute system.

One proposal has its own file rather than a section here, because the reasoning
is longer than the entry would be: `TODO_BRANCH_ON_LOGIN.md` — an attribute that
names a setup callback for endpoints that are public but behave differently when
signed in. Deferred, with the trigger condition and the build sketch written down.

## High Priority

### RequireJSON
**Status:** Planned
**Priority:** High
**Estimated Locations:** 6+ endpoints currently using `json_decode(file_get_contents('php://input'))`

**Purpose:** Validate JSON content type and automatically decode request body.

**Distinction from RequireAjax:**
- RequireJSON validates **request body format** (Content-Type + JSON parsing)
- RequireAjax validates **request origin** (came from JavaScript vs browser URL bar)
- Both serve different purposes - JSON validation vs origin validation
- Can be used together or separately depending on endpoint needs

**Usage:**
```php
#[RequireJSON]
#[RequiredParams(['receiptId', 'amount'])]  // Validates decoded JSON
public function save($args) {
    $data = $this->jsonInput;  // Pre-decoded by attribute
    $receiptId = $data['receiptId'];
}

// Combined for maximum security
#[RequireAjax]  // Must come from JavaScript
#[RequireJSON]  // Must have JSON body
public function apiEndpoint($args) {
    // Webhook example: RequireJSON only (accept from anywhere)
    // Delete example: RequireAjax only (no body needed)
}
```

**Implementation Notes:**
- Validate `Content-Type: application/json` header
- Decode `php://input` automatically
- Store decoded data in `$this->jsonInput` or module property
- Handle malformed JSON gracefully (return 400 Bad Request)
- Make decoded data available to `RequiredParams` for validation

**Current Manual Pattern:**
```php
// Found in: Sqrlcam, Receipts (6+ locations)
$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    $this->error('Invalid JSON');
    return;
}
```

**Benefits:**
- Eliminates 3-5 lines of boilerplate per endpoint
- Consistent JSON error handling
- Integrates with existing `RequiredParams` attribute

---

### RequireAjax
**Status:** Planned
**Priority:** High

**Purpose:** Ensure requests originate from JavaScript (AJAX/fetch), not direct browser navigation.

**Distinction from RequireJSON:**
- RequireAjax checks **request origin** (JavaScript vs browser)
- RequireJSON checks **request body format** (valid JSON)
- Different security concerns - origin vs data format

**Usage:**
```php
#[RequireAjax]
public function deleteUser($args) {
    // Only accessible via JavaScript fetch/XMLHttpRequest
    // Prevents accidental deletion via direct URL navigation
}

#[RequireAjax]
#[RequireJSON]
public function sensitiveUpdate($args) {
    // Both origin AND format validation
}
```

**Implementation Notes:**
- Check `$_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest'`
- Fallback: Check `Accept` header contains `application/json`
- Return 403 Forbidden if accessed directly
- Note: Modern fetch() may not send X-Requested-With header

**Benefits:**
- Prevents direct URL access to destructive endpoints
- Security layer for AJAX-only routes
- Complements RequireJSON for full API protection

---

### AuditLog
**Status:** ✅ **IMPLEMENTED**
**Priority:** High
**Location:** `core/attribute/AuditLog.php`
**Documentation:** `docs/attributes/AuditLog.md`

**Purpose:** Automatically log access to sensitive endpoints for compliance and security.

**Usage:**
```php
#[RequirePermission('allow.admin')]
#[AuditLog('admin_action')]
public function updatePermission($args) {
    // Access automatically logged to event table
}

#[AuditLog('user_data_access', includeParams: true)]
public function viewSensitiveData($args) {
    // Logs with sanitized request parameters
}

#[AuditLog('sensitive_view', requireAuth: true)]
public function viewProfile($args) {
    // Only logs if user is authenticated
}
```

**Implementation Details:**
- ✅ Integrates with `\Zero\Entity\Event` class
- ✅ Logs: endpoint, user_id, username, HTTP method, IP, user agent
- ✅ Optional parameter logging with automatic sanitization
- ✅ Redacts sensitive fields (password, token, api_key, etc.)
- ✅ Truncates long values to prevent huge logs
- ✅ Optional requireAuth parameter to skip anonymous access
- ✅ Never blocks execution (errors logged but ignored)
- ✅ Uses existing Event EAV schema (no schema changes needed)

**Benefits:**
- ✅ Security audit trail for compliance
- ✅ Track who accessed what and when
- ✅ Detect suspicious activity patterns
- ✅ Debug user actions
- ✅ GDPR/SOC2 compliance support

---

## Medium Priority

### CacheControl
**Status:** Planned
**Priority:** Medium
**Complexity:** High - Will require careful implementation

**Purpose:** Set HTTP cache headers declaratively.

**Usage:**
```php
#[CacheControl('public, max-age=3600')]
public function getCategories($args) {
    // Browser/CDN caching for 1 hour
}

#[CacheControl('private, no-cache')]
public function getUserProfile($args) {
    // No caching for user-specific data
}
```

**Implementation Notes:**
- Set `Cache-Control` header
- Common presets: `public`, `private`, `no-cache`, `no-store`, `max-age`
- Consider `ETag` and `Last-Modified` support
- Integrates well with static/public endpoints
- **Complexity concerns:**
  - Proper ETag generation requires content hashing
  - Last-Modified requires tracking modification times
  - Cache invalidation strategies needed
  - Interaction with CDN/reverse proxy caching
  - Conditional request handling (304 Not Modified)

---

### RequireOwnership
**Status:** Planned
**Priority:** Low-Medium

**Purpose:** Verify user owns a resource before allowing access/modification.

**Usage:**
```php
#[RequireOwnership(resource: 'receipt', param: 'receiptId', field: 'user_id')]
public function editReceipt($args) {
    // Verifies receipt.user_id matches $_SESSION['user_id']
}

#[RequireOwnership(resource: 'post', param: 'postId')]
public function deletePost($args) {
    // Checks ownership before deletion
}
```

**Implementation Notes:**
- Query database to check resource ownership
- Resource types defined in configuration or entity classes
- Check `{resource}.user_id = $_SESSION['user_id']`
- Return 403 Forbidden if not owner
- Consider caching ownership checks

**Benefits:**
- Prevents unauthorized modification of resources
- Common pattern in user-generated content systems
- Complements `RequirePermission` for granular access control

---

### Deprecated
**Status:** Planned
**Priority:** Low

**Purpose:** Mark endpoints for future removal, warn API consumers.

**Usage:**
```php
#[Deprecated(message: 'Use /api/v2/users instead', sunset: '2026-01-01')]
public function oldEndpoint($args) {
    // Logs warning, adds Deprecation header
}
```

**Implementation Notes:**
- Add `Deprecation: true` HTTP header (RFC draft)
- Add `Sunset` header with deprecation date
- Log access to deprecated endpoints
- Optionally add `Link` header pointing to replacement
- Consider deprecation levels (warning, error)

**Headers Set:**
```
Deprecation: true
Sunset: Sat, 01 Jan 2026 00:00:00 GMT
Link: </api/v2/users>; rel="alternate"
```

**Benefits:**
- Smooth API transitions
- Consumer awareness of changes
- Track usage of deprecated endpoints

---

### CORS
**Status:** Planned
**Priority:** Low

**Purpose:** Handle Cross-Origin Resource Sharing for API endpoints.

**Usage:**
```php
#[CORS(origins: ['https://example.com'], methods: ['GET', 'POST'])]
public function publicApi($args) {
    // CORS headers set automatically
}

#[CORS(origins: '*')]
public function openEndpoint($args) {
    // Allow all origins
}
```

**Implementation Notes:**
- Set `Access-Control-Allow-Origin` header
- Handle preflight OPTIONS requests
- Set `Access-Control-Allow-Methods`
- Set `Access-Control-Allow-Headers`
- Consider credentials support

---

### ValidateCSRF
**Status:** Planned
**Priority:** Low (if not already implemented globally)

**Purpose:** Validate CSRF tokens for state-changing operations.

**Usage:**
```php
#[AllowedMethods('POST')]
#[ValidateCSRF]
public function updateSettings($args) {
    // CSRF token validated before execution
}
```

**Implementation Notes:**
- Check for CSRF token in POST data or header
- Validate against session-stored token
- Return 403 if invalid/missing
- Token generation helper needed
- Consider token rotation

---

## Implementation Priority

**Phase 1 (Immediate Value):**
1. **AuditLog** - Security/compliance essential for sensitive endpoints
2. **RequireJSON** - Used in 6+ places, eliminates boilerplate
3. **RequireAjax** - Security layer for AJAX-only endpoints

**Phase 2 (Enhanced Functionality):**
4. **RequireOwnership** - User data protection, granular access control
5. **CacheControl** - Performance optimization (complex implementation)

**Phase 3 (API Maturity):**
6. **Deprecated** - API versioning and transition support
7. **CORS** - External API access if needed
8. **ValidateCSRF** - If not handled globally

**Notes:**
- RequireHTTPS and RateLimit are handled earlier in the stack (not needed as attributes)
- AuditLog prioritized first for immediate security/compliance value

---

## Refactors (existing attributes)

### AllowWithToken — stop hand-building the login session
**Status:** ✅ **DONE** — converted onto `Zero\Core\User::establish()` (2026-08-08)
**Location:** `core/attribute/AllowWithToken.php:100-126`

`AllowWithToken` constructs a full login session by hand, under a comment claiming parity with
`Auth::complete()`. It has already drifted:

- does not set `$_SESSION['created_at']`
- does not set `$_SESSION['login_provider']`
- never calls `session_regenerate_id()` — so API-token auth carries the same session-fixation
  gap that OAuth login has

This makes it a second, divergent writer of the session contract documented in
`modules/Auth/CLAUDE.md`.

**Fix:** the manual-registration project extracts session construction into a single helper.
Because that helper has three callers — `Auth::complete()`, the magic-link consumer, and this
attribute — it belongs on `Zero\Core\User` (which already owns session *reads*) rather than as
a private method on the Auth module. Once it exists, replace lines 100-126 with one call.

**Do not do this in isolation** — it is only worth touching once the shared helper lands, or
the divergence just moves.

**Found:** while tracing `user_view` consumers for the `Zero\Model` migration
(`docs/superpowers/specs/2026-08-07-zero-model-user-migration-design.md` §12).

---

### AllowWithToken — refuse an unverified token owner
**Status:** Open
**Priority:** Low (no longer a bypass, but still wrong)
**Location:** `core/attribute/AllowWithToken.php:94-101`

`AllowWithToken` calls `User::establish($user)` for whoever owns the token, without checking
`$user->verified`. That produces a session with a `user_id` for an account that has never
verified its email.

Until 2026-08-16 that session passed `#[RequireLogin]`, which tested `isset($_SESSION['user_id'])`
— see `core/attribute/AUTH_GATE_CONSISTENCY.md`. `RequireLogin` now delegates to
`User::isLoggedIn()`, so the session is refused at the gate and the bypass is closed.

What remains is the smaller wrong: establishing a session that nothing downstream will honour.
Better to fail the request at the token check with a clear 401 than to hand back a session that
silently fails every gated endpoint.

**Fix:** after loading `$user`, deny with `HTTPError(401, "Token owner not verified")` when
`!$user->verified`, before `establish()`.

---

### RequireAuthLevel — shelved, not retired
**Status:** Shelved 2026-08-16. Existing call sites stay; do not add new ones.
**Location:** `core/attribute/RequireAuthLevel.php`
**Documentation:** `docs/attributes/RequireAuthLevel.md` (full reasoning + un-shelving condition)

`RequireAuthLevel` was meant as the **blanket** alternative to `RequirePermission`'s **granular**
grants. It has not earned that role:

- The install has exactly one populated `auth.level` (user 1, value `1`), and every
  `#[RequireAuthLevel]` in the tree asks for level `1`. There is no second tier, so it collapses
  into "is this user special" — which `allow.admin` answers more precisely.
- Every module gating on it also carries `#[RequirePermission]`, **except** `modules/Sitemap` and
  `modules/Test`. Dropping it from the rest would change nothing about who gets in.
- It is the **only** auth gate that does not open with `(new RequireLogin())->handler();` the way
  `RequirePermission.php:46` does, so it alone does not enforce a verified email. Latent rather
  than live: neither bare-use module carries `#[AllowWithToken]`, and no module anywhere pairs the
  two attributes.

**Un-shelve when** there is a real need for tiers that named `allow.*` grants cannot express —
more than one populated `auth.level` with a meaningful "level 5 implies level 3" ordering. If that
happens, add the `RequireLogin` delegation first so all three gates share one definition.

**Also:** `modules/Sqrlcam/Sqrlcam.php:5` imports `RequireAuthLevel` and never applies it. Dead
`use`, safe to drop.

---

### Verified-email invariant — enforce it where it is written, not just where it is read
**Status:** Open
**Priority:** Medium
**Found:** tracing whether `users.verified` can go 1 → 0 (2026-08-16)

Two invariants currently hold **by coincidence of data**, exactly like the one
`AUTH_GATE_CONSISTENCY.md` documents:

**1. "No unverified user has permissions."** True — all 27 `allow.*` rows in `user_settings`
belong to verified accounts — but nothing enforces it. `User::setSetting()` (`core/User.php:341`)
has no `verified` check, and neither grant path does (`modules/Admin/Admin.php:310`,
`modules/AccessRequest/AccessRequest.php:269`). **Fix:** the check belongs in `setSetting()` —
one place, both callers covered. This matters more than the `RequireLogin`/`RequireVerified`
question, because it is what actually makes `RequirePermission` the reliable gate.

**2. "`verified` never goes 1 → 0."** True in PHP: the only two writes are
`Auth.php:264` and `Auth.php:741`, both `= 1`, both guarded on `!== 1`, and `Model::save()`
writes only dirty columns so nothing can clobber it as a side effect. Admin displays the flag but
has no toggle. Outside PHP, three vectors remain:

- **`user_view` is updatable.** `information_schema.VIEWS.IS_UPDATABLE = YES` — it is a bare
  single-table projection left over from the EAV cutover, so `UPDATE user_view SET verified = 0`
  writes through to `users`. Nothing does this today; the surface just is not obvious from a file
  named like a read-only compatibility view.
- **`core/user_view_eav_rollback.sql`** reverts the 9 rows to the 2026-08-08 snapshot. Already
  self-documented there; currently moot only because no row has been touched since cutover.
- Manual SQL, or a restore from a pre-verification backup.

**Do NOT split `RequireVerified` out of `RequireLogin` to address this.** `RequirePermission`
delegates to `RequireLogin` for its whole login check; splitting the predicate hands it two things
to compose and re-opens the drift that `AUTH_GATE_CONSISTENCY.md` closed. Revisit only if a
concrete endpoint needs "logged in, unverified is fine" — none does.

---

### Stale comment: Google adapter normalization
**Status:** Open
**Priority:** Trivial
**Location:** `modules/Auth/Auth.php:255-260`

The comment inside `findOrCreateUser()` says Google `format()` "passes Google's value straight
through with no normalization (`$user['verified_email'] ?: $user['email_verified']`)".
`GoogleAdapter.php:96` has since been changed to `filter_var(… ?? … ?? false, FILTER_VALIDATE_BOOLEAN)`.
The unnormalized adapter is now **Keycloak** (`KeycloakAdapter.php:258`, raw
`$claims['email_verified']`), which is what the `filter_var` in that block actually guards. The
code is right; the comment names the wrong provider.

For reference, how each adapter derives `verified` — no provider emits a uniform claim:

| Adapter | Source | Normalization |
|---|---|---|
| GitHub | second call to `/user/emails`, entry with `primary && verified` | `=== true` |
| GitLab | `!empty($raw['confirmed_at'])` from `/api/v4/user` | truthiness |
| Discord | `$raw['verified']` | `=== true` |
| Google | `verified_email ?? email_verified` | `filter_var(…, BOOLEAN)` |
| Keycloak | `$claims['email_verified']` | none — raw passthrough |

`Auth::enforceVerifiedEmail()` applies `FILTER_VALIDATE_BOOLEAN` at the boundary, which is what
catches Keycloak's unnormalized value.

---

## Notes

- All attributes should follow the existing pattern:
  - Namespace: `\Zero\Core\Attribute`
  - Location: `core/attribute/`
  - Must have `handler()` method returning `true` or `Error` object
  - Document in `docs/attributes/` when implemented

- Consider attribute interactions:
  - `RequireJSON` + `RequiredParams` should validate JSON keys
  - `RateLimit` + `AuditLog` for attack detection
  - `RequireOwnership` + `RequirePermission` for layered security

- Performance considerations:
  - Cache database lookups where possible
  - Use Redis for RateLimit to avoid database load
  - AuditLog should be async to avoid blocking requests
