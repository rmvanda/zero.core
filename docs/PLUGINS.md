# Zero Framework Plugin System

## Overview

The plugin system provides a way to extend Zero's core functionality without modifying `Application.php`. Plugins hook into specific lifecycle points and are automatically discovered from the `plugin/` directory.

**Key Principles:**
- **Zero Configuration** - Drop a plugin file and it works
- **Zero Core Changes** - Add/remove plugins without touching Application.php
- **Zero Bloat** - Plugins are optional; removing one never breaks the framework
- **Convention-based** - Follows Zero's "convention over configuration" philosophy

**Architecture:**
- Plugins live in `zero/plugin/{Name}/{Name}.php`
- Each extends `\Zero\Plugin` abstract base class
- `PluginLoader` discovers and instantiates them automatically
- Hooks fire at 5 lifecycle points during request processing

## Lifecycle Hooks

Plugins can hook into these points in the request lifecycle:

| Hook | When It Fires | Available State | Common Uses |
|------|--------------|-----------------|-------------|
| `afterAutoloaders()` | After SPL + Composer autoloaders registered | Autoloaders only | Early initialization, timing |
| `afterParseRequest()` | After URL parsed into Request object | `Request::$Module`, `$endpoint`, `$args` | Request logging, routing |
| `afterConstants()` | After all .ini constants defined | All constants, `DEVMODE`, `MODULE_PATH` | Config-dependent setup, discovery |
| `beforeRun($module, $endpoint, $args)` | After module resolved, before instantiation | Module name (string), endpoint, args | Pre-execution hooks, caching |
| `afterRun($module, $endpoint, $args)` | After endpoint executed, before output flush | Everything + response generated | Output modification, logging |

**Execution Order:**
```
Application::__construct()
  ↓
Application::registerAutoloaders()
  ├→ SPL autoloaders registered
  ├→ Composer vendor/autoload.php loaded
  ├→ PluginLoader::load() - discovers all plugins
  └→ afterAutoloaders() hook fires
  ↓
Application::parseRequest()
  ├→ Request object populated with module/endpoint/args
  └→ afterParseRequest() hook fires
  ↓
Application::defineConstants()
  ├→ All .ini files processed
  ├→ DEVMODE set based on IP
  └→ afterConstants() hook fires
  ↓
Application::run($module, $endpoint, $args)
  ├→ Module class resolved
  ├→ beforeRun() hook fires
  ├→ Attributes checked (RequireLogin, etc.)
  ├→ Module instantiated
  ├→ Endpoint method called
  └→ afterRun() hook fires
  ↓
Application::__destruct()
  └→ Output buffer flushed to browser
```

## Available Plugins

| Plugin | Purpose | Hooks Used | Documentation |
|--------|---------|-----------|---------------|
| `SideNav` | Auto-discovers modules/views/submodules for navigation | `afterConstants()` | [plugin/SideNav/README.md](../../plugin/SideNav/README.md) |
| `DevToolbar` | Debug toolbar showing performance/request/session data | `afterAutoloaders()`, `beforeRun()`, `afterRun()` | [plugin/DevToolbar/README.md](../../plugin/DevToolbar/README.md) |

## Creating a Plugin

### Basic Plugin

Create `zero/plugin/MyPlugin/MyPlugin.php`:

```php
<?php

namespace Zero\Plugin;

use \Zero\Plugin as BasePlugin;
use \Zero\Core\Console;

class MyPlugin extends BasePlugin {

    /**
     * Priority: lower runs first (0-100, default 50)
     */
    protected int $priority = 50;

    /**
     * Hook example: runs after constants are loaded
     */
    public function afterConstants(): void {
        Console::debug("MyPlugin: Constants loaded, DEVMODE = " . (DEVMODE ? 'true' : 'false'));

        // Your plugin logic here
    }
}
```

That's it! The plugin will be automatically discovered and loaded.

### With Caching

Plugins have built-in cache helpers:

```php
public function afterConstants(): void {
    // Try cache first (1 hour TTL)
    $data = $this->cacheGet('mydata', 3600);

    if ($data === null) {
        // Cache miss - do expensive operation
        $data = $this->expensiveOperation();

        // Cache the result
        $this->cacheSet('mydata', $data);
    }

    // Use $data
}

private function expensiveOperation(): array {
    // Scan filesystem, query DB, etc.
    return ['result' => 'data'];
}
```

Cache files are stored at `storage/cache/plugin/{PluginName}/{key}.json` with automatic TTL expiration.

### Full Example: Request Logger

