# Sanitize

**Namespace:** `\Zero\Core\Attribute\Sanitize`

**Purpose:** Sanitize POST/GET parameters before method execution to prevent injection attacks and ensure clean data.

## Usage

```php
use Zero\Core\Attribute\Sanitize;

// Default sanitization - strips special symbols
#[Sanitize(['title', 'content'])]
public function create() {
    // $_POST['title'] and $_POST['content'] are sanitized
}

// Specific filter_var filter
#[Sanitize(['email' => FILTER_SANITIZE_EMAIL])]
public function subscribe() {
    // $_POST['email'] sanitized as email address
}

// Built-in presets
#[Sanitize(['title' => 'filename'])]
public function create() {
    // $_POST['title'] sanitized for safe filenames
}

// Custom regex pattern - removes non-matching characters
#[Sanitize(['code' => '/[^a-zA-Z0-9]/'])]
public function lookup() {
    // $_POST['code'] keeps only alphanumeric chars
}

// Auto-detect numeric IDs (keys ending in 'id')
#[Sanitize(['user_id', 'post_id'])]
public function update() {
    // Both sanitized as integers automatically
}

// Combined with other attributes
#[AllowedMethods('POST')]
#[RequiredParams(['title', 'content'])]
#[Sanitize(['title', 'content', 'category_id'])]
public function create() {
    // POST only, params required, then sanitized
}
```

## Parameters

- `$params` (array) - Parameters to sanitize with optional sanitization method
  - Numeric key: `['title']` - default sanitization
  - String key with filter: `['email' => FILTER_SANITIZE_EMAIL]`
  - String key with regex: `['slug' => '/^[a-z0-9-]+$/']`

## Sanitization Methods

### Default (no method specified)
- Removes special characters while preserving readable text
- Keeps: letters, numbers, spaces, basic punctuation (`-_.!?@#$%&*()`)
- Normalizes whitespace

### Auto-detected ID Fields
- Any parameter ending in `id` (case-insensitive) is sanitized as integer
- Examples: `user_id`, `postId`, `CATEGORY_ID`

### Built-in Presets
Use preset names as the value for common sanitization patterns:

| Preset | Keeps | Use Case |
|--------|-------|----------|
| `filename` | Letters, numbers, spaces, `-_.` | File names, titles |
| `slug` | Lowercase letters, numbers, `-` | URL slugs |
| `alphanumeric` | Letters and numbers only | Codes, identifiers |
| `alpha` | Letters only | Names |
| `numeric` | Numbers only | Numeric strings |

Example: `#[Sanitize(['title' => 'filename'])]`

### filter_var Filters
Supported filter constants:
- `FILTER_SANITIZE_EMAIL` - Remove illegal email characters
- `FILTER_SANITIZE_URL` - Remove illegal URL characters
- `FILTER_SANITIZE_NUMBER_INT` - Remove non-numeric characters
- `FILTER_SANITIZE_NUMBER_FLOAT` - Remove non-numeric (except . and ,)
- `FILTER_SANITIZE_FULL_SPECIAL_CHARS` - HTML-encode special chars

### Custom Regex Patterns
- Pattern must start/end with delimiter (`/`, `#`, `~`, etc.)
- Characters matching the pattern are REMOVED
- Use negated character class to keep specific chars: `/[^a-z0-9]/` keeps only a-z and 0-9
- Whitespace is normalized after pattern application

## Validation Logic

- Sanitizes both `$_POST` and `$_GET` values
- Handles arrays recursively
- Logs changes to Console for debugging
- Non-destructive: original values in superglobals are replaced

## When to Use

- Before processing user input
- File upload names
- Search queries
- Any user-provided data used in output or queries
- Pair with `RequiredParams` for complete validation

## Debug Output

```
Sanitize processing 3 parameter(s)
  Auto-detected 'user_id' as numeric ID
  Sanitized POST['title']: 'Hello<script>alert(1)</script>' -> 'Helloalert1'
  Sanitized POST['user_id']: '42abc' -> '42'
```

## Notes

- Runs BEFORE method execution (like all attributes)
- Order matters: use after `RequiredParams` if both present
- For validation without modification, use `Validate` attribute
- Empty values remain empty (not sanitized to placeholder)

## Implementation

Location: `core/attribute/Sanitize.php`
