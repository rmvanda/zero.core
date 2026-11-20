# Zero Framework Internals

How the Zero Framework works under the hood. For building modules, use the `/zeromodule` skill — it covers URL routing, response methods, assets, submodules, and attribute usage.

## Philosophy

"Zero Routing, Zero Configuration, Zero Bloat" — URLs automatically map to classes and methods without configuration files.

## Core Classes

| Class | Purpose |
|-------|---------|
| `\Zero\Core\Application` | Front controller — output buffering, autoloading, routing |
| `\Zero\Core\Request` | Parses URL into module/endpoint/args |
| `\Zero\Core\Response` | View rendering, JSON/HTML response handling |
| `\Zero\Core\Module` | Base class for modules (extends Response) |
| `\Zero\Core\Exception\HTTPError` | HTTP error exception — `throw new HTTPError(404)` |
| `\Zero\Core\Database` | PDO connection management |

`\Zero\Core\Error` is deprecated — see `DEPRECATING_CHANGES.md`. All internals use `HTTPError`.

## Request Flow

1. `Application` instantiates, starts output buffering
2. `Request` parses URL: `/module/endpoint/arg1/arg2` → module, endpoint, args array
3. `Application` loads class from `modules/{Module}/{Module}.php`
4. Attributes checked via `Application::checkAttributes()`
5. If method exists: `$module->{$endpoint}(...$args)` (splat unpacks URL segments)
6. If method missing: checks for submodule at `modules/{Module}/submodule/{Endpoint}/{Endpoint}.php`
7. If no submodule: looks for view at `modules/{Module}/view/{endpoint}.php`
8. Falls back to `modules/Index/view/{module}.php` for static pages
9. Response wraps content with a frame. Each piece (`head`, `header`, `sideNav`, `footer`) is the first of: the module's `frame/`, the module's `view/`, the site's `app/frontend/frame/` (`VIEW_PATH`), then core's own copy in `core/skeleton/frontend/frame/`. A site that has written none of these still renders; it overrides a piece by having the file.

`ArgumentCountError` from step 5 is caught in `Application::run()` and returned as HTTP 400.

## What a site may leave out

- Frame pieces (`head`, `header`, `sideNav`, `footer`): looked up as in step 9 above, ending at `core/skeleton/frontend/frame/`. Partials a module view includes from the site's frame folder (such as `_google-signin.php`) are the site's own and have no skeleton copy.
- `app/config/error_codes.ini`: `Application::defineConstants()` reads every `app/config/*.ini`, then each file in `core/skeleton/config/` the site does not have (`Application::configFiles()`). A site that has its own `error_codes.ini` is not also given the skeleton's, so no constant is defined twice.

`core/skeleton/config/` holds defaults core itself reads; `core/skeleton/config-templates/` holds blank templates a new site copies and fills in.

## Error Handling

`HTTPError` is an exception caught by `Application::run()`, which calls `renderError()` on the already-instantiated module — preserving framePath/viewPath so error pages render with the correct frame. See root `CLAUDE.md` for usage.

Throw it; never instantiate `Error`, which builds a new Response and loses the module's frame context.

## Model Autoloading

Models in `modules/{Module}/model/` are auto-loaded by `Response::registerAutoloaders()`:

- Namespace must include `\Model\` (e.g., `namespace Zero\Module\MyModule\Model;`)
- No manual `require` statements needed

## Sharing logic between modules: `lib/`

Logic that another module uses lives in the providing module's `lib/` folder:
`modules/{Name}/lib/{Class}.php`, namespace `Zero\Module\{Name}\Lib`. Other modules call the
provider's `lib/`, never its `model/`, so the provider's rules apply wherever its data changes.
Example: `Zero\Module\Lists\Lib\ListService`, which Lists' own endpoints, Notebook and Index0 use.
Other examples are `Zero\Module\Llm\Lib\OllamaClient`, `Zero\Module\Comfy\Lib\ComfyUIClient` and
`Zero\Module\ApiConfig\Lib\ServiceClient`.

The libraries that belong to no module are in `core/lib/`: `Zero\Lib\Http`, `Zero\Lib\Mail`,
`Zero\Lib\CloudFlare`, `Zero\Lib\Push`, `Zero\Lib\Net` (`Cidr`, `TrustedProxy`) and the class `Zero\Lib\Crypto`.
The exceptions core throws are in `core/exception/` (`Zero\Core\Exception\HTTPError`). `Application::classFile()` maps `Zero\Lib\` to `core/lib/`.

The entity framework (`Entity`, `GenericEntity`, `Event`) is in `core/entity/`. `Application::classFile()` maps `Zero\Entity\` to `core/entity/` first and then to the site's `entity/` (or `ENTITY_SITE_DIR`), where a site's own and generated entity classes live; a site class named like a framework class is not loaded. See `core/entity/README.md`.

- At request time it loads through `Application::autoloader()`, which asks `Application::classFile()`
  for the file: `Zero\Module\{Name}` is the folder `Modules::path()` names, and each following
  namespace segment is a folder, tried as written, then as a submodule (`submodule/{Segment}/`),
  then lowercased (`Lib` is `lib/`). `Zero\Aux\` maps to `aux/`. Every segment must be a PHP
  identifier and the file must lie inside the folder its rule names. Only the
  segments are mapped, so a class name containing `Model` or `Lib` loads like any other.
- PHPUnit doesn't run that autoloader: `core/tests/bootstrap.php` maps
  `Zero\Module\{Name}\Lib\{Class}` to `modules/{Name}/lib/{Class}.php`.
- A `lib/` class takes the acting user and its dependencies as arguments rather than reading the
  session. For a Bearer-token caller (`#[AllowWithToken]`) the session exists only after the
  endpoint's attributes run, so a module builds a `lib/` object when an endpoint first needs it,
  not in its constructor.
- **Groups and permissions come from core.** `Zero\Core\Access\Group` owns the `user_group` and
  `user_group_member` tables and `Zero\Core\Access\Permission` owns `permission`. A module calls those
  classes (`Group::forUser($userId)`, `$perm->can(...)`, `$perm->sqlCan(...)` for a
  listing) and never writes SQL against the tables. No module depends on the Admin module; its
  screens use the same classes. See `core/PERMISSIONS.md`.

## How a module is found

`Zero\Core\Modules` answers every question about where a module is and whether it is on. `Application::registerAutoloaders()` hands it the module roots in priority order, the site's `modules/` folder (`MODULE_PATH`) first and `core/modules/` second, and the path of `app/modules.ini`. A directory in a root is a module when its name matches `^[A-Z][A-Za-z0-9]*$` (a folder such as `Links (old)` or `Evil&Co` is not one: the router never serves it and `app/modules.ini` cannot hold it). Core never builds a module path from `MODULE_PATH`: it asks `Modules::path()`, which accepts any spelling of a name (`ApiConfig`, `apiconfig`, `api-config`), and `Modules::name()` for the real directory name. Two roots holding the same name (compared ignoring case and hyphens) is a configuration error: `Application::run()` logs it and the request fails with a 500.

The ten modules every site gets (`Auth`, `User`, `Admin`, `AccessRequest`, `Sitemap`, `Push`, `Entity`, `Module`, `HelloWorld`, `Test`) live in `core/modules/`; every other module lives in the site's `modules/`. Their namespaces (`Zero\Module\Auth`) and URLs are the same wherever the folder is. A module's public asset link (`assets/{module}`) is a symlink to its `assets/` folder; `Module::linkAssets()` replaces a link whose target no longer exists and leaves a live link, a real directory and a real file alone. `core/modules/Module/mkmod` writes a new module into the site's `modules/`, never into `core/` (`--dest <dir>` overrides), and refuses a name a bundled module has. Tests that check every module live in `core/tests/module/` and walk `Modules::all()`.

Tiers: `Auth`, `User`, `Admin` and `AccessRequest` are required and always on. `Sitemap`, `Push` and `Entity` are optional, and every module not named here is a site module; both are on by default. `Module`, `HelloWorld` and `Test` are examples and are off by default. `app/modules.ini` lists only the departures from those defaults:

```ini
; Only departures from the defaults.
[enabled]
Sitemap = off
HelloWorld = on

[order]
Links = 10
```

A missing file means all defaults; an entry for a required module, or for a name that matches no module, is ignored. `[order]` sets the position of a module's card on the admin dashboard (lower first, no entry last).

`/admin/manage/modules` (Admin) lists every module of both roots with its tier, where it lives (`core` or `site`), whether it is on and whether that is the default, and its order; a required module has no switch, and a module named like an INI keyword (`On`, `Off`, `Yes`, `No`, `True`, `False`, `None`, `Null`) cannot be set. `POST /admin/set-module` (`module`, `hash`, `enabled` = `on`, `off` or `default`, `order`) changes one module through `Zero\Core\ModulesIni`, the only writer of `app/modules.ini`. The form carries the file's hash from when the screen was drawn and the post is refused with 409 when the file has changed since. `ModulesIni` writes only departures from the defaults, keeps names it does not know, refuses a required module, an unknown name and an order outside 0..9999, rejects an existing file that holds anything but the two sections with booleans and whole numbers (naming the file and the key), checks that `Modules` reads its text back as the intended entries, and saves under a lock on `app/modules.ini.lock` by writing a temp file in the same folder and renaming it, refusing when the file changed since it was read. The web server user must be able to write the FOLDER that holds `app/modules.ini` (the temp file, the lock file and the rename need the folder); an existing file keeps its permission bits, a file with no write bit at all is deliberately read-only and refused, and a new file is 0664. When it cannot write, the screen shows the error with the path and changes nothing. A save changes `app/modules.ini`, a tracked file the site then commits; `modules.ini.lock` and `.modules.ini.*.tmp` are git-ignored (`zero-init.sh` writes both into a new site's `app/.gitignore`). A change takes effect on the next request. A file `Modules` cannot parse is read as all defaults and logs a warning naming it.

A module that is off is treated at every step exactly as a module that does not exist: the controller is not loaded, the view-file fallback in `Response` finds no view in its `view/`, its `config.ini` and `config/` are not read, its `frame/` and `aux/` are not used, `/admin/{module}` answers 404, and every public asset link into its folder under `assets/` (whatever the spelling of its name, links into a submodule, and links one level below a real directory such as `assets/test/route`) is removed. The removal runs on the first request after the set of disabled modules changes (a module switched off in `app/modules.ini`, a new example-tier folder): `Application::run()` compares a hash of the disabled modules' real paths with `storage/cache/modules-assets.marker` and writes the marker only when every removal succeeded. When `storage/cache/` cannot be written, the cleanup runs on every request. `Module::__construct()` creates no public asset link for a module that is off. `/admin/{module}` is checked before the admin class is looked up, so its admin file is never loaded. A submodule follows its parent: it is unreachable because the parent is.

## Configuration System

- Global: `app/config/*.ini` → parsed as PHP constants
- Only `.ini` files in `app/config/` are config files; any other file there is left alone
- Module: `{Module}/config.ini` and `{Module}/config/*.ini` → loaded on access to a module that is switched on
- Paths in `paths.ini` are auto-prefixed with `ZERO_ROOT`
- A config file does not redefine a constant that already exists (a strings file defined it first): the repeat is logged with the key and file, and the first value stays

### Wording: `strings/`

Wording is constants too, from `.ini` files read by `Zero\Core\Strings` with `INI_SCANNER_RAW`. `Application::defineConstants()` reads the site's `app/strings/*.ini` first, before any config file; once the config files are read it defines `SITE_SHORT_NAME` as `SITE_NAME` if nothing set it and `SITE_NAME` as `Zero` if nothing set either, so a site that still holds `SITE_NAME` only in `app/config/*.ini` keeps that value. A strings key that is not a valid constant name, or that something other than a strings file already defined, is skipped with a log line; config files skip invalid names the same way. A module ships its defaults in `{Module}/strings/*.ini` (keys prefixed with the module's name in upper snake case); `Strings::loadModule()` defines each key not yet defined, so a site overrides any module string by repeating its key in `app/strings/`. `defineConstants()` loads the request's module and `Module::__construct()` loads the module being built (a submodule loads its parent's); code that needs another module's wording calls `Strings::loadModule('Name')`. A module that is off loads nothing.