```php
<?php

namespace Zero\Plugin;

use \Zero\Plugin as BasePlugin;

class RequestLogger extends BasePlugin {

    protected int $priority = 20;
    private float $startTime;
    private string $logFile;

    public function afterAutoloaders(): void {
        $this->startTime = microtime(true);
        $this->logFile = ZERO_ROOT . 'storage/logs/requests.log';
    }

    public function beforeRun(string $module, string $endpoint, mixed $args): void {
        $entry = sprintf(
            "[%s] START %s::%s() from %s\n",
            date('Y-m-d H:i:s'),
            $module,
            $endpoint,
            $_SERVER['REMOTE_ADDR']
        );
        file_put_contents($this->logFile, $entry, FILE_APPEND);
    }

    public function afterRun(string $module, string $endpoint, mixed $args): void {
        $elapsed = round((microtime(true) - $this->startTime) * 1000, 2);
        $memory = round(memory_get_peak_usage() / 1024 / 1024, 2);

        $entry = sprintf(
            "[%s] END   %s::%s() [%sms, %sMB]\n",
            date('Y-m-d H:i:s'),
            $module,
            $endpoint,
            $elapsed,
            $memory
        );
        file_put_contents($this->logFile, $entry, FILE_APPEND);
    }
}
```

Drop this at `zero/plugin/RequestLogger/RequestLogger.php` and it immediately starts logging all requests.

## Plugin API Reference

### Base Class Properties

```php
protected int $priority = 50;        // Execution priority (0-100, lower = first)
public string $cacheDir = '';        // Auto-set by PluginLoader
```

### Lifecycle Hook Methods

All default to no-op (override only what you need):

```php
public function afterAutoloaders(): void {}
public function afterParseRequest(): void {}
public function afterConstants(): void {}
public function beforeRun(string $module, string $endpoint, mixed $args): void {}
public function afterRun(string $module, string $endpoint, mixed $args): void {}
```

### Cache Helper Methods

```php
// Read cache (returns null if missing or expired)
protected function cacheGet(string $key, int $ttl = 3600): mixed

// Write cache
protected function cacheSet(string $key, mixed $data): void

// Delete cache
protected function cacheClear(string $key): void

// Get plugin priority
public function getPriority(): int
```

## Priority and Ordering

Plugins run in priority order (lower = first). Ties are broken alphabetically by class name.

**Default priorities:**
- `0-20` - Early initialization (timers, logging setup)
- `20-40` - Request processing (routing, validation)
- `40-60` - Normal operations (default is 50)
- `60-80` - Late processing (navigation, UI)
- `80-100` - Output modification (injecting HTML, compression)

**Example:**
```php
// DevToolbar: priority 10 (runs early to capture start time)
// MyPlugin: priority 50 (default)
// SideNav: priority 70 (runs late, navigation not needed early)
```

## Accessing Framework State

Plugins can access framework state through existing mechanisms:

**Request Data:**
```php
use \Zero\Core\Request;

Request::$Module;    // "Test"
Request::$endpoint;  // "myMethod"
Request::$args;      // ["arg1", "arg2"]
Request::$method;    // "GET" or "POST"
```

**Constants:**
```php
ZERO_ROOT;          // Framework root path
MODULE_PATH;        // Path to modules directory
DEVMODE;            // true if on dev subnet
// ... all constants from .ini files
```

**Session:**
```php
$_SESSION['user_id'];   // users.id, or absent
// ... standard PHP session
```

Permissions and `auth.level` are not in the session. Ask the user object, which reads
through to the database:

```php
\Zero\Core\Session::can('allow.admin');       // bool, current user
\Zero\Core\Session::user()?->authLevel() ?? 0;       // int
```

The full session contract is in `core/modules/Auth/CLAUDE.md`.

**Database:**
```php
use \Zero\Core\Database;

$db = Database::getConnection();
$stmt = $db->prepare("SELECT ...");
```

**Logging:**
```php
use \Zero\Core\Console;

Console::debug("Debug message");
Console::info("Info message");
Console::warn("Warning message");
Console::error("Error message");
```

## Error Handling

Plugins are wrapped in try/catch to prevent breaking the framework:

```php
// In PluginLoader::hook()
try {
    $plugin->$hook(...$args);
} catch (\Throwable $e) {
    Console::error("Plugin {$name}::{$hook}() threw: " . $e->getMessage());
    // Framework continues running
}
```

**Result:** A broken plugin logs an error but never crashes the site.

## Best Practices

