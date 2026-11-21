# Sanitize

**Class:** `Zero\Core\Attribute\Sanitize` (`core/attribute/Sanitize.php`)

Cleans GET/POST parameters in place before the endpoint runs. It never refuses
a request.

It is not an escape. The default method keeps `<`, `>`, quotes and slashes, so
a sanitized value still needs a prepared statement on its way into SQL and
`htmlspecialchars()` on its way into a page.

## Usage

```php
use Zero\Core\Attribute\Sanitize;

#[Sanitize(['title', 'content'])]                     // default method
#[Sanitize(['user_id', 'post_id'])]                   // names ending in "id": integer
#[Sanitize(['title' => 'filename'])]                  // preset
#[Sanitize(['email' => FILTER_SANITIZE_EMAIL])]       // filter_var filter
#[Sanitize(['code' => '/[^a-zA-Z0-9]/'])]             // regex: what matches is removed

#[AllowedMethods('POST')]
#[RequiredParams(['title', 'content'])]
#[Sanitize(['title', 'content', 'category_id'])]
public function create() {}
```

## Parameters

- `$params` (array): a list of parameter names, or `name => method` pairs, or a
  mix of both.

## Behaviour

For each named parameter, the value in `$_POST` and the value in `$_GET` are
each replaced with the sanitized one, if set. A parameter that is not set is
left alone. An array value is sanitized element by element, recursively.

Declare it after `RequiredParams` and before `Validate`; see
[../ATTRIBUTES.md](../ATTRIBUTES.md#order).

## Methods

The method for a parameter is chosen in this order.

**1. No method, name ends in `id`** (case-insensitive: `user_id`, `postId`,
and also `paid` or `valid`): the value becomes an integer, by
`FILTER_SANITIZE_NUMBER_INT` and an `(int)` cast. An empty or non-numeric value
becomes `0`. Giving the parameter any method turns this off.

**2. No method, any other name**: the default. Removes every character that is
not a letter, a digit, whitespace or one of

```
- _ . , ! ? @ # $ % & * ( ) + = [ ] { } | \ : ; " ' < > / ~ `
```

which leaves control characters, `^` and symbols such as emoji to be removed.
Runs of whitespace then collapse to one space and the value is trimmed.

**3. A preset name:**

| Preset | Keeps |
|--------|-------|
| `filename` | Letters, digits, whitespace, `-` `_` `.` |
| `slug` | `a-z`, `0-9`, `-`. Uppercase letters and spaces are removed, not converted: `My Slug` becomes `ylug`. |
| `alphanumeric` | Letters and digits |
| `alpha` | Letters |
| `numeric` | `0-9` |

Whitespace is collapsed and trimmed afterwards, as in the default.

**4. A `filter_var` sanitize filter** (an integer from 500 to 600, which is
where the `FILTER_SANITIZE_*` constants are): the value is passed through
`filter_var($value, $filter)`. No flags can be given, so
`FILTER_SANITIZE_NUMBER_FLOAT` removes the decimal point as well.

**5. A regex**, delimited by one of `/ # ~ @ ! %`, with optional flags.
Everything the pattern matches is **removed**, so write what to strip, usually
a negated class: `/[^a-z0-9]/` keeps only `a-z` and `0-9`. A pattern written to
describe a valid value (`/^[a-z0-9-]+$/`) deletes every valid value. Whitespace
is collapsed and trimmed afterwards.

**Anything else** (an unknown preset name, a `FILTER_VALIDATE_*` constant):
the default method is used, and a warn is logged:

```
Sanitize: Unknown method for 'title', using default
```

That is the only thing the attribute logs. Changed values are not logged.