Write each value wrapped in double quotes: `KEY="We'll email you a link; it says "hi" = done."`. Inside the quotes a single quote, `=`, `;` and double quotes are literal, and a backslash is a backslash. Without the quotes `;` starts a comment and surrounding spaces are dropped.

### Key Constants

- `ZERO_ROOT` — Framework root
- `MODULE_PATH` — The site's modules directory (the first root handed to `Modules`)
- `VIEW_PATH` — Global views
- `WEB_ROOT` — Public web root
- `DEVMODE` — True when IP matches `DEV_SUBNET`

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

## Asset System (overview)

Module assets (`modules/{Module}/assets/`) are symlinked to `/assets/{module}/` (lowercase) on first request via `Module::__construct()`. CSS/JS files in `assets/css/` and `assets/js/` auto-load by filename convention:

- `{module}.css` / `{module}.js` — loads for all endpoints in the module
- `{endpoint}.css` / `{endpoint}.js` — loads only for that endpoint

Names are kebab-case (matching URL format). Submodule assets work the same way under `/assets/{parent}/{child}/`.

Each tag carries the file's modification time, `/assets/lists/js/view.js?v=1794503893`, so a deploy changes the URL and browsers fetch the new file. Apache sends no `Cache-Control`, so without the version a browser keeps a copy for about a tenth of the file's age and a normal reload doesn't ask again: a script last changed months earlier would stay cached for weeks and run against the new markup. Assets a frame includes by hand need the same `?v=filemtime` (`frame/head.php` does it for `main.css`).

See `/zeromodule` skill for the full convention, frame-override method signatures (`getStylesheets`, `getScripts`, `getComponents`), and submodule asset details.

Admin modules override this — assets are served inline, never web-accessible. See `core/ADMIN_ASSETS.md`.

## Permission System

Two independent systems — they answer different questions.

**Capabilities** — "may this user use this feature at all?" Named `allow.*` keys, held as rows in the core `permission` table by a user or a group, gated declaratively with `#[RequirePermission('allow.admin')]` and read with `Session::can()`. Read through to the database on every request; a grant or revocation takes effect on the user's next request. See `core/docs/attributes/RequirePermission.md`.

**Sharing** — "may this user read, update or delete *this item*?" Rows in core's `permission` table, read and written only through `Zero\Core\Access\Permission`. A grant goes to a holder (a `Group` or a `User`), under a key of `{module}.{entity}` (`lists.list`, `kanban.board`). The module checks ownership itself: "is the owner, or `$perm->can($id, $userId, 'update')`". Used by Lists, Notebook, Notes, Kanban and Comfy. A module keeps no permission table of its own. See `core/PERMISSIONS.md`.

An attribute cannot express the second one — it runs before the row is loaded — so entity checks live in the endpoint body.

## Attribute System

Attributes in `core/attribute/` run via `Application::checkAttributes()` before method execution. They can block requests, sanitize input, enforce permissions, or log actions. Use `#[RequireLogin]` to enforce auth rather than manual `if (!Session::isLoggedIn())` checks.

`checkAttributes()` is public and static, and each dispatcher calls it for its own level: `Application::run()` for top-level modules, `Module::__call()` for submodules, `AdminResponse::routeToModuleAdmin()` for module-admin controllers. Gates are additive down the nesting chain.

See `core/docs/ATTRIBUTES.md` for the full attribute reference, execution flow, and creating custom attributes.

## Submodules

Modules can nest submodules infinitely. When a method is missing, `Module::__call` checks for a matching submodule (`modules/{Parent}/submodule/{Child}/{Child}.php`) before falling back to view lookup. URL routing shifts down at each level: `/ttrpg/characters/view/5` → `Characters::view('5')`.

See `/zeromodule` skill for the full submodule convention.

## Development Mode

When `DEVMODE` is true (IP matches `DEV_SUBNET` in `app/config/constants.ini`):

- JSON is pretty-printed
- Error pages show detailed information
- Xdebug settings adjusted
