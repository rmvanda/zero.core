# zero.core
The core components of the Zero Framework

This bit is basically just a front controller that provides reasonable defaults for your web application.

The heart of this is to get people coding modularly and writing object oriented.
If you work with this framework, you will be able to see the benefits of this style of coding.
Also, at the heart of this framework is a burning hatred for other frameworks that are more "kitchen-sink" than "frame"

Zero Routing
Zero Configuration
Zero Bloat

# How to use it

A site is a folder with core at `core/`. [skeleton/README.md](skeleton/README.md) builds one
from nothing: `zero-init.sh` lays out the folder, [sql/core.sql](sql/README.md) creates the
tables.

The web root's `index.php` defines four constants, includes `Application.php` and calls four
methods in order. `skeleton/frontend/www/index.php` is the reference:

```php
define("ZERO_ROOT", dirname(__DIR__, 3) . "/");     // the folder that holds core/
define("ROOT_PATH", dirname(ZERO_ROOT) . "/");      // its parent
define("VIEW_PATH", ZERO_ROOT . "app/frontend/frame/");  // head.php, header.php, sideNav.php, footer.php
define("MODULE_PATH", ZERO_ROOT . "modules/");      // where your code lives

require ZERO_ROOT . "core/Application.php";

$app = new Zero\Core\Application();

$app->registerAutoloaders();
$app->parseRequest();
$app->defineConstants();

$app->run(
    \Zero\Core\Request::$module,
    \Zero\Core\Request::$endpoint,
    \Zero\Core\Request::$args
);
```

The methods are called externally so it stays obvious what the class does:

1. **Registers autoloaders.** Its own, which maps a `Zero\` namespace to a folder, then any
   you pass, then Composer's `vendor/autoload.php` (looked for in `ROOT_PATH` and `ZERO_ROOT`).
   Plugins are loaded at the end of this step.
2. **Parses the request.** Instantiates `Request`, which gives static access to the parts of
   `$protocol://$sub.$domain.$tld/$module/$endpoint/$arg1/$arg2/$arg3`.
3. **Defines constants** from `.ini` files: the site's, then the ones in the folder of the
   module being called. A module's constants exist before its class is loaded, simply by
   putting a `.ini` file into the module's folder.
4. **Runs.** Puts all the above into motion to create a Response that suits the Request.

So for

    domain.com/class/method/arg1/arg2/argX/

`Application` loads `Class` and calls `$module->method("arg1", "arg2", "argX")`.

For security reasons, it will only load "Class" if it is a module: a folder in `MODULE_PATH`
or in `core/modules/`. If the class also extends `\Zero\Core\Module`, it is armed with
Reasonable Defaults ™ - such as where to load the header & footer from
and what to do if the `method` called does not exist (look for a view of that name, then
throw a 404 page, automatically).

Code that does not run through `index.php` (a command-line script, a cron job, a test)
includes `core/autoload.php` and loads classes by the same rule.

[docs/ZERO.md](docs/ZERO.md) is the reference for all of this: the request flow, class
loading, how a module is found and switched off, configuration, wording, assets.

# What a site installs

