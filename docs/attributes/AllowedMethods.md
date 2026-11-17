# AllowedMethods

**Namespace:** `\Zero\Core\Attribute\AllowedMethods`

**Purpose:** Restrict which HTTP methods can access an endpoint.

## Usage

```php
use Zero\Core\Attribute\AllowedMethods;

#[AllowedMethods('POST')]
public function submit() {
    // POST only
}

#[AllowedMethods(['GET', 'POST'])]
public function flexible() {
    // Both allowed
}

#[AllowedMethods(['PUT', 'PATCH'])]
public function update() {
    // REST API endpoint
}
```

## Parameters

- `$methods` (string|array) - Single method or array of allowed methods

## When to Use

- Form submissions (POST only)
- REST API endpoints
- CSRF prevention via method restrictions
- Enforcing semantic HTTP usage

## Error Response

- HTTP 405 Method Not Allowed
- Message: "Method not allowed. Allowed: POST, GET"

## Notes

- Methods normalized to uppercase internally
- Checks `Request::$method` (from `$_SERVER['REQUEST_METHOD']`)
- Best practice: Be explicit for security

## Override

A method-level `#[AllowedMethods]` replaces a class-level `#[AllowedMethods]` for that endpoint. Useful when a class sets a default (e.g. `GET`-only) but one method needs different semantics (e.g. `POST` for a form submission):

```php
#[AllowedMethods('GET')]
class Api extends Module {

    public function list() {
        // GET only (inherited from class)
    }

    #[AllowedMethods('POST')]
    public function create() {
        // POST only — class-level GET restriction does NOT apply
    }
}
```

See [../ATTRIBUTES.md](../ATTRIBUTES.md) for the general override rule across all attribute types.

## Implementation

Location: `core/attribute/AllowedMethods.php:24-32`
```php
public function handler() {
    $currentMethod = strtoupper(Request::$method);

    if (!in_array($currentMethod, $this->methods)) {
        throw new HTTPError(405, 'Method >'.Request::$method.'< not allowed. Allowed: ' . implode(', ', $this->methods));
    }

    return true;
}
```
