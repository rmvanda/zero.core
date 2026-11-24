# Zero Framework Internals

How the Zero Framework works under the hood. For what is in the repository and how a site starts, see `core/README.md`. For building modules, use the `/zeromodule` skill — it covers URL routing, response methods, assets, submodules, and attribute usage.

"Zero Routing, Zero Configuration, Zero Bloat" — URLs map to classes and methods without configuration files.

## Core Classes

| Class | Purpose |
|-------|---------|
| `\Zero\Core\Application` | Front controller — output buffering, session start, autoloading, constants, routing |
| `\Zero\Core\Request` | Parses URL into module/endpoint/args |
| `\Zero\Core\Response` | View rendering, JSON/HTML response handling |
| `\Zero\Core\Module` | Base class for modules (extends Response) |
| `\Zero\Core\Modules` | Where a module is and whether it is on |
| `\Zero\Core\Strings` | Wording as constants |
| `\Zero\Core\Exception\HTTPError` | HTTP error exception — `throw new HTTPError(404)` |
| `\Zero\Core\Database` | PDO connection management |
| `\Zero\Core\Console` | Logging |

## Request Flow

`index.php` defines `ZERO_ROOT`, `ROOT_PATH`, `VIEW_PATH` and `MODULE_PATH` and calls the four `Application` methods (`core/README.md` shows the file).

1. `new Application()` starts output buffering and the PHP session. A session older than `SESSION_REGENERATE_INTERVAL` seconds (when that constant is defined) gets a new session id.
2. `registerAutoloaders()` configures `Modules`, registers the class loading described below and loads the plugins.
3. `parseRequest()` builds `Request` from the URL: `/module/endpoint/arg1/arg2` → module, endpoint, args array. Module and endpoint are lowercased, and kebab-case becomes camelCase (`/user-profile/edit` → `UserProfile::edit()`). An empty path is `index/index`; a path of one segment gets the endpoint `index`.
4. `defineConstants()` reads the strings and config files (see Configuration System).
5. `run()` begins with `banNefariousRequest()` and the check on the module folders (see How a module is found).
6. `run()` loads the controller of the module `Modules` names, when it exists and is on. With no such module the request is served by a plain `Response`, with `Index` loaded for step 10.
7. The module is instantiated and its attributes are checked via `Application::checkAttributes()`.
8. If the method exists: `$module->{$endpoint}(...$args)`. The URL segments are separate arguments, so an endpoint declares named parameters, not a single `$args` array. Extra segments are ignored; a missing required one raises `ArgumentCountError`, which `run()` returns as HTTP 400.
9. If the method is missing, `Module::__call()` checks for a submodule at `{Module}/submodule/{Endpoint}/{Endpoint}.php`.
10. With no submodule, `Response::__call()` takes the first of:
    1. a view file in the requested module's own `view/` folder, `{Module}/view/{endpoint}.php`;
    2. a public method of the Index module named after the first URL segment;
    3. a view file `Index/view/{segment}.php` named after that same segment;
    4. `HTTPError(404)`.
11. Response wraps the view with a frame. Each piece (`head`, `header`, `sideNav`, `footer`) is the first of: the module's `frame/`, the module's `view/`, the site's `app/frontend/frame/` (`VIEW_PATH`), then core's own copy in `core/skeleton/frontend/frame/`. A submodule without a `frame/` uses its parent's. A JSON client gets no frame and no view; an AJAX request gets the view without the frame.

### A public method is an endpoint

Step 8 calls the method from outside the module object, so PHP runs any **public** method the URL names: one the module declares, and one it inherits. A protected or private method is not reached that way; PHP gives its name to `__call()` (steps 9 and 10), which finds a view, a submodule or a 404.

- Declare a method `public` only when a URL may call it. Every helper is `protected` or `private`, static helpers included.
- The helpers of `Response` and `Module` are protected for this reason: `getAuxiliary()`, `framePiecePath()`, `framePieceFile()`, `findModuleView()`, `linkAssets()`, `respond()`, `build()`, `export()`. A frame or a view calls them as `$this->...`: it is included from inside the object, so a protected method is in reach.
- `renderError()` is public, because `Application::run()` calls it.
- An admin offering has a stricter rule of its own (`AdminResponse::isRoutableMethod()`).

