# RequiredParams

**Namespace:** `\Zero\Core\Attribute\RequiredParams`

**Purpose:** Validate required parameters exist in GET or POST before execution.

## Usage

```php
use Zero\Core\Attribute\RequiredParams;

#[RequiredParams('username')]
public function lookup() {
    $username = $_POST['username'] ?? $_GET['username'];
    // Guaranteed to exist
}

#[RequiredParams(['email', 'password'])]
public function register() {
    // Both guaranteed present
}

// Combined with other attributes
#[AllowedMethods('POST')]
#[RequiredParams(['requested_url', 'reason'])]
public function submit() {
    // POST only + both params required
}
```

## Parameters

- `$params` (string|array) - Single param or array of required param names

## Validation Logic

- Checks both `$_POST` and `$_GET`
- Parameter missing if:
  - Not in POST or GET
  - Present but `empty()` (empty string, null, 0, '0', false)
- Reports ALL missing parameters in error

## When to Use

- Form submission handlers
- API endpoints with required fields
- Reducing boilerplate validation
- Preventing undefined index errors

## Error Response

- HTTP 400 Bad Request
- Message: "Missing required parameter(s): email, password"

## Debug Output

```
RequiredParams checking for: email, password
  - Found: email = user@example.com
  - Missing: password
RequiredParams attribute blocked request - Missing: password
POST params available: email, action
```

## Notes

- Only validates presence, NOT format
- For complex validation, handle in method
- Empty string/0/'0'/false considered missing

## Implementation

Location: `core/attribute/RequiredParams.php:21-47`
