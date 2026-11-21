# AuditLog

**Class:** `Zero\Core\Attribute\AuditLog` (`core/attribute/AuditLog.php`)

Records a request to the endpoint as an `Event` entity. It never refuses a
request: `handler()` always passes, and a failure to write the event is caught
and logged (`AuditLog failed: …`, at error).

## Usage

```php
use Zero\Core\Attribute\{RequirePermission, AuditLog, AllowedMethods};

#[RequirePermission('allow.admin')]
#[AuditLog('admin_permission_change', includeParams: true)]
#[AllowedMethods('POST')]
public function updatePermission() {}
```

Declare it after the auth attributes, so the event carries the user and is
written only for callers who got in, and before `AllowedMethods` and the
parameter attributes. See [../ATTRIBUTES.md](../ATTRIBUTES.md#order).

## Parameters

| Parameter | Type | Default | Description |
|---|---|---|---|
| `$eventType` | `string` | required | The event's type, for grouping (`admin_action`, `data_export`, `permission_grant`). |
| `$includeParams` | `bool` | `false` | Add the request parameters to the event. |

## What is recorded

One `Zero\Entity\Event`:

| Field | Value |
|---|---|
| `event` | `$eventType` |
| `datetime` | Now. Set by `Event`. |
| `ip` | The client's address, `Request::clientIp()`. Set by `Event`. |
| `detail` | One string, parts joined by ` \| `, in the order below. |

`detail` parts:

- `Endpoint: {module}/{endpoint}` (`Request::$module`, `Request::$endpoint`)
- `User ID: {id}`, when `Session::userId()` is not null
- `Username: {name}`, when `$_SESSION['username']` is set
- `Method: {HTTP method}`
- `Params: {json}`, when `includeParams` is true and there are any
- `UA: {user agent}`, cut to 100 characters

An anonymous request is recorded too, without the user parts.

### Parameters in the event

`$_GET` and `$_POST` merged (POST wins on a shared key), then:

- These top-level keys are replaced with `[REDACTED]`: `password`, `passwd`,
  `pwd`, `pass`, `secret`, `token`, `api_key`, `apikey`, `auth`,
  `authorization`, `csrf`, `csrf_token`. The match is on the exact key; a
  nested key or one of another name (`new_password`) is recorded as sent.
- A string value over 200 characters is cut to 200 with `... [truncated]`.

## Cost

The event is written synchronously, on every request that reaches the
attribute. Leave `includeParams` off on high-traffic endpoints.

## Reading the log

Events are entities; query the pivoted `event_view`:

```sql
SELECT * FROM event_view
WHERE event = 'admin_action'
ORDER BY datetime DESC
LIMIT 100;

-- By user: the id is inside the detail string
SELECT * FROM event_view
WHERE event = 'admin_action' AND detail LIKE '%User ID: 5 |%';
```
