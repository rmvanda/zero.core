# Validate

**Namespace:** `\Zero\Core\Attribute\Validate`

**Purpose:** Validate POST/GET parameters before method execution.

> **STATUS: STUB/PROPOSAL** - This attribute is partially implemented. Basic validators work but not all features are complete. Use with caution in production.

## Usage

```php
use Zero\Core\Attribute\Validate;

// Built-in validators
#[Validate(['email' => 'email'])]
public function subscribe() {
    // $_POST['email'] must be valid email format
}

// Multiple rules
#[Validate(['password' => ['required', 'length:8,']])]
public function register() {
    // password is required and minimum 8 characters
}

// Value must be in list
#[Validate(['status' => 'in:active,pending,closed'])]
public function updateStatus() {
    // status must be one of the listed values
}

// Regex pattern
#[Validate(['slug' => '/^[a-z0-9-]+$/'])]
public function lookup() {
    // slug must match regex pattern
}
```

## Parameters

- `$rules` (array) - Validation rules per parameter
  - `['field' => 'rule']` - Single rule
  - `['field' => ['rule1', 'rule2']]` - Multiple rules

## Built-in Validators

### Currently Implemented

| Validator | Description |
|-----------|-------------|
| `required` | Value must not be empty |
| `email` | Valid email format |
| `url` | Valid URL format |
| `int` | Valid integer |
| `float` | Valid float/decimal |
| `bool` | Boolean value |
| `in:a,b,c` | Value must be in comma-separated list |
| `length:min,max` | String length between min and max |
| `range:min,max` | Numeric value between min and max |
| `/regex/` | Must match regex pattern |

### Proposed (Not Yet Implemented)

| Validator | Description |
|-----------|-------------|
| `alpha` | Letters only |
| `alphanumeric` | Letters and numbers only |
| `date` | Valid date format |
| `datetime` | Valid datetime format |
| `json` | Valid JSON string |
| `matches:field` | Must match another field |

## Error Response

- HTTP 400 Bad Request
- Message: "Validation failed: email must be a valid email; password must be at least 8 characters"

## Design Considerations (Open Questions)

1. **Error handling**: Should it throw `Error(400)` or return messages for the application to handle?
2. **Custom validators**: Should we support callback functions?
3. **Conditional validation**: How to validate X only if Y is present?
4. **Custom error messages**: Should validators support custom messages?
5. **Chainable syntax**: Should we support `'required|email|length:,255'` format?

## When to Use

- Form submission validation
- API input validation
- Reducing boilerplate validation code
- Use AFTER `Sanitize` attribute

## Recommended Order

```php
#[AllowedMethods('POST')]
#[RequiredParams(['email', 'password'])]
#[Sanitize(['email', 'password'])]
#[Validate(['email' => 'email', 'password' => 'length:8,'])]
public function register() {
    // 1. Only POST allowed
    // 2. Both params must exist
    // 3. Both params sanitized
    // 4. Both params validated
}
```

## Implementation

Location: `core/attribute/Validate.php`

**Note:** This is a stub implementation. Full implementation pending based on actual usage patterns and requirements.
