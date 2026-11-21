# AllowedMethods

**Class:** `Zero\Core\Attribute\AllowedMethods` (`core/attribute/AllowedMethods.php`)

Restricts which HTTP methods may reach an endpoint.

## Usage

```php
use Zero\Core\Attribute\AllowedMethods;

#[AllowedMethods('POST')]
public function submit() {}

#[AllowedMethods(['PUT', 'PATCH'])]
public function update() {}
```

## Parameters

- `$methods` (string|array): one method or a list of them. Case does not matter.

## Behaviour

Compares `Request::$method` (`$_SERVER['REQUEST_METHOD']`) with the list. A
method not on it throws `HTTPError(405)` with the detail
`Method >GET< not allowed. Allowed: POST, PUT`. Nothing is logged.

A method-level `#[AllowedMethods]` replaces a class-level one, so a class can
default to `GET` and one endpoint can take `POST`. See
[../ATTRIBUTES.md](../ATTRIBUTES.md#class-level-and-method-level).

Tests: `core/tests/Attribute/AllowedMethodsTest.php`