Plugin hooks fire along the way (`afterAutoloaders`, `afterParseRequest`, `afterConstants`, `beforeRun`, `afterRun`). See `core/docs/PLUGINS.md`.

### Standalone pages

2 and 3 of step 10 are the standalone page: a URL like `/about` with no module behind it.

- 2 beats 3 on purpose. If Index has both an `about()` method and an `about.php` view, the method is the page and the view is only its template. Building the template on its own renders it with none of the data the method would have fetched, and the result looks like a page that worked.
- 1 beats 2, so a real module's own view is never hijacked by a same-named page in Index.
- Only public, non-static methods Index itself declares are routable this way. Eligibility is reflection, not `method_exists()`: the latter also matches everything inherited from Module and Response, which would put `/renderError` and `/getAuxiliary` on the web, and PHP's method lookup is case-insensitive so the URL would not even have to match.
- The method goes through `Application::checkAttributes()` like any other endpoint and is called with no arguments.
- A standalone page's assets are Index's: `/about` loads `about.css` from the Index module.

## What a site may leave out

- Frame pieces (`head`, `header`, `sideNav`, `footer`) and the partials views load through `Response::framePiecePath()` (`_head-scripts`, `_oauth-brand`, `_oauth-buttons`, `_google-signin`): looked up as in step 11, ending at `core/skeleton/frontend/frame/`. A site overrides a piece by having the file.
- Config files: `Application::defineConstants()` reads every `app/config/*.ini`, then each `.ini` in `core/skeleton/config/` (`constants.ini`, `error_codes.ini`) the site has no file of the same name for (`Application::configFiles()`), so no constant is defined twice.
- Wording: `core/skeleton/strings/*.ini` defines every key the site left undefined (see Wording).

`core/skeleton/config/` holds defaults core itself reads; `core/skeleton/config-templates/` holds blank templates a new site copies and fills in.

## Error Handling

`throw new HTTPError($code, $detail?)`. `Application::run()` catches it and calls `renderError()` on the already-instantiated module, so the error page renders with that module's frame and paths.

- An HTML request gets the module's `view/error{code}.php` when it has one, otherwise a generic page that shows the detail.
- A JSON client gets `HTTPError::toArray()`: the reason phrase in `message` and the detail in `detail`.
- The error is logged through `Console`: `error` for a 5xx, `warn` for anything else.

## Class loading

`Application::classFile()` is the one rule that says which file a class is in. The map, in order:

