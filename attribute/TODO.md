# Attribute System TODO

This document tracks planned attributes for the Zero Framework's attribute system.

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