### DO:
- Keep plugins focused on a single concern
- Use caching for expensive operations
- Check for DEVMODE when appropriate
- Log errors with Console::error()
- Make plugins optional (no hard dependencies)
- Follow the Priority guidelines

### DON'T:
- Modify global state unexpectedly
- Perform slow operations on every request
- Assume other plugins are loaded
- Block requests (use attributes for that)
- Require manual configuration

### Plugin Pattern Examples

**Configuration-based:**
```php
public function afterConstants(): void {
    // Check if feature is enabled via constant/config
    if (!defined('ENABLE_MY_FEATURE') || !ENABLE_MY_FEATURE) {
        return;
    }

    // Feature logic here
}
```

**DEVMODE-only:**
```php
public function afterRun(...): void {
    if (!defined('DEVMODE') || !DEVMODE) {
        return;
    }

    // Dev-only logic here
}
```

**Conditional injection:**
```php
public function afterRun(...): void {
    // Only inject into HTML responses
    $isHtml = false;
    foreach (headers_list() as $header) {
        if (stripos($header, 'Content-Type: text/html') !== false) {
            $isHtml = true;
            break;
        }
    }

    if (!$isHtml) return;

    echo "<div>Injected content</div>";
}
```

## Disabling Plugins

### Temporary Disable

**Option 1:** Rename the plugin file
```bash
mv plugin/MyPlugin/MyPlugin.php plugin/MyPlugin/MyPlugin.php.disabled
```

**Option 2:** Rename the plugin directory
```bash
mv plugin/MyPlugin plugin/_MyPlugin
```

**Option 3:** Delete the plugin directory
```bash
rm -rf plugin/MyPlugin
```

**Option 4:** Add a disable check in the plugin
```php
public function afterAutoloaders(): void {
    if (file_exists(ZERO_ROOT . 'storage/myplugin.disabled')) {
        return;
    }
    // ... plugin logic
}
```

Then: `touch storage/myplugin.disabled` to disable.

### Permanent Removal

Simply delete the plugin directory:
```bash
rm -rf zero/plugin/MyPlugin
```

No other changes needed. The plugin system will skip it automatically.

## Troubleshooting

### Plugin Not Loading

**Check the log:**
```bash
tail -f /var/log/php-fpm/zero.log | grep "PluginLoader"
```

**Common issues:**
- File not named correctly (must be `{Name}/{Name}.php`)
- Class name doesn't match namespace (must be `\Zero\Plugin\{Name}`)
- PHP syntax error in plugin file
- Plugin directory not in `zero/plugin/`

### Plugin Loaded But Hook Not Firing

**Check:**
- Hook method signature matches base class exactly
- No type errors in parameters
- Plugin isn't returning early due to conditional logic
- Look for exceptions in the log

### Cache Not Working

**Verify:**
- `storage/cache/plugin/` directory exists and is writable
- Plugin's `$cacheDir` property is set (automatic)
- TTL hasn't expired
- In DEVMODE, some plugins may bypass cache intentionally

## Comparison: Plugins vs Attributes

**Use Plugins for:**
- Lifecycle-level concerns (timing, logging, discovery)
- Expensive operations that run once per request
- Output modification (injecting HTML, headers)
- Framework-wide features
- Background tasks

**Use Attributes for:**
- Per-endpoint concerns (auth, validation)
- Request filtering and early termination
- Method-level decorators
- Security enforcement
- Parameter sanitization

**Example:**
- ✅ Plugin: "Log all requests to a file"
- ✅ Attribute: "Require login for this endpoint"
- ✅ Plugin: "Auto-discover modules for navigation"
- ✅ Attribute: "Validate POST parameters before execution"

## Future Plugin Ideas

- **Metrics** - Track request times, memory usage, query counts
- **CookieBanner** - Inject GDPR cookie consent banner
- **Maintenance** - Check maintenance flag, show maintenance page
- **CORS** - Set CORS headers for API endpoints
- **Compression** - Gzip compress output
- **RateLimiter** - Limit requests by IP/user
- **Localization** - Load language files based on user preference
- **AssetOptimizer** - Minify/combine CSS/JS on the fly

Each can be added without touching the core framework.

## See Also

- [ATTRIBUTES.md](ATTRIBUTES.md) - PHP 8 Attributes system
- [plugin/SideNav/README.md](../../plugin/SideNav/README.md) - SideNav plugin documentation
- [plugin/DevToolbar/README.md](../../plugin/DevToolbar/README.md) - DevToolbar plugin documentation
- [MODULE_PHILOSOPHY.md](MODULE_PHILOSOPHY.md) - Module design principles