| Namespace | Folder |
|---|---|
| `Zero\Core\Attribute\` | `core/attribute/` |
| `Zero\Core\` | `core/` |
| `Zero\Lib\` | `core/lib/` |
| `Zero\Entity\` | `core/entity/`, then the site's `entity/` (or `ENTITY_SITE_DIR`) |
| `Zero\Aux\` | `aux/` |
| `Zero\App\Lib\` | `app/lib/` |
| `Zero\Core\Module\{Name}\` | core's module of that name, `core/modules/{Name}/` (`Modules::corePath()`) |
| `Zero\Module\{Name}\` | the site's module of that name, `modules/{Name}/` (`Modules::sitePath()`) |

- Each following namespace segment is a folder, tried as written, then (module classes only) as a submodule (`submodule/{Segment}/`), then lowercased (`Model` is `model/`, `Lib` is `lib/`, `Access` is `access/`).
- Every segment must be a PHP identifier and the file must lie inside the folder its rule names.
- Only the segments are mapped, so a class name containing `Model` or `Lib` loads like any other.
- The root is the folder that holds `core/`; the site folder may carry any name.
- The entity framework (`Entity`, `GenericEntity`, `Event`) is in `core/entity/`; a site's own and generated entity classes live in its `entity/`. A site class named like a framework class is not loaded. See `core/entity/README.md`.

A request loads through `Application::autoloader()`, which logs a warning for a `Zero\` class it cannot place. `registerAutoloaders()` registers it, then the autoloader or list of autoloaders passed as its argument, then Composer's `vendor/autoload.php` from `ROOT_PATH` and from `ZERO_ROOT`. Composer loads third-party packages only: no class of the framework or of a module needs an entry in `composer.json`.

Code that does not run through `index.php` (a command-line script, a cron job, the test bootstrap) includes `core/autoload.php`, which registers the same rule, points `Modules` at the site's `modules/` and `core/modules/`, and includes `vendor/autoload.php` when the site has one:

```php
require __DIR__ . '/../../core/autoload.php';
```

A module's models (`modules/{Module}/model/`, namespace `Zero\Module\{Module}\Model`) need no `require`. `Response::registerAutoloaders()` also loads any class whose namespace contains `\Model\` from the `model/` folder of the module serving the request.

## Sharing logic between modules: `lib/`

Logic that another module uses lives in the providing module's `lib/` folder:
`modules/{Name}/lib/{Class}.php`, namespace `Zero\Module\{Name}\Lib`. Other modules call the
provider's `lib/`, never its `model/`, so the provider's rules apply wherever its data changes.
Example: `Zero\Module\Lists\Lib\ListService`, which Lists' own endpoints, Notebook and Index0 use.
In core, `Zero\Core\Module\Push\Lib\PushHelper` is how any module sends a push notification.
Other examples are `Zero\Module\Llm\Lib\OllamaClient`, `Zero\Module\Comfy\Lib\ComfyUIClient` and
`Zero\Module\ApiConfig\Lib\ServiceClient`.

The libraries that belong to no module are in `core/lib/`: `Zero\Lib\Http`, `Zero\Lib\Mail`,
`Zero\Lib\CloudFlare`, `Zero\Lib\Net` (`Cidr`, `TrustedProxy`) and the class `Zero\Lib\Crypto`.

- A `lib/` class takes the acting user and its dependencies as arguments rather than reading the
  session. For a Bearer-token caller (`#[AllowWithToken]`) the session exists only after the
  endpoint's attributes run, so a module builds a `lib/` object when an endpoint first needs it,
  not in its constructor.
- **Groups and permissions come from core.** A module calls `Zero\Core\Access\Group` and
  `Zero\Core\Access\Permission` (`Group::forUser($userId)`, `$perm->can(...)`, `$perm->sqlCan(...)`
  for a listing) and never writes SQL against their tables. See Permission System.

## Sending mail

Every message goes through `Zero\Lib\Mail\Mail`. A module never calls PHP's `mail()`.

```php
use \Zero\Lib\Mail\Mail;

Mail::sendEmailTo($to, $subject, $message);                  // from the site's own address
Mail::sendEmailTo($to, $subject, $message, null, $replyTo);  // with a Reply-To
Mail::sendEmailToAdmin($subject, $message);                  // to NOTIFY_EMAIL
```

`Mail` does four things a direct `mail()` call does not:

- It sends from `Mail::sender()`: `MAIL_FROM`, or `noreply@` + `PRIMARY_DOMAIN` when that is blank.
- It sets the HTML content type when the message is HTML.
- It records each message as a `Mail Sent` event (`Zero\Entity\Event`): recipient, subject, sender,
  length and whether the send succeeded. The body is not kept.
- It obeys `MAIL_CAPTURE_DIR`: when set, the message is written to a file there and not sent.

## How a module is found

`Zero\Core\Modules` answers every question about where a module is and whether it is on.

