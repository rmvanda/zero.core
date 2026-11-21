# Validate

**Class:** `Zero\Core\Attribute\Validate` (`core/attribute/Validate.php`)

Checks GET/POST parameters against rules before the endpoint runs, and refuses
the request with a `400` when any fail.

> **Status: partial.** The class is marked a stub. The rules below work; a rule
> name it does not know **passes** with a logged warning, so a typo in a rule
> validates nothing.

## Usage

```php
use Zero\Core\Attribute\Validate;

#[Validate(['email' => 'email'])]
#[Validate(['password' => ['required', 'length:8,']])]
#[Validate(['status' => 'in:active,pending,closed'])]
#[Validate(['slug' => '/^[a-z0-9-]+$/'])]

#[AllowedMethods('POST')]
#[RequiredParams(['email', 'password'])]
#[Sanitize(['email' => FILTER_SANITIZE_EMAIL])]
#[Validate(['email' => 'email', 'password' => 'length:8,'])]
public function register() {}
```

Declare it last, so it judges the sanitized value; see
[../ATTRIBUTES.md](../ATTRIBUTES.md#order).

## Parameters

- `$rules` (array): `['field' => 'rule']` or `['field' => ['rule1', 'rule2']]`.

## Behaviour

The value is `$_POST[field]`, else `$_GET[field]`. Every rule of every field is
checked and all failures are reported together.

A value that is absent or `''` fails `required` and **passes every other rule**:
a rule constrains a value only when one is sent. Add `required` to insist on it.

## Rules

| Rule | Passes when | Failure message |
|------|-------------|-----------------|
| `required` | The value is not absent and not `''` (`'0'` passes) | `{field} is required` |
| `email` | `FILTER_VALIDATE_EMAIL` accepts it | `{field} must be a valid email` |
| `url` | `FILTER_VALIDATE_URL` accepts it | `{field} must be a valid url` |
| `int` | `FILTER_VALIDATE_INT` accepts it | `{field} must be a valid int` |
| `float` | `FILTER_VALIDATE_FLOAT` accepts it | `{field} must be a valid float` |
| `bool` | The value is a **true** boolean: `1`, `true`, `on`, `yes`. `0`, `false`, `off` and `no` fail, as any non-boolean does. | `{field} must be a valid bool` |
| `in:a,b,c` | The value is one of the comma-separated items | `{field} must be one of: a,b,c` |
| `length:min,max` | `strlen()` (bytes) is within the bounds. Omit `max` for no upper bound (`length:8,`); an omitted `min` is 0 (`length:,255`). | `{field} must be at least {min} characters` / `at most {max} characters` |
| `range:min,max` | The value, cast to float, is within the bounds. Either bound may be omitted. A non-numeric value counts as 0. | `{field} must be at least {min}` / `at most {max}` |
| `/regex/` | `preg_match()` matches. Delimiters: `/ # ~ @ ! %`, with optional flags. | `{field} format is invalid` |

Not implemented: `alpha`, `alphanumeric`. Like any unknown rule they pass, and
log at warn `Validate: Unknown rule 'alpha' for 'name'` (or
`Validate: Unknown parameterized rule '…'` for an unknown `name:args` rule).

## Refusal

`HTTPError(400)` with every failure, joined by `; `:

```
Validation failed: email must be a valid email; password must be at least 8 characters
```

The same line is logged at warn.

## Open questions

Undecided in the class, and the reason it is still a stub:

1. Custom validator callbacks.
2. Conditional rules (validate X only if Y is present).
3. Per-field error messages.
4. A chained syntax (`'required|email|length:,255'`).
5. More rules: `alpha`, `alphanumeric`, `date`, `datetime`, `json`, `matches:field`.
