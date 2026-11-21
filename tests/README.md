# Zero Framework Tests

## Running Tests

Run from the site root (the folder that holds `core/`, `phpunit.xml` and `vendor/`).

```bash
# Run everything
./vendor/bin/phpunit

# Run one suite: Core, Acceptance, Modules or Aux
./vendor/bin/phpunit --testsuite Core

# Run a single module's tests
./vendor/bin/phpunit modules/Lists/tests/

# Run a single test file
./vendor/bin/phpunit modules/Lists/tests/ListModelTest.php

# Run a single test method
./vendor/bin/phpunit --filter testOwnerAlwaysHasPermission

# Verbose output (shows each test name)
./vendor/bin/phpunit --testdox

# Show warnings/notices if any appear
./vendor/bin/phpunit --display-warnings --display-notices
```

| Suite | Directories |
|---|---|
| `Core` | `core/tests/`, without `acceptance/` |
| `Acceptance` | `core/tests/acceptance/` (see its `README.md`) |
| `Modules` | `modules/*/tests/` and `core/modules/*/tests/` |
| `Aux` | `aux/*/tests/` |

## Structure

Tests live inside the repo they exercise, so each repo carries its own suite:

- `core/tests/` - Core framework tests: `ZeroTestCase` and the other shared test classes, and the tests of the classes in `core/`
- `core/tests/Attribute/` - one test per attribute
- `core/tests/module/` - tests that check every module, walking `Modules::all()`
- `core/tests/acceptance/` - a bare site built from the skeleton, end to end
- `modules/*/tests/`, `core/modules/*/tests/` - Module tests live inside each module's own folder

Placement follows the **subject under test**, not the collaborators. `SessionEstablishTest`
lives in core because it tests `Zero\Core\Session::establish()`, even though it builds its
fixture row via `Zero\Core\Access\User::create()`; `OAuthBrokerTest` likewise tests
`Zero\Module\Auth\OAuth\Broker` through `Zero\Module\Auth\OAuth\Token`, and lives in the Auth module.

## How classes load

`phpunit.xml` names `core/tests/bootstrap.php` as its bootstrap. The bootstrap includes
`core/autoload.php`, so a test loads a class of core or of a module by the rule a request
uses (`Application::classFile()`, see `core/docs/ZERO.md`, "Class loading"). A test names the
class in a `use` line; it needs no `require_once` and no entry in `composer.json`.

The bootstrap adds two rules, for tests only:

- `Zero\Tests\Core\{Class}` is `core/tests/{Class}.php`, wherever the test that uses it lives.
- A test that points `Modules` at throwaway folders still finds the classes of the real
  modules: they are looked for in `modules/` and `core/modules/` directly.
  `zeroTestConfigureModules()` points `Modules` back at the real tree.

`phpunit.xml`, `composer.json` and `vendor/` belong to the site, not to core: a clone of core
alone has the test files but no runner.

## Adding Tests for a New Module

1. Create a `tests/` folder inside your module directory
2. Give the test classes the namespace `Zero\Module\{Module}\Tests`
3. Extend `Zero\Tests\Core\ZeroTestCase` (or a module-specific base case)

The `Modules` suite picks them up; there is nothing to register.
