# Zero Framework Attributes

PHP 8 attributes declare the gates and input handling for an endpoint. Each one
is a class in `core/attribute/` (namespace `Zero\Core\Attribute`) with a
`handler()` method, and the framework runs them before the endpoint.

## How they run

`Application::checkAttributes($class, $endpoint)` runs every attribute that
applies to one endpoint:

1. `Application::resolveAttributes()` collects the class-level and method-level
   attributes and decides which apply (see [Class level and method level](#class-level-and-method-level)).
2. Each one is instantiated and its `handler()` is called, class-level first,
   then method-level, each in declaration order.
3. A handler stops the request by throwing `HTTPError` (rendered by the module's
   `renderError()`) or by redirecting and exiting. The return value is ignored.
4. If none stops it, the endpoint runs.

Only attributes in the `Zero\Core\Attribute\` namespace are run
(`Application::gates()`). Anything else on a class or method, such as PHP's
`#[\AllowDynamicProperties]`, is ignored.

`checkAttributes()` is public and static because every dispatcher calls it for
its own `(class, endpoint)` pair. The last three call it before constructing
the class they dispatch to; `Application::run()` constructs the module first,
because that instance renders the error.

| Dispatcher | Checks |
|---|---|
| `Application::run()` | the module named by the URL |
| `Module::__call()` | a submodule |
| `AdminResponse::routeToModuleAdmin()` | a module-admin controller |
| `Response::__call()` | `Index`, for a standalone page with a controller method behind it |

Gates therefore add up down the nesting chain and nothing is evaluated twice:
`/ttrpg/hexmap/save` runs `(Ttrpg, 'hexmap')`, then `(Ttrpg\Hexmap, 'save')`.

`resolveAttributes()` returns the resolved sequence without running it
(`class`, `method`, `effective`, `scope`, `overridden`). `AttributeOrderTest`
and the DevToolbar's attribute tab (`Application::$attributeLog`) read it.

## Class level and method level

A class-level attribute applies to every endpoint of the class. A method-level
attribute applies to one.

**Method level replaces class level of the same type.** If a method declares
any attribute of type `X`, the class-level attributes of type `X` are skipped
for that endpoint. Class-level attributes of other types still apply.

```php
#[AllowedMethods('GET')]
class Api extends Module {

    public function list() {}      // GET only, from the class

    #[AllowedMethods('POST')]
    public function create() {}    // POST only: the class-level GET does not apply
}
```

**`RequirePermission` is the one exception: it adds.** Both declarations run,
so the requirement is the union. `Application::isAdditive()` names it.

```php
#[RequirePermission('allow.receipt.view')]
class Receipts extends Module {

    #[RequirePermission('allow.receipt.edit')]
    public function update() {}    // needs allow.receipt.view AND allow.receipt.edit
}
```

Replace is the rule because the other attributes name a destination or a
constraint, and two of those contradict each other. Two permissions are a
longer list.

**A class-level gate cannot be dropped for one endpoint.** A method can replace
it with another of the same type, never remove it, and a class-level
`RequirePermission` cannot even be narrowed. A class with any deliberately
public endpoint gates per method.

### `__call()` endpoints

A virtual endpoint (the URL segment is data, not a method) has no method to
carry attributes, so the attributes on `__call()` are used as its method-level
attributes, under the same replace and additive rules. A class-level gate still
applies when `__call()` declares nothing of that type, so a class whose
`__call()` serves anonymous traffic must not carry a class-level gate.
`modules/TheButton` gates each real method for this reason.

`__call()`'s attributes also run when the endpoint names a submodule, because
the parent has no method of that name. The submodule's own check then runs on
top.

### Inheritance

Class-level attributes are **not** inherited: `ReflectionClass::getAttributes()`
does not walk the parent. Method-level attributes **are**, because an inherited
method keeps the attributes of its declaration.

```php
#[RequireLogin]                        // does NOT reach Child
class Base extends Module {
    #[RequirePermission('allow.x')]    // DOES reach Child::save()
    public function save() {}
}

class Child extends Base {}            // ungated at class level
```

Gate every class that needs it, in its own file. A submodule extends `Module`,
not its parent module, so it does not inherit the parent's gates either; the
parent's are checked at the parent's dispatch level.

## Available attributes

Listed in pipeline order.

| Attribute | Purpose | Page |
|-----------|---------|------|
| `RestrictIP` | Allow only listed client IPs / CIDR ranges | [RestrictIP.md](attributes/RestrictIP.md) |
| `AllowWithToken` | Establish a session from a Bearer token, for API clients | none; see below |
| `RequireLogin` | Require a signed-in, verified user | [RequireLogin.md](attributes/RequireLogin.md) |
| `RequireAuthLevel` | Require a minimum `auth.level`. **Shelved**: use `RequirePermission` | [RequireAuthLevel.md](attributes/RequireAuthLevel.md) |
| `RequirePermission` | Require named capabilities (`allow.*`); implies login | [RequirePermission.md](attributes/RequirePermission.md) |
| `AuditLog` | Record the request in the `event` table | [AuditLog.md](attributes/AuditLog.md) |
| `AllowedMethods` | Restrict the HTTP method | [AllowedMethods.md](attributes/AllowedMethods.md) |
| `RequiredParams` | Require GET/POST parameters to be present | [RequiredParams.md](attributes/RequiredParams.md) |
| `Sanitize` | Clean GET/POST parameters in place | [Sanitize.md](attributes/Sanitize.md) |
| `Validate` | Check parameter format and values (partial) | [Validate.md](attributes/Validate.md) |

**`AllowWithToken`** (`core/attribute/AllowWithToken.php`, no parameters). With
no `Authorization` header it passes, leaving normal session auth to the gates
after it. With one, the header must be `Bearer zk_…`; the token's SHA-256 hash
is looked up in `api_tokens`, and a malformed header, unknown token, expired
token or missing owner is a `401`. A valid token calls `Session::establish()`
for its owner, sets `$_SESSION['_api_token_auth']` and updates `last_used_at`.
It does not check that the owner is verified; `RequireLogin` after it does.

## Order

Attributes are not sorted at runtime: declaration order is execution order,
class level before method level. The order to declare them in:

```php
#[RestrictIP([...])]              //  1. Network deny; needs nothing from the session
#[AllowWithToken]                 //  2. Establishes a session from a Bearer token
#[RequireLogin]                   //  3. Who are you
#[RequireAuthLevel(1)]            //  4. Shelved; holds a slot because modules still declare it
#[RequirePermission('allow.api')] //  5. What may you do
#[AuditLog('action_type')]        //  6. Reads the session's user id
#[AllowedMethods('POST')]         //  7. Request shape, after auth
#[RequiredParams('data')]         //  8. Required parameters
#[Sanitize(['data'])]             //  9. After the params are known to be present
#[Validate([...])]                // 10. Last, so it judges the sanitized value
```

`core/tests/module/AttributeOrderTest.php` enforces this (its `ORDER` constant
is the authority) for every module class and every endpoint, including a
virtual one dispatched through `__call()`. It reads
`Application::resolveAttributes()`, so it checks the sequence that actually
runs. It also fails when a file in `core/attribute/` is not in `ORDER`.

The order spans both levels. A class-level `#[RequireLogin]` above a
method-level `#[AllowWithToken]` reads correctly at each site and is wrong
combined: the Bearer caller is sent to the login page before the attribute that
would have authenticated it runs.

- **`AllowWithToken` before every gate.** It creates a session; the gates check
  one.
- **`AuditLog` after `AllowWithToken`.** It records `Session::userId()`, so
  before it every Bearer-authenticated request is logged with no user.
- **Auth before request shape.** An anonymous caller gets `401` rather than a
  `405` or `400` that confirms the endpoint exists and what it accepts.

## Typical endpoint

```php
use Zero\Core\Attribute\{AllowedMethods, RequiredParams, Sanitize, RequirePermission, AuditLog};

#[RequirePermission('allow.api')]                   // implies login
#[AuditLog('admin_action', includeParams: true)]
#[AllowedMethods('POST')]
#[RequiredParams(['action', 'data'])]
#[Sanitize(['action', 'user_id'])]
public function secureEndpoint() {}
```

## Gating is the attribute's job

An endpoint does not re-implement a gate in its body:

```php
// WRONG: a gate written by hand
public function save() {
    if (!Session::isLoggedIn()) { $this->error('Authentication required'); return; }
    …
}

// RIGHT
#[RequireLogin]
public function save() { … }
```

`Session::isLoggedIn()` is for an endpoint that is public and only behaves
differently when someone is signed in. If the branch ends in a redirect, an
error or an early `return`, it is a gate and belongs in an attribute. See
[RequireLogin.md](attributes/RequireLogin.md) and
[core/attribute/AUTH_GATE_CONSISTENCY.md](../attribute/AUTH_GATE_CONSISTENCY.md).

Checks that stay in the endpoint: business rules, and anything that depends on
the record being acted on.

## Creating an attribute

```php
namespace Zero\Core\Attribute;

use \Attribute;
use \Zero\Core\Exception\HTTPError;

#[Attribute]
class MyAttribute {
    public function __construct(private string $config) {}

    public function handler() {
        if (!$this->isValid()) {
            throw new HTTPError(403, 'Denied');
        }
        return true;
    }
}
```

- Namespace `Zero\Core\Attribute`, file in `core/attribute/`. An attribute in
  any other namespace is never run.
- A public `handler()` that throws `HTTPError` to refuse.
- A place in `AttributeOrderTest::ORDER`.
