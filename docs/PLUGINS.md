# Zero Framework Plugin System

A plugin runs code at fixed points of every request without a change to `Application.php`. It is
a class in the site's `plugin/` folder; there is no registry and no configuration.

- A plugin is `plugin/{Name}/{Name}.php`, declaring `\Zero\Plugin\{Name}`, which extends the
  abstract base class `\Zero\Plugin`.
- The base class is `plugin/Plugin.php`. It ships with the plugin folder, not with core. A site
  with no `plugin/` folder loads no plugins.
- `Zero\Core\PluginLoader` (`core/PluginLoader.php`) finds the plugins and calls their hooks.

## Lifecycle Hooks

There are five hooks. `Application` fires each with `PluginLoader::hook()`.

| Hook | Fires | Available |
|------|-------|-----------|
| `afterAutoloaders()` | End of `registerAutoloaders()`, right after the plugins are loaded | Autoloaders (core's and Composer's), `ZERO_ROOT`, `MODULE_PATH`, the session |
| `afterParseRequest()` | End of `parseRequest()` | `Request::$module`, `$Module`, `$endpoint`, `$args`, `$method`, ... |
| `afterConstants()` | End of `defineConstants()` | Every `.ini` constant, `DEVMODE` |
| `beforeRun($module, $endpoint, $args)` | In `run()`, after the module class is resolved, before it is constructed | The three arguments `run()` was given: the module and endpoint as the URL named them, and the remaining URL segments |
| `afterRun($module, $endpoint, $args)` | End of `run()`, after the endpoint returned or its `HTTPError` was rendered | The response in the output buffer, not yet sent |

Order within a request, as the site's `index.php` calls it:

```
new Application()                 output buffering on, session started
registerAutoloaders()
  ├ autoloaders registered, Composer's vendor/autoload.php loaded
  ├ PluginLoader::load()
  └ afterAutoloaders
parseRequest()
  ├ new Request()
  └ afterParseRequest
defineConstants()
  ├ .ini files read, DEVMODE set
  └ afterConstants
run($module, $endpoint, $args)
  ├ ban check, module check
  ├ module class resolved
  ├ beforeRun
  ├ module constructed
  ├ attributes checked (RequireLogin, ...)
  ├ endpoint called
  └ afterRun
Application::__destruct()         output buffer flushed
```

`afterRun` does not fire when the endpoint ends the request itself (`exit` after a redirect, for
example), or when `run()` stops at its module check.

## Loading

`PluginLoader::load()` runs once per request:

1. Returns no plugins when `ZERO_ROOT/plugin/` is not a directory.
2. Requires `plugin/Plugin.php`.
3. For each subfolder `{Name}` that holds `{Name}.php`: requires the file and constructs
   `\Zero\Plugin\{Name}`. A subfolder without that file is skipped silently.
4. Sets the plugin's `$cacheDir` to `storage/cache/plugin/{Name}/`.
5. Sorts by priority, lower first, then by class name.

Two mistakes are logged with `Console::warn()` and the plugin is left out:

- `Plugin file {file} exists but class {class} not found`
- `Plugin {class} does not extend \Zero\Plugin base class`

## Errors

`PluginLoader::hook()` catches anything a hook throws, logs
`Plugin {class}::{hook}() threw: {message}` with `Console::error()` and goes on to the next
plugin. A hook that throws never breaks the request.

Loading has no such guard: a syntax error in a plugin's file, or a constructor that throws, breaks
every request.

## Plugins in the plugin folder

| Plugin | Purpose | Priority | Hooks | Documentation |
|--------|---------|----------|-------|---------------|
| `DevToolbar` | Debug toolbar (performance, request, session), `DEVMODE` only | 10 | `afterAutoloaders`, `afterConstants`, `beforeRun`, `afterRun` | [plugin/DevToolbar/README.md](../../plugin/DevToolbar/README.md) |
| `SideNav` | Discovers modules, views and submodules for navigation | 70 | `afterConstants` | [plugin/SideNav/README.md](../../plugin/SideNav/README.md) |

## Creating a Plugin

`plugin/MyPlugin/MyPlugin.php`:

```php
<?php

namespace Zero\Plugin;

use \Zero\Plugin as BasePlugin;
use \Zero\Core\Console;

class MyPlugin extends BasePlugin {

    protected int $priority = 50;

    public function afterConstants(): void {
        Console::debug("MyPlugin: DEVMODE = " . (DEVMODE ? 'true' : 'false'));
    }
}
```

It is loaded on the next request.

### With caching

```php
public function afterConstants(): void {
    $data = $this->cacheGet('mydata', 3600);      // null when missing or older than an hour

    if ($data === null) {
        $data = $this->expensiveOperation();      // scan the filesystem, query the database
        $this->cacheSet('mydata', $data);
    }
}
```

A cache entry is the file `storage/cache/plugin/{PluginName}/{key}.json`. Its age is the file's
modification time; `cacheGet()` deletes an entry that is older than `$ttl` or is not valid JSON
and returns `null`. `cacheSet()` creates the folder when it is missing, so `storage/cache/` must
be writable by the web server user.

