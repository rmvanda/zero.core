# AuditLog

**Purpose:** Automatically log endpoint access to the event table for security auditing and compliance.

## Basic Usage

```php
use \Zero\Core\Attribute\AuditLog;

#[AuditLog('admin_action')]
public function updatePermission() {
    // Endpoint access automatically logged to event table
}
```

## Parameters

- **`eventType`** (string, required): Event category/type for grouping logs
- **`includeParams`** (bool, optional, default: false): Include sanitized request parameters
- **`requireAuth`** (bool, optional, default: false): Only log if user is authenticated

## Usage Examples

### Basic Audit Logging

```php
#[RequirePermission('allow.admin')]
#[AuditLog('admin_action')]
public function deleteUser() {
    // Logs: event type, endpoint, user ID, IP, timestamp
}
```

### Include Request Parameters

```php
#[AuditLog('data_export', includeParams: true)]
public function exportData() {
    // Logs with sanitized request parameters
    // Sensitive keys (password, token, etc.) are redacted
}
```

### Only Log Authenticated Access

```php
#[AuditLog('sensitive_view', requireAuth: true)]
public function viewProfile() {
    // Only logs if user is logged in
    // Silent skip if anonymous access
}
```

## What Gets Logged

The attribute creates an Event entity with:

**Automatic (from Event class):**
- `datetime` - Current timestamp
- `ip` - Client IP address (from $_SERVER['REMOTE_ADDR'])

**From AuditLog:**
- `event` - Event type (from parameter)
- `detail` - Formatted string containing:
  - Endpoint (module/method)
  - User ID (if available)
  - Username (if available)
  - HTTP method (GET/POST)
  - Request parameters (if includeParams=true, sanitized)
  - User agent (truncated to 100 chars)

## Parameter Sanitization

When `includeParams: true`, sensitive fields are automatically redacted:

**Redacted fields:**
- password, passwd, pwd, pass
- secret, token, api_key, apikey
- auth, authorization
- csrf, csrf_token

Long values (>200 chars) are truncated with "[truncated]" suffix.

## Error Handling

- Logging errors are caught and logged to Console
- Attribute always returns `true` - **logging never blocks execution**
- If `requireAuth: true` and user not authenticated, silently skips logging

## Common Event Types

Recommended event type naming:

- `admin_action` - Administrative operations
- `user_data_access` - Access to user data
- `permission_change` - Permission modifications
- `data_export` - Data export operations
- `config_change` - Configuration changes
- `auth_attempt` - Authentication attempts (use Auth module instead)

## Performance Considerations

- Event creation is synchronous - adds minimal overhead
- Use `includeParams: false` for high-traffic endpoints
- Consider archiving old events periodically
- Event table uses EAV schema - query carefully for large datasets

## Best Practices

1. **Combine with permission attributes:**
   ```php
   #[RequirePermission('allow.admin')]
   #[AuditLog('admin_action')]
   ```

2. **Use descriptive event types:**
   - Good: `user_data_export`, `permission_grant`
   - Bad: `action`, `log`

3. **Enable params for critical operations:**
   ```php
   #[AuditLog('permission_grant', includeParams: true)]
   ```

4. **Use requireAuth for optional endpoints:**
   ```php
   // Public endpoint that tracks authenticated access only
   #[AuditLog('public_api', requireAuth: true)]
   ```

## Example: Admin Panel Audit Trail

```php
namespace Zero\Module;

use \Zero\Core\Attribute\{RequirePermission, AuditLog, AllowedMethods};

class Admin extends \Zero\Core\Module {

    #[RequirePermission('allow.admin')]
    #[AuditLog('admin_permission_change', includeParams: true)]
    #[AllowedMethods('POST')]
    public function updatePermission() {
        // Logs user ID, which permission was changed, and new value
    }

    #[RequirePermission('allow.admin')]
    #[AuditLog('admin_user_view')]
    public function viewUser() {
        // Logs which admin viewed which user
    }
}
```

## Querying Audit Logs

Events are stored in the `event` table using EAV schema. View the `event_view` for easier querying:

```sql
-- Recent admin actions
SELECT * FROM event_view
WHERE event = 'admin_action'
ORDER BY datetime DESC
LIMIT 100;

-- Actions by specific user
SELECT * FROM event_view
WHERE event = 'admin_action'
AND detail LIKE '%User ID: 5%'
ORDER BY datetime DESC;

-- Actions within date range
SELECT * FROM event_view
WHERE event LIKE 'admin%'
AND datetime BETWEEN '2025-01-01' AND '2025-12-31';
```