`composer.json` lists the Composer packages core uses and does not bring with it:
`minishlink/web-push` (the Push library) and, suggested, `google/apiclient` (the Auth
module's Google sign-in). A site requires them in its own `composer.json`. Nothing reads
core's `composer.json` at runtime; it documents what to install.

# What is here

## The front controller

| File | What it is |
|---|---|
| `Application.php` | The front controller: autoloaders, constants, the ban check, loading the module, running the attribute gates and the endpoint, output buffering. |
| `autoload.php` | The same class loading for scripts, cron jobs and the test bootstrap. |
| `Request.php` | The request, parsed once into static properties: module, endpoint, args, protocol, subdomain, whether the caller wants JSON, whether it came from AJAX. Also `clientIp()` and `redirect()`. |
| `Response.php` | Decides what kind of response to give. Wraps a view in head, header, sideNav and footer and loads the module's css and scripts; answers JSON when the client asks for it; leaves the frame off for AJAX. Finds a view or a standalone page when the method does not exist, and renders error pages. |
| `Module.php` | Response under the name your code extends. Semantics! Think Modular! *Be* Modular. Also links the module's public assets and resolves submodules. |
| `AdminResponse.php` | Base class for admin controllers. A Module subclass whose assets are inlined, never web-accessible. See [ADMIN_ARCHITECTURE.md](docs/ADMIN_ARCHITECTURE.md) and [ADMIN_ASSETS.md](docs/ADMIN_ASSETS.md). |
| `Modules.php`, `ModulesIni.php` | Where a module is and whether it is on; the one writer of `app/modules.ini`. |
| `Strings.php` | Wording as constants, from `strings/*.ini`. |
| `exception/` | The exceptions core throws (`Zero\Core\Exception`): `HTTPError`, `StaleFileException`, `FileNotWritableException`. `throw new HTTPError(404, $detail)` anywhere in a request renders the error page in the module's own frame; a JSON client gets `HTTPError::toArray()`. |
| `attribute/` | The declarative gates: `RequireLogin`, `RequirePermission`, `AllowWithToken`, `AllowedMethods`, `RequiredParams`, `Sanitize`, `Validate`, `RestrictIP`, `AuditLog`, and the shelved `RequireAuthLevel`. See [docs/ATTRIBUTES.md](docs/ATTRIBUTES.md). |

## Data and who may do what

| File | What it is |
|---|---|
| `Database.php` | PDO connection management. Its `TrackedStatement` times and records every query when query tracking is switched on, so the DevToolbar can show what a page actually ran. |
| `Model.php` | A thin typed row gateway over a real table. Deliberately not an ORM and deliberately not `Zero\Entity`: for models that want a real schema with real constraints. |
| `entity/` | The entity framework (`Zero\Entity`), a git submodule. See [entity/README.md](entity/README.md). |
| `Session.php` | Who is making this request: `userId()`, `user()`, `isLoggedIn()`, `can()`. `establish()` is the single writer of the login session and `end()` the single teardown. See `modules/Auth/CLAUDE.md` for the session contract. |
| `access/` | Users, groups and permissions (`Zero\Core\Access`): `User`, `Group`, `Permission`, the `Holder` interface and the `HoldsCapabilities` trait. The only code that reads or writes the `user_group`, `user_group_member` and `permission` tables. See [PERMISSIONS.md](docs/PERMISSIONS.md). |
| `sql/` | `core.sql`, the schema for an empty database, and older one-off scripts. See [sql/README.md](sql/README.md). |

## Everything else

| Path | What it is |
|---|---|
| `modules/` | The modules every site gets: `Auth`, `User`, `Admin`, `AccessRequest`, `Sitemap`, `Push`, `Entity`, and the examples `Module`, `HelloWorld`, `Test`. `modules/Module/README` is the reference module. |
| `lib/` | Libraries any site can use (`Zero\Lib`): `Crypto` (AES-256-GCM at-rest encryption), `Net\Cidr` (IP and CIDR matching), `Net\TrustedProxy` (which peers may say who the real client is), `Http` (`Client`, `Response`, `ExternalRequest`), `Mail`, `CloudFlare`. |
| `Console.php` | Threshold-based logging. `Console::warn()`, `Console::debug()` and friends via `__callStatic`; messages are also retrievable in-process for the DevToolbar. |
| `PluginLoader.php` | Discovers the plugins in the site's `plugin/` folder and fires their hooks. See [docs/PLUGINS.md](docs/PLUGINS.md). |
| `skeleton/` | The template for a new site, and the defaults core falls back to. See [skeleton/README.md](skeleton/README.md). |
| `tests/` | PHPUnit suite for core. See [tests/README.md](tests/README.md). |
| `docs/` | Framework documentation. |
| `bin/` | Maintenance scripts (`refresh-cloudflare-ips.php`, `log_monitor.sh`). |
| `deprecated/` | `Error.php` and `SQLAdapter.php`. Nothing loads them. `SQLAdapter` extends an `Adapter` class that does not exist in this repo. |
| `archive/` | Superseded design notes. History, not documentation. |

# Where to read next

| Doc | For |
|---|---|
| [docs/ZERO.md](docs/ZERO.md) | How the framework works: the reference |
| [modules/Module/README](modules/Module/README) | Writing a module; `mkmod` scaffolds one |
| [docs/MODULE_PHILOSOPHY.md](docs/MODULE_PHILOSOPHY.md) | What makes a module worth writing |
| [docs/ATTRIBUTES.md](docs/ATTRIBUTES.md) | The attribute gates |
| [PERMISSIONS.md](docs/PERMISSIONS.md) | Session, users, groups, capabilities, sharing |
| [ADMIN_ARCHITECTURE.md](docs/ADMIN_ARCHITECTURE.md), [ADMIN_ASSETS.md](docs/ADMIN_ASSETS.md) | Admin screens |
| [docs/PLUGINS.md](docs/PLUGINS.md) | Plugins and their hooks |
| [docs/PUSH_NOTIFICATIONS.md](docs/PUSH_NOTIFICATIONS.md) | Web push |
| [docs/DEPRECATING_CHANGES.md](docs/DEPRECATING_CHANGES.md) | What was removed or replaced, and what to use instead |

# TODO

- Learn MarkDown and make this file prettier
- Better access control
- Subdomain support
- Delete `deprecated/SQLAdapter.php` or restore the `Adapter` base class it needs
