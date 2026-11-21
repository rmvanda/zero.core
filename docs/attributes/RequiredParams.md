# RequiredParams

**Class:** `Zero\Core\Attribute\RequiredParams` (`core/attribute/RequiredParams.php`)

Requires parameters to be present in GET or POST before the endpoint runs.

## Usage

```php
use Zero\Core\Attribute\RequiredParams;

#[RequiredParams('username')]
public function lookup() {
    $username = $_POST['username'] ?? $_GET['username'];
}

#[AllowedMethods('POST')]
#[RequiredParams(['requested_url', 'reason'])]
public function submit() {}
```

## Parameters

- `$params` (string|array): one parameter name or a list of them.

## Behaviour

A parameter is missing when it is `empty()` in both `$_POST` and `$_GET`. That
includes an absent key, an empty string, `0`, `'0'`, `false` and an empty
array, so this attribute does not suit a parameter whose valid values include
zero.

If any are missing it throws `HTTPError(400)` naming all of them:
`Missing required parameter(s): email, password`, and logs at warn:

```
RequiredParams attribute blocked request - Missing: email, password
```

It checks presence only. For format, see [Validate](Validate.md).