- **Roots.** `Application::registerAutoloaders()` hands it the module roots in priority order, the site's `modules/` folder (`MODULE_PATH`) first and `core/modules/` second, and the path of `app/modules.ini`.
- **Names.** A directory in a root is a module when its name matches `^[A-Z][A-Za-z0-9]*$`. A folder such as `Links (old)` or `Evil&Co` is not one: the router never serves it and `app/modules.ini` cannot hold it.
- **Paths.** Core never builds a module path from `MODULE_PATH`: it asks `Modules::path()`, which accepts any spelling of a name (`ApiConfig`, `apiconfig`, `api-config`), and `Modules::name()` for the real directory name.
- **Namespaces.** A module of core is a class of `Zero\Core\Module\` (`Zero\Core\Module\Auth`, `Zero\Core\Module\Auth\OAuth\Token`); a module of the site is a class of `Zero\Module\` (`Zero\Module\Links`). `Modules::controllerClass()` gives the class that answers a URL, and `Modules::classRest()` drops either prefix from a class name. `Zero\Core\Module` itself, with nothing after it, is the base class every module extends.
- **Overriding a module of core.** A site overrides any module of core by having a module of the same name in its `modules/`. The site's answers the URL (`Zero\Module\Index`), its folder is the one `Modules::path()` names, and its views, assets, strings, config and `Admin/` are the ones used; nothing of core's folder is read on its behalf. Core's classes stay loadable under their own names, so the site's class may extend core's (`class Auth extends \Zero\Core\Module\Auth`) or stand alone. `Modules::overridden()` says a name is held by both, `Modules::corePath()` and `Modules::sitePath()` name each folder, and the Admin modules screen marks the row "Overridden".
- **Clashes.** Two spellings of one name (`Links` and `links`, compared ignoring case and hyphens), or one name in two folders of the site, is a configuration error: `Application::run()` logs it and the request fails with a 500. The exact name of a module of core in the site's folder is an override, not a clash.
- **Index.** Core ships `core/modules/Index/`, the landing page and the home of standalone pages, so a site works with no Index of its own. It holds two pages, `/` and `/privacy`, the one the sign-in screen links to. A site's own `modules/Index/` overrides it and carries its own `view/privacy.php`.

The nine modules every site gets (`Index`, `Auth`, `User`, `Admin`, `Push`, `Entity`, `Module`, `HelloWorld`, `Test`) live in `core/modules/`; every other module lives in the site's `modules/`. Requests for access to a capability are answered by `AccessRequest`, a submodule of Auth at `/auth/access-request`.

`core/modules/Module/mkmod` writes a new module into the site's `modules/`, never into `core/` (`--dest <dir>` overrides), and refuses a name a bundled module has: an override of a module of core is written by hand. Tests that check every module live in `core/tests/module/` and walk `Modules::all()`.

### Tiers and `app/modules.ini`

| Tier | Modules | Default |
|---|---|---|
| core | `Index`, `Auth`, `User`, `Admin`, `Push`, `Entity` | on |
| site | every module not named here | on |
| example | `Module`, `HelloWorld`, `Test` | off |

No module is always on. A site with no sign-in turns Auth off; `#[RequireLogin]` and `#[RequirePermission]` still redirect to `/user/login` and `/auth/access-request`, which then answer 404. The tier goes by name, so a site module that overrides a module of core keeps its tier.

`app/modules.ini` lists only the departures from those defaults:

```ini
; Only departures from the defaults.
[enabled]
Push = off
HelloWorld = on

[order]
Links = 10
```

- A missing file means all defaults. A file `Modules` cannot parse is read as all defaults and logs a warning naming it.
- An entry for a name that matches no module is ignored.
- `[order]` sets the position of a module's card on the admin dashboard (lower first, no entry last).
- A change takes effect on the next request.

### The Admin modules screen

`/admin/modules` (Admin) lists every module of both roots with its tier, where it lives (`core` or `site`), whether it is on and whether that is the default, and its order. A module of core the site overrides is marked "Overridden". Admin's own row says how to turn Admin back on (delete the `Admin = off` line from `app/modules.ini`), because turning it off removes the screen; the browser asks before saving that change. A module named like an INI keyword (`On`, `Off`, `Yes`, `No`, `True`, `False`, `None`, `Null`) cannot be set.

