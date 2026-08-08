# Zero Framework Tests

## Running Tests

```bash
# Run everything (Core + all Modules)
./vendor/bin/phpunit

# Run only Core tests
./vendor/bin/phpunit --testsuite Core

# Run only Model tests
./vendor/bin/phpunit --testsuite Model

# Run only Module tests
./vendor/bin/phpunit --testsuite Modules

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

## Structure

Tests live inside the repo they exercise, so each repo carries its own suite:

- `core/tests/` - Core framework tests (`ZeroTestCase` base class, Request, Console, Database, etc.)
- `model/tests/` - `Zero\Model\*` row-gateway tests
- `modules/*/tests/` - Module tests live inside each module's own folder

`ZeroTestCase` is shared by all of them and stays at `Zero\Tests\Core\ZeroTestCase`
regardless of which repo a test lives in — the namespace is mapped by prefix in
`composer.json`, not by directory nesting.

Placement follows the **subject under test**, not the collaborators. `UserEstablishTest`
lives in core because it tests `Zero\Core\User::establish()`, even though it builds a
`Zero\Model\User` row as a fixture; `TokenBrokerTest` likewise tests `Zero\Core\TokenBroker`
through `Zero\Entity\OAuthToken`.

Note that `phpunit.xml`, `composer.json` and `composer.lock` sit at the assembly root and
are tracked by **no** repo — a fresh clone of core alone has the test files but no runner
config or autoloader.

## Adding Tests for a New Module

1. Create a `tests/` folder inside your module directory
2. Add PSR-4 mappings to `composer.json`:
   - `autoload` section if the model directory is lowercase (e.g. `"Zero\\Module\\MyModule\\Model\\": "modules/MyModule/model/"`)
   - `autoload-dev` section for the test namespace (e.g. `"Zero\\Module\\MyModule\\Tests\\": "modules/MyModule/tests/"`)
3. Run `composer dump-autoload`
4. Create test classes extending `Zero\Tests\Core\ZeroTestCase` (or a module-specific base case)
5. The `Modules` test suite in `phpunit.xml` uses `modules/*/tests` and will pick up your tests automatically