### Example: request logger

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
            \Zero\Core\Request::clientIp()
        );
        file_put_contents($this->logFile, $entry, FILE_APPEND);
    }

    public function afterRun(string $module, string $endpoint, mixed $args): void {
        $entry = sprintf(
            "[%s] END   %s::%s() [%sms, %sMB]\n",
            date('Y-m-d H:i:s'),
            $module,
            $endpoint,
            round((microtime(true) - $this->startTime) * 1000, 2),
            round(memory_get_peak_usage() / 1024 / 1024, 2)
        );
        file_put_contents($this->logFile, $entry, FILE_APPEND);
    }
}
```

## Base Class Reference

```php
protected int $priority = 50;        // 0-100, lower runs first
public string $cacheDir = '';        // set by PluginLoader

// Hooks. All are no-ops; override the ones you need, with exactly these signatures.
public function afterAutoloaders(): void {}
public function afterParseRequest(): void {}
public function afterConstants(): void {}
public function beforeRun(string $module, string $endpoint, mixed $args): void {}
public function afterRun(string $module, string $endpoint, mixed $args): void {}

protected function cacheGet(string $key, int $ttl = 3600): mixed   // null if missing or expired
protected function cacheSet(string $key, mixed $data): void        // $data must be JSON-serializable
protected function cacheClear(string $key): void
public function getPriority(): int
```

## Priority

Every hook runs the plugins in priority order, lower first; ties go by class name. A guide:

- `0-20`: early setup (timers, logging). DevToolbar is 10, to capture the start time.
- `20-40`: request processing
- `40-60`: ordinary work. The default is 50.
- `60-80`: late work (navigation, UI). SideNav is 70.
- `80-100`: output modification

## Framework State in a Hook

```php
use \Zero\Core\{Request, Session, Database, Console};

Request::$Module;  Request::$endpoint;  Request::$args;  Request::$method;   // from afterParseRequest on

ZERO_ROOT;  MODULE_PATH;          // always
DEVMODE;                          // from afterConstants on, with every .ini constant

Session::userId();                // ?int
Session::can('allow.admin');      // bool, reads the database
Session::user()?->authLevel() ?? 0;

$db = Database::getConnection();

Console::debug("...");  Console::info("...");  Console::warn("...");  Console::error("...");
```

Capabilities and `auth.level` are not in `$_SESSION`; ask `Session`. See `core/PERMISSIONS.md`,
and `core/modules/Auth/CLAUDE.md` for what the session holds.

## Patterns

```php
// Only when a feature is switched on by a constant
public function afterConstants(): void {
    if (!defined('ENABLE_MY_FEATURE') || !ENABLE_MY_FEATURE) {
        return;
    }
    // ...
}

// Only in DEVMODE
public function afterRun(string $module, string $endpoint, mixed $args): void {
    if (!defined('DEVMODE') || !DEVMODE) {
        return;
    }
    // ...
}

// Add to HTML responses only
public function afterRun(string $module, string $endpoint, mixed $args): void {
    foreach (headers_list() as $header) {
        if (stripos($header, 'Content-Type: text/html') !== false) {
            echo "<div>Injected content</div>";
            return;
        }
    }
}
```

Guidelines:

- One concern per plugin, and no dependence on another plugin being loaded.
- A hook runs on every request: cache anything expensive.
- A plugin does not block a request. That is an attribute's job (below).

## Disabling and Removing

The loader only sees `{Name}/{Name}.php`, so any of these takes a plugin out:

```bash
mv plugin/MyPlugin/MyPlugin.php plugin/MyPlugin/MyPlugin.php.disabled   # disable
mv plugin/MyPlugin/MyPlugin.php.disabled plugin/MyPlugin/MyPlugin.php   # enable
rm -rf plugin/MyPlugin                                                  # remove
```

The `.php.disabled` rename is what the Plugins module's admin screen (`/admin/plugins`, a site
module) does; it also clears the plugin's cache folder.

## Troubleshooting

Log lines go through `Console` (by default `/var/log/php-fpm/zero.log`):

```bash
tail -f /var/log/php-fpm/zero.log | grep "Plugin"
```

**Not loading:**
- The file is not `plugin/{Name}/{Name}.php`. Nothing is logged for a folder without it.
- The class is not `\Zero\Plugin\{Name}`, or does not extend `\Zero\Plugin` (both are logged).

**Loaded, but a hook does nothing:**
- The method name or signature differs from the base class.
- The hook returns early on its own condition.
- The hook threw: look for `threw:` in the log.

**Cache not working:**
- `storage/cache/plugin/{Name}/` cannot be created or written.
- The TTL passed to `cacheGet()` has expired.
- The plugin skips its cache on purpose. SideNav does in `DEVMODE`.

## Plugins or Attributes

| Use a plugin for | Use an attribute for |
|------------------|----------------------|
| Something on every request: timing, logging, discovery | One endpoint or one module: auth, validation, sanitizing |
| Adding to the output | Refusing or ending a request |

"Log every request" is a plugin. "Require login for this endpoint" is an attribute. See
[ATTRIBUTES.md](ATTRIBUTES.md).