`POST /admin/modules/set` (`module`, `hash`, `enabled` = `on`, `off` or `default`, `order`) changes one module. The form carries the file's hash from when the screen was drawn, and the post is refused with 409 when the file has changed since. When the file cannot be written, the screen shows the error with the path and changes nothing.

`Zero\Core\ModulesIni` is the only writer of `app/modules.ini`. It:

- writes only departures from the defaults and keeps names it does not know;
- refuses an unknown name and an order outside 0..9999;
- rejects an existing file that holds anything but the two sections with booleans and whole numbers, naming the file and the key;
- checks that `Modules` reads its text back as the intended entries;
- saves under a lock on `app/modules.ini.lock` by writing a temp file in the same folder and renaming it, refusing when the file changed since it was read.

Permissions: the web server user must be able to write the FOLDER that holds `app/modules.ini` (the temp file, the lock file and the rename need the folder). An existing file keeps its permission bits, a file with no write bit at all is deliberately read-only and refused, and a new file is 0664.

A save changes `app/modules.ini`, a tracked file the site then commits. `modules.ini.lock` and `.modules.ini.*.tmp` are git-ignored (`zero-init.sh` writes both into a new site's `app/.gitignore`).

### A module that is off

A module that is off is treated at every step exactly as a module that does not exist:

- the controller is not loaded, and the view-file fallback in `Response` finds no view in its `view/`;
- its `config.ini`, `config/` and `strings/` are not read;
- its `frame/` and `aux/` are not used;
- `/admin/{module}` answers 404. It is checked before the admin class is looked up, so the admin file is never loaded;
- a submodule follows its parent: it is unreachable because the parent is;
- `Module::__construct()` creates no public asset link for it, and every existing link into its folder under `assets/` is removed (whatever the spelling of its name, links into a submodule, and links one level below a real directory such as `assets/test/route`).

The removal runs on the first request after the set of disabled modules changes (a module switched off in `app/modules.ini`, a new example-tier folder). `Application::run()` compares a hash of the disabled modules' real paths with `storage/cache/modules-assets.marker` and writes the marker only when every removal succeeded. When `storage/cache/` cannot be written, the cleanup runs on every request.

## Configuration System

Config is PHP constants from `.ini` files. `Application::defineConstants()` reads, in order:

1. The site's wording, `app/strings/*.ini` (next section).
2. Global config, `app/config/*.ini`, then the skeleton defaults the site has no file for. Only `.ini` files are config files; any other file in `app/config/` is left alone. The values of a file named `paths.ini` are prefixed with `ZERO_ROOT`.
3. Core's default wording, `core/skeleton/strings/*.ini`.
4. The array passed to `defineConstants()`, if any.
5. The requested module's `strings/*.ini`, then its `config.ini` and every file in its `config/`, when the module is switched on. An admin screen is served by the Admin module, so `/admin/{module}` also reads these for the module whose screen it is (`Application::loadModuleConfig()`).

A config file does not redefine a constant that already exists (a strings file or an earlier config file defined it): the repeat is logged with the key and file, and the first value stays. A key that is not a valid constant name is skipped with a log line.

After the files: when `TIMEZONE` is defined and not empty it becomes PHP's default timezone.

### The database: `database.ini`

`app/config/database.ini` holds `DB_DRIVER` (`mysql`, `pgsql` or `sqlite`), `HOST`, `NAME`, `USER` and `PASS`, with `DB_PORT` and `DB_CHARSET` optional.

- `DB_DRIVER` is required and has no default.
- Nothing connects until code calls `Database::getConnection()`.
- With `DB_DRIVER`, `NAME` or `USER` blank (`NAME` alone decides for sqlite), `Database::isConfigured()` is false and no connection is attempted at all: `getConnection()` throws `Database is not configured` and logs nothing. A site with no database leaves the file blank and serves every page that runs no query.
- A configured database that cannot be reached logs `Database connection failed` at critical level and throws.
- `Database::connect($conn_info)` and `Database::init($conn_info)` take their settings as an array and do not read these constants.

### Wording: `strings/`

Wording is constants too, from `.ini` files read by `Zero\Core\Strings` with `INI_SCANNER_RAW`.

- **The site's wording** is `app/strings/*.ini`, read before any config file. A strings key that is not a valid constant name, or that something other than a strings file already defined, is skipped with a log line.
- **Site name.** Once the config files are read, `SITE_NAME` is defined as `SITE_SHORT_NAME`, or as `Zero` when the site set neither, and `SITE_SHORT_NAME` is defined as `SITE_NAME` if nothing set it. A site that holds `SITE_NAME` only in `app/config/*.ini` keeps that value.
- **Core's defaults.** `Strings::loadDefaults()` then defines each key of `core/skeleton/strings/*.ini` (what the skeleton's frames say) that nothing has defined.
- **A module's defaults** are in `{Module}/strings/*.ini`, keys prefixed with the module's name in upper snake case. `Strings::loadModule()` defines each key not yet defined, so a site overrides any module string by repeating its key in `app/strings/`.
- **When a module's strings load.** `defineConstants()` loads the request's module and `Module::__construct()` loads the module being built (a submodule loads its parent's). Code that needs another module's wording calls `Strings::loadModule('Name')`. A module that is off loads nothing.

Write each value wrapped in double quotes: `KEY="We'll email you a link; it says "hi" = done."`. Inside the quotes a single quote, `=`, `;` and double quotes are literal, and a backslash is a backslash. Without the quotes `;` starts a comment and surrounding spaces are dropped.

A string that takes a value holds `%s` where the value goes (`USER_LOGIN_HEADING="Sign in to %s"`). Fill it with `Strings::fill(USER_LOGIN_HEADING, SITE_SHORT_NAME)`, never with `sprintf()`: `fill()` replaces `%s` and leaves every other percent sign as written, so a site's wording cannot make a page fail.

### Key Constants

- `ZERO_ROOT` — The site root, the folder that holds `core/`
- `ROOT_PATH` — The parent of `ZERO_ROOT`
- `MODULE_PATH` — The site's modules directory (the first root handed to `Modules`)
- `VIEW_PATH` — The site's frame folder, `app/frontend/frame/`
- `WEB_ROOT` — Public web root (`app/config/config.ini`)
- `DEVMODE` — Not defined at present (see Development Mode)

## Banned requests and `allowed-ips.lst`

`Application::run()` starts with `banNefariousRequest()`. A request whose URL contains `.php`, `.env` or `wp-` is one no legitimate client makes: the client's IP (`Request::clientIp()`) is blocked at Cloudflare with `CloudFlare::block()` and the request ends.

`app/config/allowed-ips.lst` lists the clients that are never banned. Such a client's request carries on and is answered like any other.

```
# one entry per line; `#` starts a comment
203.0.113.5          # a plain IP
198.51.100.0/24      # a CIDR range
2001:db8::/32        # IPv6 works the same way
```

- Matching is `Zero\Lib\Net\Cidr`. An entry that is not a valid IP or range matches nothing.
- The file is read only when a request is about to be banned, and no database is involved.
- With no file, nobody is exempt.
- The file is not committed (`app/config/` is ignored). The commented template is `core/skeleton/config-templates/allowed-ips.lst`; `zero-init.sh` copies it into a new site.

`Request::clientIp()` believes `CF-Connecting-IP` and `X-Forwarded-For` only when the peer is a Cloudflare edge (`Zero\Lib\Net\TrustedProxy`); otherwise the client is `REMOTE_ADDR`.

## Logging

`Console::debug()`, `info()`, `notice()`, `warn()`, `error()`, `critical()`, `alert()`, `emergency()` and `ban()` append a line to `Console::$defaultLogFile` (`/var/log/php-fpm/zero.log`); `ban()` writes to `/var/log/php-fpm/zero-tolerance.log`. A second argument names another file.

- A line holds the UTC time, the client IP `Request::clientIp()` resolves (`cli` when there is none), the level, the calling file and line, and the message. Arrays and objects are printed with `print_r()`.
- `ZERO_LOG_LEVEL_INT` (the level's index, `DEBUG` = 0) or `ZERO_LOG_LEVEL` (its name) sets the lowest level written. With neither, everything is written.
- Every message, whatever the threshold, is also kept for the request and returned by `Console::getMessages()` (the DevToolbar reads it).

## Asset System (overview)

Module assets (`modules/{Module}/assets/`) are symlinked to `/assets/{module}/` (lowercase) by `Module::__construct()`. `Module::linkAssets()` replaces a link whose target no longer exists and leaves a live link, a real directory and a real file alone.

CSS/JS files in `assets/css/` and `assets/js/` auto-load by filename convention:

- `{module}.css` / `{module}.js` — loads for all endpoints in the module
- `{endpoint}.css` / `{endpoint}.js` — loads only for that endpoint

Names are kebab-case (matching URL format). Submodule assets work the same way under `/assets/{parent}/{child}/`.

Each tag carries the file's modification time, `/assets/lists/js/view.js?v=1794503893`, so a deploy changes the URL and browsers fetch the new file. Apache sends no `Cache-Control`, so without the version a browser keeps a copy for about a tenth of the file's age and a normal reload doesn't ask again: a script last changed months earlier would stay cached for weeks and run against the new markup. Assets a frame includes by hand need the same `?v=filemtime` (`frame/head.php` does it for `main.css`).

See `/zeromodule` skill for the full convention, frame-override method signatures (`getStylesheets`, `getScripts`, `getComponents`), and submodule asset details.

Admin modules override this — assets are served inline, never web-accessible. See `core/docs/ADMIN_ASSETS.md`.

## Permission System

Two independent systems — they answer different questions. Both are rows in core's `permission` table, read and written only through `Zero\Core\Access`. A module keeps no permission table of its own, writes no SQL against `user_group`, `user_group_member` or `permission`, and does not depend on the Admin module, whose screens use the same classes. See `core/docs/PERMISSIONS.md`.

- **Capabilities**: "may this user use this feature at all?" Named `allow.*` keys, gated with `#[RequirePermission]` and read with `Session::can()`.
- **Sharing**: "may this user read, update or delete *this item*?" A grant to a `Group` or a `User` under a `{module}.{entity}` key, checked in the endpoint body, since an attribute runs before the row is loaded.

## Attribute System

Attributes in `core/attribute/` run via `Application::checkAttributes()` before method execution. They can block requests, sanitize input, enforce permissions, or log actions. Use `#[RequireLogin]` to enforce auth rather than manual `if (!Session::isLoggedIn())` checks.

`checkAttributes()` is public and static, and each dispatcher calls it for its own level: `Application::run()` for top-level modules, `Module::__call()` for submodules, `Response::__call()` for a standalone page's Index method, `AdminResponse::routeToModuleAdmin()` for module-admin controllers. Gates are additive down the nesting chain.

See `core/docs/ATTRIBUTES.md` for the full attribute reference, execution flow, and creating custom attributes.

## Submodules

Modules can nest submodules infinitely. When a method is missing, `Module::__call` checks for a matching submodule (`modules/{Parent}/submodule/{Child}/{Child}.php`) before falling back to view lookup. URL routing shifts down at each level: `/ttrpg/characters/view/5` → `Characters::view('5')`.

See `/zeromodule` skill for the full submodule convention.

## Development Mode

`DEVMODE` is not defined at present: the check in `Application::defineConstants()` that defines it when `REMOTE_ADDR` contains the string `DEV_SUBNET` is commented out, and nothing reads `DEV_SUBNET` (`[dev]` in `app/config/config.ini`; core's default in `core/skeleton/config/constants.ini`).

Code asks `defined('DEVMODE') && DEVMODE`, so it runs its production branch. With `DEVMODE` true:

- JSON is pretty-printed
- Xdebug's `var_display_max_*` limits are lifted
- A site's DevToolbar plugin shows the request's queries, log messages and attributes
