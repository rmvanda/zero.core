# Deprecating changes

Things that were removed, replaced, or shelved, and what to use instead.

**This file exists so the other docs don't have to carry it.** Every other document in
this repo describes how the framework works *now*, in the present tense. When something
goes away, the explanation moves here and the doc it came from is rewritten as if the old
behaviour had never existed. If you find a doc explaining what something "used to" do,
that text belongs in this file.

Newest first.

## 2026-11 — Auth adapters have a namespace; Keycloak is gone

### Global adapter classes (`\\GitHubAdapter`) — REPLACED by `Zero\Module\Auth\Adapters\`

`GoogleAdapter`, `GitHubAdapter`, `DiscordAdapter`, `GitLabAdapter` and `OAuth2Adapter` are
classes of `Zero\Module\Auth\Adapters` in `core/modules/Auth/adapters/`. The autoloader
finds them; nothing loads an adapter file by path.

```php
// gone
require_once __DIR__ . '/../adapters/GitHubAdapter.php';
new \GitHubAdapter($config);

// use
use Zero\Module\Auth\Adapters\GitHubAdapter;
new GitHubAdapter($config);
```

### `KeycloakAdapter`, `/auth/keycloak-test` and via code 11 — REMOVED

The Keycloak OIDC test surface is gone: the adapter, its test, the `/auth/keycloak-test`
page and its stylesheet, and the `adapters/config-tmpl/keycloak.json` template. Via code 11
names no provider and answers 404. There is no replacement; a new OIDC provider is a new
adapter that extends `OAuth2Adapter`.

## 2026-11 — Tests and scripts load classes by the request's rule

### `Zero\...` entries in a site's `composer.json` — REMOVED

A site's `composer.json` has no `autoload` or `autoload-dev` section for the framework or
for a module (`"Zero\\Module\\Lists\\Model\\": "modules/Lists/model/"` and the like).
Composer loads third-party packages only. `core/autoload.php` registers
`Application::classFile()`, the rule a request uses, and the test bootstrap and every
command-line script include it:

```php
// gone
require __DIR__ . '/../../vendor/autoload.php';

// use
require __DIR__ . '/../../core/autoload.php';
```

### `require_once` of a module's class in its tests — REMOVED

A test names the class in a `use` line and nothing else. A `require_once` stays only for
a file whose class does not follow the naming convention.

## 2026-11 — Users, groups and permissions are in `core/access/`

The five classes moved, and their namespace follows the folder. There are no aliases: code
that names an old class does not load.

| Was | Is | File |
|---|---|---|
| `Zero\Core\User` | `Zero\Core\Access\User` | `core/access/User.php` |
| `Zero\Core\Group` | `Zero\Core\Access\Group` | `core/access/Group.php` |
| `Zero\Core\Permission` | `Zero\Core\Access\Permission` | `core/access/Permission.php` |
| `Zero\Core\Holder` | `Zero\Core\Access\Holder` | `core/access/Holder.php` |
| `Zero\Core\HoldsCapabilities` | `Zero\Core\Access\HoldsCapabilities` | `core/access/HoldsCapabilities.php` |

`Zero\Core\Session` stays where it is. A site's `composer.json` maps
`Zero\Core\Access\` to `core/access/`.

## 2026-11 — The ban check has a name and an allow-list

### `Application::banmotherfuckers()` — RENAMED to `Application::banNefariousRequest()`

Same check, same place (the first line of `Application::run()`). Nothing else called it.

### Every file in `app/config/` read as constants — REPLACED by `.ini` files only

`Application::configFiles()` returns only `.ini` files. A file of any other extension in
`app/config/` or `core/skeleton/config/` is not parsed, so `app/config/allowed-ips.lst` can
sit beside the config files.

### `DEPRECATING_CHANGES.md` at the site root — MOVED to `core/docs/DEPRECATING_CHANGES.md`

This file. It travels with core.

## 2026-11 — Modules throw `HTTPError`

### `new \Zero\Core\Error(...)` in Share, OCR, Links, Thingiverse, Ttrpg, Hexmap, Yelp and CleanInput — REPLACED by `HTTPError`

```php
// gone
new \Zero\Core\Error(404, 'Map not found');
return;

// use
throw new \Zero\Core\Exception\HTTPError(404, 'Map not found');
```

The status code is the same. The message is the `$detail` of the `HTTPError`: the error page
shows it and a JSON client gets it as `detail`. The error page keeps the frame of the module
that answers the request. `EntityGuard::verifyOwnership()` and `EntityGuard::requirePost()`
throw; they return only `true`. The unused `use Zero\Core\Error;` lines in Notes, Llm and
PollyDemo are gone.

### `core/Error.php` — MOVED to `core/deprecated/Error.php`, nothing loads it

No code calls `new \Zero\Core\Error(...)`, and the autoloader does not find the class.
`Share::initOCR()` and `aux/OCR/OCR.php` throw `HTTPError`. Both run inside the `try` of
`Share::processReceiptImageFile()` or `Share::processReceiptImageBase64()`, so those two
methods catch `HTTPError` first and throw it again: the client gets the specific code and
message, not `500 Failed to process receipt image`. `aux/OCR/test_ocr.php` loads
`core/exception/HTTPError.php`. The calls in `archive/TechStack/` throw `HTTPError` too.

## 2026-11 — core/ is tidied into folders

Classes that sat directly in `core/` moved, and their namespaces follow the folder. There
are no aliases: code that names an old class does not load.

| Was | Is | File |
|---|---|---|
| `Zero\Core\HTTPError` | `Zero\Core\Exception\HTTPError` | `core/exception/HTTPError.php` |
| `Zero\Core\StaleFileException` | `Zero\Core\Exception\StaleFileException` | `core/exception/` |
| `Zero\Core\FileNotWritableException` | `Zero\Core\Exception\FileNotWritableException` | `core/exception/` |
| `Zero\Core\Cidr` | `Zero\Lib\Net\Cidr` | `core/lib/Net/Cidr.php` |
| `Zero\Core\TrustedProxy` | `Zero\Lib\Net\TrustedProxy` | `core/lib/Net/TrustedProxy.php` |
| `Zero\Core\Crypto` | `Zero\Lib\Crypto` | `core/lib/Crypto.php` |
| `Zero\Core\ExternalRequest` | `Zero\Lib\Http\ExternalRequest` | `core/lib/Http/ExternalRequest.php` |

```php
// gone
use Zero\Core\HTTPError;

// use
use Zero\Core\Exception\HTTPError;
```

Also moved: `core/SQLAdapter.php` to `core/deprecated/SQLAdapter.php` (nothing loads it),
`core/log_monitor.sh` to `core/bin/log_monitor.sh`, and the framework docs from the site's
`docs/` to `core/docs/` (`ZERO.md`, `ATTRIBUTES.md`, `attributes/`, `PLUGINS.md`,
`PUSH_NOTIFICATIONS.md`, `TOKEN_ISSUE.md`, `MODULE_PHILOSOPHY.md`).

## 2026-11 — Core names no site

### `/test/vendorautoloader` — MOVED to `/sandbox/vendorautoloader`

The endpoint checks that a parent Composer project provides `Google_Client`, which only means something on a site with such a project. It is a Sandbox endpoint (site module) with the gate it had in Test.

### The `unisolu_user_id` cookie name — now `Session::hintCookieName()`

The "returning user" hint cookie is named by the `SESSION_HINT_COOKIE` constant (`app/config/constants.ini`) and is `zero_user_id` when the site defines none. A site that wants the old name sets `SESSION_HINT_COOKIE="unisolu_user_id"`.

### Frame partials `_oauth-brand.php`, `_oauth-buttons.php`, `_google-signin.php` — now found through `Response::framePiecePath()`

The bundled User and Auth views load these three partials through the frame lookup (the module's frame, its view folder, the site's `app/frontend/frame/`, then `core/skeleton/frontend/frame/`). A file in the site's frame folder wins; core's defaults are plain links (the Google button is a link into `/auth/user?via=3`, not Google's sign-in widget).

### Skeleton wording — defaults live in `core/skeleton/strings/`

`Strings::loadDefaults()` defines every key of `core/skeleton/strings/*.ini` that the site and its config did not define. The skeleton frames read the `SITE_*` constants directly.

### Module frame heads — scripts and fonts come from the `_head-scripts` frame piece

`core/modules/Module/frame/head.php` (and the head `mkmod --frame` copies) no longer name a component script or a Google Fonts link. They include the frame piece `_head-scripts` through `framePiecePath()`: the site's `app/frontend/frame/_head-scripts.php` when it has one, otherwise core's empty `core/skeleton/frontend/frame/_head-scripts.php`. This site's file holds the `shadow-component.js` script tag and the Material Symbols link the head used to carry (the font link now follows `main.css` instead of preceding it).

### `core/composer.json`

Lists the Composer packages core uses (`minishlink/web-push`; suggested `google/apiclient`). It documents what a site installs and is not read at runtime.

## 2026-11 — The skeleton runs on its own

### `zero-init.sh` cloning `shadowjs` and `zero.core` — REMOVED

`core/skeleton/zero-init.sh` clones nothing. It no longer fetches `shadowjs` into `www/shadow-component/` and no longer clones `zero.core`: it lives in `{site}/core/skeleton/`, so core is already there, and it builds the site folder in the folder that contains `core/` (or the folder given as the first argument). It no longer requires an empty target and no longer creates a `zero/` subfolder, a `www` symlink, a git repository for `app/` or changes permissions: the web root is `app/frontend/www/` and the vhost samples in `etc/` point at it. A file that already exists is skipped, so the script can run again.

### The skeleton's frames — REWRITTEN

`core/skeleton/frontend/frame/*` are plain HTML and one stylesheet (`assets/css/main.css`). They no longer use `shadow-component`, Material Symbols or a Google font, and `assets/js/main.js` is gone. A site that relied on the old skeleton frames for web components copies the frames it wants into its own `app/frontend/frame/`.

## 2026-11 — The bundled modules ship with core

### `modules/{Auth,User,Admin,AccessRequest,Sitemap,Push,Entity,Module,HelloWorld,Test}/` — MOVED to `core/modules/`

The ten modules every site gets live in `core/modules/`. Namespaces (`Zero\Module\Auth`, ...) and URLs
are unchanged; `Modules::path()` and the namespace map find them there. A site's `modules/` folder
holds only the site's own modules, and a site module may not reuse a bundled name.

| Was | Is |
|---|---|
| `modules/{X}/` for the ten | `core/modules/{X}/` |
| `modules/Auth/config/config.ini`, `modules/Auth/adapters/config/*.json` (ignored files) | the same paths under `core/modules/Auth/` |
| `modules/Test/config.ini`, `modules/Test/config/test.ini` (ignored files) | the same paths under `core/modules/Test/` |
| `php modules/Auth/bin/purge-auth-tokens.php` (cron) | `php core/modules/Auth/bin/purge-auth-tokens.php` |
| `modules/Module/mkmod` writing into the folder holding the skeleton | `core/modules/Module/mkmod` writing into the site's `modules/` (`--dest <dir>` overrides; a bundled name is refused) |
| `core/tests/AttributeOrderTest.php`, the old-admin-URL scan in `AdminDashboardTest` | `core/tests/module/AttributeOrderTest.php`, `core/tests/module/AdminUrlsTest.php`, both walking `Modules::all()` |

### `Module::linkAssets()` leaving a dangling link — REPLACED

A public asset link whose target no longer exists (a module moved folders) is replaced by a link
to the module's current `assets/` folder. A link to a live target, a real directory and a real file
stay as they are.

### Test endpoints that need the site — MOVED to the Sandbox module

`core/modules/Test` keeps the endpoints that need only core. The ones that need a site module, a
site table, a file under `aux/` or a user row moved to `modules/Sandbox/` (`Zero\Module\Sandbox`, the
same `#[RequireAuthLevel(1)]` gate). The tracked symlink `Test/submodule/Route/assets/success`
(an absolute link `Module::linkAssets()` created) is no longer tracked and is ignored.

| Was | Is |
|---|---|
| `/test/user` | `/sandbox/user` |
| `/test/publix-insert`, `/test/publix-parser`, `/test/parser-debug`, `/test/receipt-parser`, `/test/kroger-parser`, `/test/nam-dae-mun-insert`, `/test/nam-dae-mun-parser` | the same names under `/sandbox/` |
| `/test/update-urls` | `/sandbox/update-urls` |
| `/test/admin-routing` | `/sandbox/admin-routing` |
| `/test/settings` (view), `/test/xml` (view) | `/sandbox/settings`, `/sandbox/xml` |

## 2026-11 — The entity framework ships with core

### The top-level `entity/` framework files — MOVED to `core/entity/`

`Entity`, `GenericEntity` and `Event`, their scripts (`Test.php`, `Testcase.php`, `sanity.php`,
`test_generic_entity.php`, `encrypt.php`, `test`), `reset_entity.sql`, `README.md`, `CLAUDE.md`
and `.gitignore` moved from `entity/` to `core/entity/` (the `zero.entity` repository, a git
submodule of core). The site's `entity/` folder holds only the site's own classes, which are
committed in the site repository.

| Was | Is |
|---|---|
| `entity/{Entity,GenericEntity,Event}.php` | `core/entity/` (same names) |
| generated classes written next to `GenericEntity.php` | written to `GenericEntity::siteDir()`: `ENTITY_SITE_DIR`, else `ZERO_ROOT . 'entity/'`, else the framework folder when used alone |
| `Zero\Entity\` maps to `entity/` | `Zero\Entity\` maps to `core/entity/`, then the site's `entity/` (a site class named like a framework class is not loaded) |
| `require_once ZERO_ROOT . '/entity/GenericEntity.php'` | none: the autoloader loads it |

### Entity and attribute names that are not plain identifiers — REFUSED

`GenericEntity::define()` accepts only entity type, attribute, default and unique-key names
that are plain identifiers (`^[a-z_][a-z0-9_]*\z`, so a trailing newline is refused too), a
`server:KEY` default with `KEY` in upper-case letters, digits and underscores, and scalar or null
defaults. A type whose studly-cased class name matches a framework class (`generic_entity`,
`event`, `test_case`, ...) or an existing `Zero\Entity` class is refused before anything is
written, as is one whose class name is a PHP reserved word (`class`, `list`, `self`, `int`, ...). Entity and attribute names are capped at 64 characters. A class file differing only in case, or one `force` would overwrite that was generated for another entity name, is a collision. Values in a generated class are emitted with `var_export()`.

---

## 2026-11 — A schema for an empty database

### One-off `.sql` files — MOVED to `core/sql/migrations/`; `core/sql/core.sql` creates the tables

Core's tables used to exist only on the live database and in scattered one-off files. Now
`core/sql/core.sql` creates every table and view core and its bundled modules use on an empty
database (see `core/sql/README.md`), and the one-off files are gathered in `core/sql/migrations/`,
to be run by hand on a site that still needs them.

| Was | Is |
|---|---|
| `core/{capability_migration,group_rename,group,permission_migration,permission,user_profile,users_migration,users,user_view_eav_rollback,user_view}.sql` | `core/sql/migrations/` (same names) |
| `modules/Auth/auth_manual.sql`, `user_schema.sql`, `oauth_token.sql`, `oauth_provider.sql` | `core/sql/migrations/auth_auth_manual.sql`, `auth_user_schema.sql`, `auth_oauth_token.sql`, `auth_oauth_provider.sql` |

`entity/reset_entity.sql` stays beside the entity framework.

---

## 2026-11 — Libraries live in core or in the module that owns them

### The site-root `lib/` folder — REMOVED

`lib/` no longer exists. `Zero\Lib\` maps to `core/lib/`, which holds `CloudFlare`, `Http`,
`Mail` and `Push` under the same class names. Three libraries moved into the one module that
uses them and took that module's namespace (no alias is left at the old name):

| Old | New |
|-----|-----|
| `Zero\Lib\Api\ServiceClient` (`lib/Api/`) | `Zero\Module\ApiConfig\Lib\ServiceClient` (`modules/ApiConfig/lib/`) |
| `Zero\Lib\Api\ServiceResponse` | `Zero\Module\ApiConfig\Lib\ServiceResponse` |
| `Zero\Lib\Ollama\OllamaClient` (`lib/Ollama/`) | `Zero\Module\Llm\Lib\OllamaClient` (`modules/Llm/lib/`) |
| `Zero\Lib\ComfyUI\ComfyUIClient` (`lib/ComfyUI/`) | `Zero\Module\Comfy\Lib\ComfyUIClient` (`modules/Comfy/lib/`) |

The example `example_module_integration.php`, which shows `ServiceClient` inside a module,
moved from the skeleton module to `modules/ApiConfig/`; `modules/ApiDemo` is the live example.

---

## 2026-11 — Fix wave after the core rules work

### Asset-link cleanup per request — REPLACED by a cleanup when the disabled set changes

`Application::run()` no longer walks `assets/` on every request. `Modules::dropDisabledAssetLinksWhenChanged()`
hashes the sorted real paths of the modules that are not enabled and compares the hash with
`storage/cache/modules-assets.marker`; the links are removed when it differs, and the marker is written only
when every removal succeeded. `Module::__construct()` (through `Module::linkAssets()`) creates no public
`assets/{module}` link for a module that is off. When `storage/cache/` cannot be written the cleanup runs on every request.

### Config loader key names — NARROWED

A config key (any `app/config/*.ini`, a module's `config.ini` or `config/*.ini`, a skeleton file) that is not
`^[A-Za-z_][A-Za-z0-9_]*$` is skipped with a Console WARN; it used to reach `define()`, which accepted `a.b` or
`a-b` and threw on `a::b`.

### A repeated config key — LOGGED

A key that is already defined, from any source (a strings file, an earlier config file, `index.php`), keeps its
first value and writes a Console WARN naming the key and file. It used to raise PHP's "already defined" warning.

### `/assets/hexmap/` — REPLACED by `/assets/ttrpg/hexmap/`

The hex map's images load from `/assets/ttrpg/hexmap/img/...`, the link the framework maintains for
the `Ttrpg\Hexmap` submodule. The top-level `assets/hexmap` link is removed when Ttrpg is switched off
and is not re-created. No saved map stores an asset URL (`ttrpg_maps.map_data` holds none), so no
stored value needs converting.

### `Mail::sendEmailTo()` default sender `noreply@unisolu.com` — REPLACED

The default `From` and `Reply-To` is `Mail::sender()`: `MAIL_FROM` when the site's config defines it
(and it is not blank), otherwise `noreply@` + `PRIMARY_DOMAIN`.

### `CapabilityKeys::all($modulesDir)` and `declared($modulesDir)` — REPLACED

They take a list of module directories (each with a trailing slash), or none to read every module that
is switched on in every root, instead of one `modules/` folder.

---

## 2026-11 — Wording comes from `strings/`

### `SITE_NAME` in `app/config/config.ini` — MOVED

`SITE_NAME` is defined in `app/strings/site.ini`, with `SITE_SHORT_NAME`; module defaults sit in
`{Module}/strings/*.ini` (see `docs/ZERO.md`, "Wording: `strings/`"). A config file that repeats a key
a strings file already defined no longer raises "already defined": the repeat is logged and the strings
value stays. Remove `SITE_NAME` from `app/config/config.ini`; the skeleton's `config-templates/config.ini`
no longer carries it and `core/skeleton/strings/site.ini` does.

---

## 2026-11 — A module is found through `Modules` and can be turned off

### `enabled` and `order` in `admin-config.php`, and core building module paths from `MODULE_PATH` — REPLACED

`Zero\Core\Modules` finds every module (the site's `modules/` folder, then `core/modules/`) and says
whether it is on. `app/modules.ini` holds the departures from the tier defaults: `[enabled]`
(`Name = on|off`) and `[order]` (`Name = n`). `admin-config.php` no longer has `enabled` or `order`;
`getAdminModules()` lists a module when `Modules::enabled()` is true and sorts by `Modules::order()`,
modules without an order last, then by name. The `$root` parameter of `getAdminModules()` and
`AdminResponse::resolveModuleDir()` are gone: `Modules::configure()` points tests at a throwaway tree
and `Modules::name()` gives a module's real directory name. A module that is off answers 404 exactly as
a module that does not exist: no controller, no view-file fallback, no `config.ini`, no `/admin/{module}`,
and `Application::run()` removes its public asset links (when the set of disabled modules has changed, see below). A name held by two roots makes `run()` fail
with a 500 (`Modules::assertNoClash()`). `assertNoClash()` compares names the way `path()` does (case and
hyphens ignored); `dropDisabledAssetLinks()` removes every symlink under `assets/` (and one level below a real folder there) that points anywhere into a disabled module's folder, submodule links included.
A URL that differs from a module's folder name only by case or hyphens now reaches the module (`/speedtest` reaches `SpeedTest`); it used to fall through to `Index` and 404. The skeleton `Module` is an example module and is off by default; unisolu's `app/modules.ini` turns it,
`HelloWorld` and `Test` on.

---

## 2026-11 — The autoloader maps namespaces to folders

### Path guessing by string replacement, and the site folder named `zero` — REPLACED

`Application::autoloader()` loads through `Application::classFile()`, an explicit map:
`Zero\Core\Attribute\` is `core/attribute/`, `Zero\Core\` is `core/`, `Zero\Lib\` is `lib/`,
`Zero\Entity\` is `entity/`, `Zero\Aux\` is `aux/`, `Zero\App\Lib\` is `app/lib/`, and `Zero\Module\{X}` is the
folder `Modules::path(X)` names, with each following segment a folder (as written, then as a
submodule, then lowercased). The class name is no longer rewritten, so a class named `ModelCache`
or `LibraryService` loads. `isModule()` loads a module's controller through the same map, and only for a URL name that is a
single identifier; a class name with a segment that is not an identifier maps to nothing. A module
folder with a lowercase name (`modules/foo/Foo.php`) is no longer found: the folder is the module's
name as `Modules` knows it.
`app/frontend/www/index.php` defines `ZERO_ROOT` as the folder three levels above `www/`; the site
folder no longer has to be called `zero`, and `ROOT_PATH` is the parent of `ZERO_ROOT`.
`registerAutoloaders()` hands `Modules` its roots (`MODULE_PATH` and `core/modules/`) and
`app/modules.ini`.

---

## 2026-11 — Admin offerings are classes, filtered by permission, with a fixed set of routable methods

### Any `modules/*/Admin/` folder as an offering, unfiltered cards, and `method_exists()` routing — REPLACED

An admin offering is a module with the class `Zero\Module\{X}\Admin\{X}Admin`; a bare `Admin/`
folder is not listed. A dashboard card is listed only if the user holds every key in the
offering's `admin-config.php` `permissions`. `AdminResponse::routeToModuleAdmin()` routes only
to public, non-static methods declared outside `Zero\Core\` that are not constructors or magic
methods, so `/admin/{module}/load-config`, `/admin/{module}/load-inline-asset/...` and an
offering's protected helpers answer 404. Every existing offering's own endpoints still route.

### `/admin/billing/get-current-billing?refresh=1` — REPLACED by POST

`BillingAdmin::getCurrentBilling()` refreshes from AWS only on a POST request. A GET serves the
cache (calling AWS only when no cache exists) and ignores `?refresh=1`.

### `new \Zero\Core\Error(400, ...)` in `Admin::manage()` — REPLACED by `HTTPError`

`/admin/manage` with no type throws `HTTPError(400, 'Management type required')`.

---

## 2026-11 — The WebSocket monitor and the push screen belong to Ws and Push

### `modules/Admin/submodule/WebSocket/`, `Admin::pushNotifications()`, `Admin::deletePushNotifications()` — MOVED into the Ws and Push modules' admin offerings

The WebSocket monitor stopped being an Admin submodule and the push notification screen
stopped being part of Admin. They are `Zero\Module\Ws\Admin\WsAdmin`
(`Zero\Module\Admin\WebSocket` before) and `Zero\Module\Push\Admin\PushAdmin`
(`Zero\Module\Admin::pushNotifications()` and `deletePushNotifications()` before), which Admin
discovers and routes to. The "System Monitoring" section of the Admin dashboard is gone;
both screens are listed with the other module offerings.

| Was | Now |
|-----|-----|
| `/admin/web-socket` | `/admin/ws` |
| `/admin/web-socket/stats` | `/admin/ws/stats` |
| `/admin/push-notifications` | `/admin/push` |
| `/admin/delete-push-notifications` | `/admin/push/delete` (still POST only, still audit logged as `push_notification_delete`) |

Views and assets sit in `modules/Ws/Admin/` and `modules/Push/Admin/` and are inlined by the
views (`websocket.css`, `websocket.js`, `push.js`). The WebSocket monitor's notes moved to
`modules/Ws/CLAUDE.md`. `modules/Admin/submodule/` holds only `Firewall`.

---

## 2026-11 — Billing and Plugins are their own modules

### `modules/Admin/submodule/Billing/`, `modules/Admin/submodule/Plugins/` — MOVED into their own modules' admin offerings

Billing and Plugins stopped being Admin submodules. They are
`Zero\Module\Billing\Admin\BillingAdmin` and `Zero\Module\Plugins\Admin\PluginsAdmin`
(`Zero\Module\Admin\Billing` and `Zero\Module\Admin\Plugins` before), which Admin
discovers and routes to.

| Was | Now |
|-----|-----|
| `/admin/billing/{endpoint}/...` | unchanged |
| `/admin/plugins/{endpoint}/...` | unchanged |

Billing's `config.ini` and `config-tmpl/` moved to `modules/Billing/`. The framework only
reads the config of the module named in the URL, which for `/admin/billing` is Admin, so
`BillingAdmin` reads `modules/Billing/config.ini` itself and answers 500 ("Billing is not
configured") when the file is missing. Its dashboard component is
`modules/Billing/Admin/assets/aws-billing-dashboard.html`, required by the view. Views and
assets sit in `modules/{Billing,Plugins}/Admin/`; the plugin CSS and JS are inlined by the
view.

---

## 2026-11 — ApiConfig is its own module

### `modules/Admin/submodule/ApiConfig/`, `/admin/manage/api-transactions`, `/admin/view-api-response`, `/admin/expire-api-cache` — MOVED into the ApiConfig module's admin offering

The API configuration screens left `modules/Admin` and stopped being an Admin submodule.
They are `Zero\Module\ApiConfig\Admin\ApiConfigAdmin`, which Admin discovers and routes
to, and so is the API transaction log that lived in `Admin` itself.

| Was | Now |
|-----|-----|
| `/admin/api-config/{endpoint}/...` (every endpoint) | unchanged |
| `/admin/manage/api-transactions` | `/admin/api-config/transactions` |
| `/admin/view-api-response/{txn_id}` | `/admin/api-config/view-response/{txn_id}` |
| `POST /admin/expire-api-cache/{txn_id}` | `POST /admin/api-config/expire-cache/{txn_id}` |
| (Admin dashboard API Transactions card) | `/admin/api-config` (View Transactions button) |

`Admin::viewApiResponse()` and `Admin::expireApiCache()` are gone, and `Admin::manage()`
no longer has an `api-transactions` case. The models are `Zero\Module\ApiConfig\Model\ApiService`,
`ApiProvider`, `ApiProviderService` and `ApiProviderServiceConfig` (`Zero\Module\Admin\ApiConfig\Model\*`
before). Views and assets sit in `modules/ApiConfig/Admin/`; the `manage-api-transactions`
and `view-api-response` assets are now `transactions` and `view-response`. `api_schema.sql`
and `CLAUDE.md` moved to `modules/ApiConfig/`.

---

## 2026-11 — Entity screens belong to the Entity module

### `/admin/manage/{entity}`, `/admin/manage/event`, `/admin/create-entity`, `/admin/update-entity`, `/admin/delete-entity` — MOVED into the Entity module's admin offering

The entity-management screens and the event log left `modules/Admin`. They are
`Zero\Module\Entity\Admin\EntityAdmin`, which Admin discovers and routes to.

| Was | Now |
|-----|-----|
| `/admin/manage/{entity}` (any entity label) | `/admin/entity/manage/{label}` |
| `/admin/manage/event` | `/admin/entity/events` |
| `/admin/create-entity` | `/admin/entity/create` |
| `/admin/update-entity` | `/admin/entity/update` |
| `/admin/delete-entity` | `/admin/entity/delete` |
| (Admin dashboard Entity Management section, Event Logs card) | `/admin/entity` |

`Admin::manageEntity()`, `Admin::toStudlyCase()`, `Admin::createEntity()`,
`Admin::updateEntity()` and `Admin::deleteEntity()` are gone. `Admin::manage()` answers 404
for a type it has no screen for (`users` and `groups` remain).
`Zero\Module\Admin\Model\Entity` is now `Zero\Module\Entity\Model\EntityStore`. The views
and the `create` and `manage` assets moved with it (`modules/Entity/Admin/`); the
`create-entity` and `manage-entity` asset names are now `create` and `manage`.

**Event log.** `/admin/manage/event` rendered the generic entity table for the `event`
type; `view/manage-events.php`, the filterable log over `event_view`, was never selected.
`/admin/entity/events` renders that filterable log.

---

## 2026-11 — OAuth storage belongs to the Auth module

### `Zero\Entity\OAuthToken`, `Zero\Entity\OAuthProvider`, `Zero\Core\TokenBroker` — MOVED into Auth

The OAuth token store, provider registry and token broker are Auth's own, so they live in
`modules/Auth/OAuth/`. Methods, signatures and the stored rows are unchanged.

| Was | Now |
|---|---|
| `Zero\Entity\OAuthToken` | `Zero\Module\Auth\OAuth\Token` |
| `Zero\Entity\OAuthProvider` | `Zero\Module\Auth\OAuth\Provider` |
| `Zero\Core\TokenBroker` | `Zero\Module\Auth\OAuth\Broker` |
| `entity/oauth_token.sql`, `entity/oauth_provider.sql` | `modules/Auth/oauth_token.sql`, `modules/Auth/oauth_provider.sql` |
| `core/tests/TokenBrokerTest.php` | `modules/Auth/tests/OAuthBrokerTest.php` |

---

## 2026-11 — Session, User, Group and Permission reshaped

One design for the four classes: `Session` is the request, `User` and `Group` are row objects
that can both hold permissions, `Permission` grants to either. See `core/PERMISSIONS.md`.

### Session statics on `Zero\Core\User` — MOVED to `Zero\Core\Session`

`User` was the account and the session in one class.

| Was | Now |
|---|---|
| `User::currentId()`, `User::getId()` | `Session::userId()` |
| `User::current()` | `Session::user()` |
| `User::isLoggedIn()` | `Session::isLoggedIn()` |
| `User::hasPermission($key)` | `Session::can($key)` |
| `User::establish($user)` | `Session::establish($user)` |
| `User::logout()` | `Session::end()` |

### `Zero\Core\Group` as a service taking a PDO — REPLACED by a `Model` row object

`new Group($db)` returned arrays and took a group id on every call. A group is an object:
`Group::find($id)`, `$group->group_name`, `$group->add($userId)`, `$group->members()`,
`Group::forUser($userId)`, `$group->save()`, `$group->delete()`.

| Was | Now |
|---|---|
| `$groups->isMember($groupId, $userId)` | `Group::find($groupId)?->has($userId)` |
| `$groups->update($id, [...])` | set properties, `$group->save()` |
| `$groups->removeUser($userId)` | `Group::removeFromAll($userId)`, called by `User::delete()` |
| reads returned `[]` on a database error | a `\PDOException` reaches the caller |
| `\Exception` for "already a member" / "not a member" | `\DomainException` |

### Paired group and user methods on `Permission` — REPLACED by `Holder`

`grant($id, $groupId)` and `grantUser($id, $userId)`, `revoke()` and `revokeUser()`,
`forgetGroup()` and `forgetUser()` were one operation written twice. `User` and `Group`
implement `Zero\Core\Holder`, and `grant($id, $holder)`, `revoke($id, $holder)` and
`Permission::forgetHolder($holder)` take either. `Permission` no longer takes a PDO first:
`new Permission('lists', 'list')`, with an optional PDO last for code outside the framework.

### Callers cleaning up after a deleted user or group — MOVED into `delete()`

Admin's `UserAdmin` had to remember to remove a deleted user's memberships and grants.
`$user->delete()` removes memberships, grants, settings and profile; `$group->delete()` removes
memberships and grants.

### Sharing with any group id a form posted — REFUSED

Notes, Kanban and Notebook granted to whatever group id was posted, and Ttrpg attached any
posted group to a campaign, which listed that group's members and their emails to an outsider.
`Permission::grant()` to a group requires the granter to be a member of it or an admin
(`Group::usableBy()`), and those four forms answer 403.

### Capabilities for users only — EXTENDED to groups

`can()`, `capabilities()`, `grant()` and `revoke()` lived on `User`. They are the
`HoldsCapabilities` trait, and `Group` has them too.

## 2026-11 — Capabilities live in `permission`

### `allow.*` rows in `user_settings` — REPLACED by rows in `permission`

A capability was a `user_settings` row with the value `'1'`; `User::can()` read the whole
settings map and granted on a strict `'1'`, `1` or `true`. A capability is a row in `permission`
on `entity_id` 0, so a group can hold one too. `core/capability_migration.sql` moved every
`allow.*` row and the three older keys `network.mapper`, `hello.world` and `posts.edit`.
`network.mapper` and `hello.world` are now `allow.network.mapper` and `allow.hello.world`, so
every capability key starts with `allow.`; `grant()` refuses one that does not.

- Grant and revoke with `User::grant($key, $actorId)` and `User::revoke($key)`.
  `User::setSetting('allow.x', '1')` grants nothing: it writes a setting.
- `User::can()` answers for capabilities only. Read a setting with `User::settings()`. Index0's
  `index0.confirmations` is a setting and is read that way.
- A `user_settings` value of `'0'` for a capability meant "not granted". There is no such row
  now: not granted is no row.
- `swoole/ws-server.php` had its own copy of the `allow.admin` query. It uses
  `Permission::capability()`.

### The `user_id = 0` key registry in `user_settings` — REMOVED

Rows for user 0 listed the keys the Admin user screen should offer.
`modules/Admin/USER_SETTINGS_POLISH.md` described them and is deleted. The screen reads the
keys from the modules' `#[RequirePermission]` attributes and from the grants that exist
(`Zero\Module\Admin\Model\CapabilityKeys`). In `permission`, `user_id` 0 means "this row is a
group's", which is why `User` still refuses to work on id 0.

### `POST /admin/update-permission` — TAKES `kind`

`kind=capability` grants when `setting_value` is `1` and revokes otherwise; `kind=setting`
writes `user_settings`. Without `kind`, an `allow.*` key is a capability and any other key is a
setting.

## 2026-11 — Group tables renamed

### `group`, `group_member` — RENAMED to `user_group`, `user_group_member`

`group` is a reserved word and had to be quoted in every query. `core/group_rename.sql` renames
both tables; the class is still `Zero\Core\Group`, and only `core/Group.php` and
`core/Permission.php` name the tables.

## 2026-11 — Permissions: one core table

Sharing an item with a group is core's job. `Zero\Core\Permission` owns one `permission` table
for every module; see `core/PERMISSIONS.md`.

### Per-module permission tables — REPLACED by `permission`

Each module kept its own table, with its own drifted schema, and wrote its own joins against
`group_member` to read it. A module could not be installed without those joins reaching into
tables it did not own.

| Was | Now: rows in `permission` under key |
|---|---|
| `list_permissions` | `lists.list` |
| `notebook_permissions` | `notebook.notebook` |
| `note_permissions` | `notes.note` |
| `kanban_permissions` | `kanban.board` |
| `comfy_share` (`shared_by` → `granted_by`, always read-only) | `comfy.generation` |

`core/permission_migration.sql` copies the old rows across for a site that still has the old
tables. It does not drop them.

### `Zero\Core\GroupPermission` — REPLACED by `Zero\Core\Permission`

`GroupPermission` was built with a module's table and column names and read the module's entity
table to find the owner. `Permission` is built with a module and entity name and knows nothing
about the module's tables.

| Was | Use |
|---|---|
| `new GroupPermission($db, 'notes', 'note_id', 'note_permissions', 'note')` | `new Permission($db, 'notes', 'note')` |
| `hasPermission($id, $userId, $action)` (checked the owner too) | the module checks the owner, then `can($id, $userId, $action)` |
| `getByEntity($id)` | `groups($id)`; rows carry `entity_id`, `granted_by`, `granted_on`, not `permission_id`, the module's id column or `created_at` |
| `getAvailableGroups($id, $userId)` | `availableGroups($id, $userId)` |
| `getSharedUsers($id)` | `users($id)` |
| `update($id, $groupId, $flags)` | `grant()` again with the new flags |
| a hand-written `LEFT JOIN x_permissions … LEFT JOIN group_member …` listing | `sqlCan('alias.id', $userId, $action)` |

`grant()`, `revoke()`, `find()` and `batchGrant()` keep their names.

### `Zero\Module\Lists\Model\ListPermission` — REMOVED

A hand-copied duplicate of `GroupPermission`. Lists uses `Zero\Core\Permission`;
`ListService::__construct()` takes one where it took a `ListPermission`. `getByList()` →
`groups()`.

### Grants cascading when an item is deleted — REPLACED by `Permission::forget()`

The old tables had `ON DELETE CASCADE` foreign keys to the item. `permission` has no foreign
keys, so the code that deletes an item calls `forget($id)`: `ListModel::delete()`,
`NotebookModel::deleteNotebook()`, `NoteModel::delete()`, `BoardModel::delete()`,
`ComfyModel::deleteGeneration()`. `Group::delete()` removes the grants made to the group.

### A grant with no rights — NOT STORED

Saving a group as shared with read, update and delete all off stored an all-zero row, which
showed the group as having access with nothing it could do. `grant()` with all three flags
false removes the row.

### A deactivated group kept its access — FIXED

Sharing forms hid a deactivated group, but the permission checks joined `group_member` alone, so
its members kept every right it had been granted and nobody could see or revoke it. An inactive
group now grants nothing anywhere, and its grants return if it is reactivated.

### An unknown action name in `NotebookModel::hasPermission()` meant `read` — THROWS

It throws `InvalidArgumentException`. Lists and Kanban still read an unknown action as `read`.

### `share-notebook` to a group that doesn't exist failed on a foreign key — REPLACED by 404

### Notebook `sharing-info`: `permissions[].permission_id`, `notebook_id`, `created_at` — REPLACED

Use `entity_id` and `granted_on`.

## 2026-11 — Groups: `Zero\Core\Group` replaces the Admin module's models

No module depends on the Admin module. Groups were the one place that rule broke.

### `Zero\Module\Admin\Model\Group` and `GroupMember` — REPLACED by `Zero\Core\Group`

Lists, Notebook, Kanban, Notes and Comfy built the Admin module's `Group` model to read a user's
groups, so none of them could be installed without Admin. One core class now covers both models:

| Was | Use |
|---|---|
| `Group::getUserGroups($userId)` | `Group::forUser($userId)` |
| `Group::getMembers($groupId)` | `Group::members($groupId)` |
| `Group::delete($groupId)` (set `is_active = 0`) | `Group::deactivate($groupId)` |
| `Group::hardDelete($groupId)` | `Group::delete($groupId)` |
| `GroupMember::add()`, `remove()` | `Group::add()`, `remove()` |
| `GroupMember::updateRole()` | `Group::setRole()` |
| `GroupMember::getRole()` | `Group::role()` |
| `GroupMember::isMember()` | `Group::isMember()`, which is false for an inactive group |
| `GroupMember::getNonMembers($groupId)` | `Group::nonMembers($groupId)` |

`find()`, `all()`, `create()` and `update()` keep their names. `Group::findByName()`, `getStats()`
and `GroupMember::find()`, `count()`, `bulkAdd()`, `bulkRemove()` had no callers and are gone.

Ttrpg and the Admin user list wrote their own SQL against the two tables; they use `Group` too.
`UserAdmin::delete()` clears a deleted user's memberships with `Group::removeUser()` and their
direct grants with `Permission::forgetUser()`.

### `GroupMember::updateRole()` to the role a member already has threw — FIXED

It read "no row changed" as "not a member". `Group::setRole()` throws only when the user is not in
the group.

### `modules/Admin/group_schema.sql` — MOVED to `core/group.sql`

The tables belong to core. The file no longer drops the tables before creating them.

## 2026-11 — Notebook: a checklist is a reference to a List

A checklist (a Notebook List Object) has content `{"list_id": N}` and nothing else, naming a List
its creator can read. The notebook page reads and edits the List through the Lists module's own
endpoints; Notebook creates a checklist's List through `Zero\Module\Lists\Lib\ListService` and
otherwise never touches Lists.

### A new checklist made its List at once — REPLACED by making it with the first item

`create-object` made a List for every new checklist, and the canvas menu's default content put a
"New item" in it, so adding a checklist and undoing it left an orphan List on `/lists`. A new
checklist now starts empty (`{}`) and makes no List; it gets one through
`/notebook/create-list/{objectId}` when it gets its first item or a title. `create-object` with a
title or items still makes the List at once.

### `/notebook/checklist-*` endpoints — REMOVED

`checklist-add-item`, `checklist-toggle-item`, `checklist-update-item`, `checklist-delete-item`,
`checklist-reorder`, `checklist-update-title` and `checklist-migrate`, with their helpers
`resolveChecklist()` and `resolveChecklistItem()`, edited a checklist's List through Lists'
models and re-implemented Lists' rules (they took blank items, didn't count suggestions, and let
a drag cross the checked items). Use `/lists/add-item`, `toggle-item`, `update-item`,
`delete-item`, `reorder-items`, `edit/{id}` and `delete/{id}` (see `docs/LISTS_API.md`).

### Lazy migration of checklists without a List — REMOVED

A checklist created before the Lists integration had no `list_id`; the first click on it called
`checklist-migrate` (`ensureChecklistLinked()` and its `_migrating` flag in page.js), which made a
List from the stored items. The last two such checklists were converted once, and every checklist
now gets its List when it is created.

### Deleting a List with its checklist, page or notebook — REMOVED

`deleteObject()`, `deletePage()` and `deleteNotebook()` deleted the Lists behind the checklists
they removed (`deleteChecklistLists()`, keeping a List another checklist still showed through
`NotebookModel::isListReferenced()` and `getPageChecklistListIds()`). A checklist is only a
reference now, so they leave the List alone; the page instead asks whether to delete the List too.

### Copying notebook sharing changes onto its Lists — REMOVED

`shareNotebook()` and `revokeShare()` granted or revoked the group on every List behind the
notebook's checklists. A new checklist's List starts with a copy of the notebook's sharing; after
that its sharing is its own, managed on `/lists`. (A reference can point at any List its creator
can read, so mirroring would have let a notebook owner share someone else's List.)

### Checklist content holding the title and items — REPLACED by the List

A checklist's stored content carried a `title` and an `items` array (with `item_id`s), and
`pageData()` filled `items` from the List on every load. The content is `{"list_id": N}` now; the
page reads the title and items from `/lists/get-items`, and `update-object` stores a checklist's
content as exactly its stored `list_id`. Stored rows were stripped once.

### Finding a checklist by its text in the canvas search — REMOVED

`objectMatchesQuery()` matched a checklist's title and item text from its content. The content
holds only the `list_id` now, and Notebook search knows nothing about Lists, so the canvas search
(and the server's `search-objects`) no longer finds a checklist by its title or items. Search the
List on `/lists`.

### The details panel's checklist title field — REPLACED by plain text

The details panel showed the checklist's title in an input that wrote `content.title`. The title
is the List's, so the panel shows it as text; rename it on the checklist itself (double-click the
title), which calls `/lists/edit/{id}`.

### `ListItem::toggleComplete()` — REMOVED

Only Notebook's `checklist-toggle-item` used it. `ListService::toggleItem()` sets the state and moves
the item to the end of the group it joins.

---

## 2026-11 — Lists: the rules move to `lib/ListService`

### A List that doesn't exist answered 403 — REPLACED by 404

`Lists.php`'s `requireList()` checked permission before existence, and
`ListModel::hasPermission()` is false for a List that doesn't exist, so every endpoint that takes
a List answered 403 "You do not have … permission on this list" for one that was never there or
had been deleted. `ListService::get()` checks existence first: a missing List is 404 "List not
found" for everyone, and a 403 means the List exists. (Item endpoints already answered 404 for a
missing item.)

### `Lists.php`'s private guards — MOVED to `Zero\Module\Lists\Lib\ListService`

`requireList()`, `requirePermission()`, `requireItem()`, `requireContent()`, `requireTitle()`,
`parseItemOrder()` and `listContents()` were private to the controller, so Notebook and Index0
re-implemented them. They are `ListService` methods now (`get()`, `can()`, `contents()`, and the
item methods), for any module. `requireTitle()` threw a plain `Exception` so the forms could
catch it; the lib throws `HTTPError(400)`, and the forms catch `\Exception` and show its `detail`.
A model's failure inside an item endpoint was answered with `error()` (400, `{success: false,
error}`); it is an `HTTPError(500)` now. `update-item` with nothing to change answered 400 "No
fields to update" through `error()`; it is `HTTPError(400, 'Nothing to update')`.

---

## 2026-11 — HTTPError: the detail reaches JSON clients

### A JSON error body without the detail, carrying the endpoint's data — REPLACED

```
// gone
{"message":"Not Found","data":{...whatever the endpoint had set...},"status":"error","code":404}

// now: HTTPError::toArray()
{"status":"error","message":"Not Found","detail":"Item not found","code":404}
```

`renderError()` exported the HTML error page as the JSON body, and the reason phrase then
replaced the message it built from it, so `throw new HTTPError(404, 'Item not found')` reached
a JSON client as "Not Found" alone. Any `$this->data` the endpoint had set before it threw went
along under `data`. A module's `view/error{code}.php` lost the detail too.

`message` is still the reason phrase. `detail` is new, and absent when the error has none. The
body has no `data`, and a module's error view no longer affects it. A client that shows errors
to people reads `detail` first, then `message`.

The docs said the detail showed "in DEVMODE and JSON responses". The error page has always shown
it in production too, and JSON now does the same, whatever DEVMODE is.

### HTML in an HTTPError detail — ESCAPED

The generic error page printed the detail as raw HTML. It is now escaped, with newlines turned
into line breaks, so a detail is plain text: write `"\n"`, not `"<br>"`. Jackpack's two 503
details were the only ones with markup, and use a newline.

---

## 2026-11 — Lists: suggestions count every add; unused model methods removed

### Suggestions recorded on clear and removed on delete — REPLACED

New text became a suggestion only when its item was checked off and then cleared:
`clearCompleted` passed each checked item to `addFromItem()` before deleting it. Adding an item
whose text was already a suggestion counted a use (`incrementUsage()`), and deleting an item
deleted its suggestion (`removeByText()`).

Now every add calls `ListSuggestion::addFromItem()`, which adds the suggestion or counts one more
use, whether it was picked or typed. `/lists/add-item` and Index0's `ListsModuleStore::add()` both
do. Checking off, deleting and clearing leave suggestions alone. `usage_count` is the number of
times the text was added. See `modules/Lists/CLAUDE.md`.

**Why.** The lists are mostly groceries. "Milk" picked from the suggestions and "milk" typed are
the same purchase, and buying it or deleting it from this week's list shouldn't forget it.

### `ListSuggestion::incrementUsage()`, `removeByText()` — REMOVED

Use `addFromItem()` to count a use. Nothing deletes a single suggestion; an admin page for that is
planned.

### `ListModel::getSharedUsers()`, `ListPermission::update()`, `ListPermission::find()` — REMOVED

Nothing called them. For `update()` use `grant()`, which inserts or updates the group's row; for
`find()` use `getByList()`. `getSharedUsers()` returned the email address of everyone a list was
shared with, and has no replacement.

### `list_permissions.updated_at` — DROPPED; Lists timestamps `timestamp` → `datetime`

The other `created_at`/`updated_at` columns of `lists`, `list_items`, `list_permissions` and
`list_item_suggestions` are `datetime NOT NULL`. `list_permissions` keeps only `created_at`:
a group's grant is replaced in place, and nothing read when it last changed.

### `list_items` indexes `idx_list_id`, `idx_position`, `idx_completed` — REPLACED

One composite index, `idx_list_completed_position (list_id, completed, position)`, matches how a
list's items are read (unchecked first, then by position), and serves the foreign key on
`list_id`.

### `idx_list_id` on `list_permissions` and `list_item_suggestions` — DROPPED

Each table's unique key starts with `list_id` (`unique_list_group`, `idx_list_suggestion`) and
serves the same lookups and the foreign key.

### `modules/Lists/assets/js/form.js` — MOVED into `lists.js`

The create/edit form's group checkboxes script. The framework loads only `{module}.js` and
`{endpoint}.js`, and the form is served by `create` and `edit`, so `form.js` never loaded and
a newly shared group's Update and Delete boxes stayed hidden.

---

## 2026-10 — Comfy: generations are assembled from a model and LoRAs

### `{meta, workflow}` preset files, one per workflow — REPLACED

`modules/Comfy/workflows/{id}.json` was one ComfyUI graph with its node mappings in `meta`
(`prompt_node`, `negative_node`, ...). A LoRA or a different sampler meant another whole file.

```
// gone
{"meta": {"name": ..., "prompt_node": "5"}, "workflow": {...}}

// use: one file per kind of model
{"meta": {"name", "description", "lora_match", "model_match"},
 "graphs": {"generate": {"prompt_node": "5", ..., "workflow": {...}}, "edit": {...}},
 "tunings": {...}, "presets": {...}}
```

The node mappings moved from `meta` onto each graph, unchanged in name. LoRAs are chosen per
request and chained onto the graph by `WorkflowAssembler`; settings that belong to a LoRA live
in a `tuning`. See `modules/Comfy/CLAUDE.md`.

**Why.** The LoRAs on the ComfyUI server are discoverable (`/models/loras`), so a file per
model-and-LoRA combination was a list someone had to keep in step by hand.

### The "tuned settings" checkbox, and a tuning's size — REPLACED

A LoRA with settings of its own (the Qwen Fix LoRA) was added from the LoRA list like any
other, and a checkbox under the list switched its tuning on. The tuning also set the output
size (`tunings.{id}.latent`, 1216x1600).

```
// gone
"tunings": {"fix": {"loras": [...], "sampler": {"sampler_name": "seeds_2", ...}, "latent": {"width": 1216, "height": 1600}}}

// use: a tuning is a "base" the page offers by name; the size is the request's
"tunings": {"fix": {"name": "Fix · er_sde", "loras": [...], "sampler": {"sampler_name": "er_sde", ...}},
            "fix_euler": {...}}
"graphs": {"generate": {"sizes": {"multiple": 32, "max_side": 2048, "tiers": [...]}, ...}}
```

The page shows a model's tunings as **Base LoRA** choices ending in "None". Naming a tuning in
a request (`tuning: 'fix'`) brings its LoRA; the LoRA list is for layering more. The size is
sent as `width` and `height`, chosen in the size panel on the prompt card, and a tuning no
longer has `latent`.

**Why.** `seeds_2` evaluates the model twice per step: about 4m 40s where `er_sde` and `euler`
take 2m 25s for images that are not visibly worse (same-seed comparison, 12 October 2026). And
a size tied to a LoRA meant no other size could be chosen at all.

**Watch for:** the Fix LoRA at cfg 1 without its tuning is worse than no LoRA, which is why
the page no longer offers it bare. The API still allows it (`loras` with `tuning: ''`).

### A fixed model list, one entry per definition — REPLACED

`GET /comfy/models` listed the definitions in `workflows/`, each loading the one model file
written in its graph, whether or not the ComfyUI server had it.

The list now comes from the server: one entry per model file there that a definition runs
(`/models/diffusion_models`, `/models/checkpoints`), so `id` is no longer unique within it.
Send `model` (the definition id) with `file`; an id on its own still works and loads the
definition's default file. A file no definition claims is reported under `unavailable`.

**Watch for:** code that finds a model entry by `id` alone, or uses `id` as a select value.
The page uses `modelKey()` / `selectModel()` for that.

### The name "GSL Starter 1.1" — RENAMED

The definition `gsl_starter_1_1` is named "Z-Image Turbo", after the model it runs, now that
the list is of models. The id is unchanged (the Discord bot stores it), and generations made
before the rename keep the old name in `workflow_name`.

### `meta.type: "edit"` and separate `*_edit.json` files — REMOVED

An edit is a model's `graphs.edit`, run whenever the request carries an image. The ids
`qwen_image_2_1_edit` and `qwen_image_2_1_fix_edit` no longer exist; `qwen_image_2_1_fix` is
now a preset of `qwen_image_2_1` and still works as a `workflow` id. The page's separate "Edit
Workflow" dropdown is gone with them.

### Per-generation status polling with a time limit — REPLACED

The page polled `GET /comfy/status/{prompt_id}` for one generation and gave up after 10
minutes, leaving the row `processing` for good if the page was closed or the job outlived it.

Use `GET /comfy/pending`: one request syncs every unfinished generation of the user against
ComfyUI's queue and history, and the page calls it on load as well as while anything is in
flight. `status/{prompt_id}` remains for API callers and runs the same sync for one row.

**Watch for:** code that treats "no history entry" as "still running". A job in neither
ComfyUI's queue nor its history is lost (ComfyUI restarted), and `GenerationSync` fails it.

### Result cards and the lightbox — REMOVED

A new or clicked generation used to be drawn as a card at the top of the page, and its image
opened in a click-to-dismiss overlay. Generations now open in place in the one list, and the
image opens in the viewer (zoom, pan, step between images).

### `new Error(code)` in `Comfy::image()` — REPLACED

Now `throw new HTTPError(code, $detail)`, as everywhere else.

### One expansion instructions file per graph — REPLACED

A graph's `expand` was the name of one file in `workflows/expand/`, the system prompt its
prompts were expanded by, read by `PromptExpander::instructions()`.

```
// gone
"expand": "qwen_image_2_1_t2i.txt"                  // workflows/expand/qwen_image_2_1_t2i.txt
new PromptExpander($ollama, $comfy, $instructionsPath)
$expander->instructions($graph['expand'])           // the text, or null

// use: a folder of instruction sets, one .txt file each, and which is the default
"expand": {"folder": "qwen_image_2_1_t2i", "default": "official"}
                                                    // workflows/expand/qwen_image_2_1_t2i/official.txt
new PromptExpander($ollama, $comfy)
$catalog->instructionSets($graph)                   // [{id, name}], the default first
$catalog->instructions($graph, $id)                 // {id, name, text}, or null
```

The file that was `qwen_image_2_1_t2i.txt` is `qwen_image_2_1_t2i/official.txt`, unchanged.
Requests choose a set with `expand_instructions`; left out, the default, so a caller that never
sent it gets what it got before. In `GET /comfy/models` a mode's `expand` was `true` or
`false` and is now the list of sets, or `null` where prompts are not expanded.

**Why.** How long and how inventive an expansion is comes from the instructions, so trying
another wording has to be a matter of adding a file and picking it on the page.

### A folder of expansion instructions per model — REPLACED

Each image model's graph named a folder of its own in `workflows/expand/`, and Z-Image's two
sets were called `english` and `official`.

```
// gone
"expand": {"folder": "qwen_image_2_1_t2i", "default": "official"}   // Qwen
"expand": {"folder": "z_image_t2i", "default": "english"}           // Z-Image, Z-Image Turbo
"expand": {"folder": "sd_checkpoint_t2i", "default": "tags"}        // a checkpoint

// use: one folder that every image model offers, and a default of its own
"expand": {"folder": "image", "default": "official"}                // Qwen
"expand": {"folder": "image", "default": "z-image"}                 // Z-Image, Z-Image Turbo
"expand": {"folder": "image", "default": "tags"}                    // a checkpoint
```

Every file moved to `workflows/expand/image/` unchanged. Qwen's ids and `tags` are the same;
Z-Image's `english` is `z-image` and its `official` is `z-image_chinese`, since one folder
cannot hold two `official`s. No stored generation named the old Z-Image ids. A browser that
remembered one for a model gets that model's default instead.

**Why.** The instructions are written for the language model that expands the prompt, not
for the image model, and any of them can be tried on any image model. A set added to the
folder is a choice for all of them.

---

## 2026-08 — Auth and permission overhaul

### `$_SESSION['user_settings']` — REMOVED

Was a map of the user's `user_settings` rows, written into the session by the login path
and read directly by permission checks.

```php
// gone
!empty($_SESSION['user_settings']['allow.admin'])

// use
\Zero\Core\User::hasPermission('allow.admin')   // current user
$user->can('allow.admin')                       // a specific user
$user->settings()                               // the whole map
```

**Why.** A session cache made a *revoked* permission keep working until the user logged
out. `User::can()` / `User::settings()` read through to `user_settings` and memoize per
`User` instance for the life of one request, so a revocation denies on the very next
request.

**Watch for:** a doc or comment telling a tester to log out and back in for a grant to
take effect. That has not been true since this change — the next request is enough.

### `$_SESSION['auth_level']` — REMOVED

```php
// gone
$_SESSION['auth_level'] ?? 0

// use
\Zero\Core\User::current()?->authLevel() ?? 0
```

Same reasoning as `user_settings`, of which it was one key (`auth.level`).

### `User::currentId()` fallback to `$_SESSION['user']['id']` — REMOVED

`currentId()` reads `$_SESSION['user_id']` and nothing else.

**Why.** The fallback returned the **provider's** user id — a GitHub or GitLab integer, a
Discord snowflake, a Keycloak UUID — not a local `users.id`. `User::establish()` now
overwrites `$_SESSION['user']['id']` with the local id so the key means one thing on both
of its branches, but nothing should read identity from there. Ask `currentId()`.

### `User::getPermission()`, `getAllPermissions()`, `setPermission()` — REMOVED

Had zero callers. `User::hasPermission()` is the one permission static that survives;
`$user->can()` / `$user->setSetting()` are the instance equivalents.

(`modules/User/User.php` has its own unrelated `getAllPermissions()`. Different class.)

### `UserProfile` class — REPLACED by the `Zero\Core\User` instance API

```php
// gone
UserProfile::store($userId, $values);
UserProfile::get($userId);

// use
$user->storeProfile($values, $fieldDefs);
$user->profile();
```

`user_profile` has a composite primary key `(user_id, field_key)`, and `Model` assumes a single
auto-increment pk with `lastInsertId()` semantics — which is why these methods talk to the table
with hand-written SQL rather than through the inherited column-whitelist machinery. Sensitive
fields are encrypted per-row on write and decrypted on read, shaped after `Zero\Module\Auth\OAuth\Token`.

Some test files still carry `UserProfile` in their names and comments.

### Auth adapters for nine dormant providers — DELETED

Facebook, Twitter, Instagram, Foursquare, Yelp, PayPal, LinkedIn, Pinterest and Tumblr. Deleted in
`5dbb57b` ("cleaned up Auth") and recoverable only from git history:

```bash
git show 5dbb57b^:Auth/adapters/TwitterAdapter.php
```

Their `$smids` indices are deliberately still reserved in `modules/Auth/Auth.php` so the via codes
stay stable and a revival keeps its old URLs. `loadAdapter()` 404s on all of them.
`KeycloakAdapter` is a different case — deprecated and unmaintained, but still present and still
reachable at `/auth/keycloak-test`.

### `#[RequireAuthLevel]` — SHELVED, do not add new ones

Use `#[RequirePermission]`. The attribute still exists and five bare gates still use it,
but **it must never be paired with a `#[RequirePermission]`**, and no new bare gate should
be added.

**Why.** `auth.level` is a separate `user_settings` key from the `allow.*` grants, so a
level gate beside a permission gate silently vetoed permissions that had been deliberately
granted — only user 1 has ever held an `auth.level` row, so user 9 held `allow.admin` and
was refused anyway. Every such pairing was deleted on 2026-08-16: `Admin`, `System`,
`Postgres`, `Bingo`, `HelloWorld::permittedHello()`, `NetworkMap::save()`/`load()`.

Full reasoning and the condition for un-shelving: `core/attribute/AUTH_GATE_CONSISTENCY.md`
§ "The third gate", and `docs/attributes/RequireAuthLevel.md`.

### `#[RequirePermission]` method level: REPLACE → ADDITIVE

A method-level `#[RequirePermission]` used to **replace** the class-level one for that
endpoint. It now **adds** to it — both run, and the effective requirement is the union.

No module's attributes changed; they started meaning what they already read as. Under the
old semantics `modules/Receipts` declaring `allow.receipt.view` on the class and
`allow.receipt.edit` on ten methods meant those methods required *edit alone*.

`Application::isAdditive()` names this class as the sole exception. Every other attribute
keeps replace semantics, because they name a destination or a constraint and two of those
contradict each other.

### Hand-rolled session teardown — REPLACED by `User::logout()`

`Auth::logout()` and `Jackpack::signout()` each cleared the session their own way and had
drifted: one left the cookie, the other left `$_SESSION` populated for the rest of the
request. `User::logout()` is the single teardown — memo, `$_SESSION`, cookie, destroy.
Callers keep only their redirect.

### `modules/Push` "session/cookie/localStorage" user resolution — REMOVED (security)

```php
// gone — both fallbacks were fully attacker-controlled
$userId = User::currentId()
    ?? $_COOKIE['unisolu_user_id']   // client-settable cookie
    ?? $input['user_id'];            // client-supplied request body

// use
$userId = User::currentId();         // behind #[RequireLogin]
```

`subscribe`, `unsubscribe`, `status` and `click` carried **no gate at all** and resolved the
acting user this way, so an unauthenticated caller could name any user id. Verified live
before the fix:

```
$ curl -s "https://unisolu.com/push/status?user_id=1"
{"data":{"subscribed":true,"count":2},"status":"unknown","code":200}
```

`subscribe` upserted into `push_subscriptions` with the caller-supplied `user_id` and the
caller's own endpoint and keys — registering an attacker's browser to receive another user's
push notifications. `unsubscribe` was the matching deregistration primitive.

All four now carry `#[RequireLogin]`. `vapidKey()` stays public — a VAPID *public* key is
public by definition, and `push-manager.js` fetches it on anonymous page loads — which is why
the gate is per-method rather than class-level.

### `unisolu_user_id` cookie — UNUSED, safe to delete

`User::establish()` still sets it; **nothing reads it.** Its only reader was `modules/Push`,
which resolved the acting user from it — a client-settable plaintext id trusted as proof of
identity. That was removed on 2026-08-16.

It was never authentication. Do not add a reader. Removing the `setcookie()` call in
`core/User.php` is an open cleanup.

### `$_SESSION['framePath']` — REMOVED, replaced by `Response::adoptFrameFrom()`

`Module::defineBasePaths()` closed by writing the resolved frame into the session, so that
a shared module could render in the styling of whatever sent the visitor to it.
`modules/User/User.php` was the only reader.

```php
// gone
if($_SESSION['framePath']){
    $this->framePath = $_SESSION['framePath'];
}

// use — in the module constructor, BEFORE parent::__construct()
$this->adoptFrameFrom($_SERVER['HTTP_REFERER'] ?? null);
```

**Why.** Three things were wrong with it.

- It ran on **every** request, so every visitor — bots included — was handed a session
  file. That is one of the two writes behind the accumulated session store described in
  `SESSION_LIFECYCLE_HANDOFF.md`.
- The stored value was the frame of the last module *that browser* touched. Another tab or
  an hour ago counted, so a shared module frequently rendered in a frame nothing on the
  current path had asked for.
- The two cases it was written for were not served by it. Error pages never needed it —
  `Response::renderError()` runs on the live module instance, whose `framePath` is already
  resolved. `modules/AccessRequest` never read it at all.

`adoptFrameFrom()` derives the frame from the referring URL, so it is scoped to one
handoff, and writes no state anywhere.

**Watch for:** a module that wants inherited styling and does not call `adoptFrameFrom()`.
It renders in the site default frame rather than inheriting. That is the deliberate
fallback whenever the `Referer` header is absent, stripped, or off-site.

### Declared path properties on `Response` — NOT the approach; use `#[AllowDynamicProperties]`

`Response` carries `#[\AllowDynamicProperties]`, and the attribute is inherited, so `Module`
and every module are covered by that one line. Assigning view data to undeclared properties
is how this framework works and is not being migrated away from.

Do not "fix" a dynamic-property deprecation by declaring the properties on a class that
extends `Response`. `modules/Receipts/Receipts.php` declares its view properties and its
comment gives the pre-attribute reasoning; that block is now optional, not a pattern to
copy.

### `user_view` — NO LIVE READERS, retained as a compatibility view

`user` moved out of the EAV entity system to `Zero\Core\User` over a real `users` table.
No PHP file joins `user_view` any more. It is kept only so an old query doesn't hard-fail.

**Footgun:** creating an entity type named `user` via `/admin/entity/create` replaces the
view with an empty EAV pivot, and login keeps working either way, so a smoke test won't
catch it. Recovery is a manual re-run of `core/sql/migrations/user_view.sql`. See `entity/CLAUDE.md`.

---

## Older

### `core/Client.php`, `Restricted.php`, `Whitelist.php`, `Application.min.php`, `core/tools/` — DELETED

All five were still described in `core/README.md` long after they went. Nothing references any
of them.

| Gone | Where its job went |
|---|---|
| `Client.php` — session info, `getGeoLocation()`, `getGravatarURL()` | `Zero\Core\User` (session facade) |
| `Restricted.php`, `Whitelist.php` — the old ACL | `core/attribute/` gates + `user_settings` keys |
| `Application.min.php` — a single-file build of the core | abandoned |
| `tools/` — unhooked debugging helpers | `plugin/DevToolbar`, `Zero\Core\Console` |

`core/SQLAdapter.php` is a related loose end: it survives but extends an `Adapter` class that
does not exist in this repo, and nothing references it. It would fatal if autoloaded.

### `Application` constructor options — REMOVED

`new Application(['autorun' => false, 'extensions' => …])` and `$app->fetchExtensions()` are gone.
The constructor takes no arguments and starts output buffering only; the bootstrap always calls
the four methods explicitly.

```php
// gone
$app = new Application(['autorun' => false]);
$app->fetchExtensions($opts);

// use
$app = new Zero\Core\Application();
$app->registerAutoloaders();   // optional string|callable|array of extra autoloaders
$app->parseRequest();
$app->defineConstants();       // optional array of extra constants
$app->run(Request::$module, Request::$endpoint, Request::$args);
```

### Endpoint signature: one `$args` array → splatted parameters

`Application::run()` calls `$module->{$endpoint}(...$args)`. An endpoint declares **named
parameters matching the URL segments**; it does not take a single `$args` array.

```php
// gone
public function view($args) { $id = $args[0]; }

// use
public function view($id = null) { }
```

Extra segments are ignored. A missing required one raises `ArgumentCountError`, which `run()`
catches and returns as a 400. `__call()` magic methods are the exception — there, `$args[0]` is
still the original URL args array.

### `\Zero\Core\Error` — DEPRECATED, use `HTTPError`

```php
// deprecated
new Error(404, 'Item not found');

// use
throw new \Zero\Core\HTTPError(404, 'Item not found');
```

`HTTPError` bubbles to `Application::run()` and calls `renderError()` on the live module,
so the error keeps the module's frame. `$detail` shows on the error page and reaches JSON
clients as `detail`.
`new Error()` exits immediately instead, taking the frame with it.

`core/Error.php` still exists and still works. **Not yet migrated:** `modules/Admin/Admin.php`
(six call sites) and `modules/Index/view/zero-framework.php`, which warns against
`new Error()` in one section and still teaches it as the way to return an HTTP error in two
others (lines 158 and 476). `modules/Jackpack` was migrated on 2026-08-22, and
`modules/Lists` in 2026-11.

### `Request::convertJSONtoPOST()` — now gated on Content-Type

It used to decode any request body as JSON into `$_POST`. It now returns early unless the
request declares `application/json`.

**Why.** Raw binary uploads (`image/jpeg`, `application/octet-stream`) must reach
`php://input` untouched; decoding them as JSON threw `JSON_ERROR_UTF8` on perfectly valid
bytes. An endpoint that posts JSON without the header does not get `$_POST` populated —
send the Content-Type. See `modules/Doorbell/CLAUDE.md`, which this bit.

### `Application::checkForAttributes()` — RENAMED to `checkAttributes()`

Private instance method → **public static**, so the other two dispatchers can call it:
`Module::__call()` for submodules and `AdminResponse::routeToModuleAdmin()` for module-admin
controllers. Until they did, every attribute declared inside `modules/*/submodule/` was dead
— an anonymous `GET /ttrpg/hexmap/dm` returned 200 with `#[RequireAuthLevel(1)]` on the
method.

```php
// gone
$this->checkForAttributes($Module, $endpoint);

// use
\Zero\Core\Application::checkAttributes($class, $endpoint);
```

Each dispatch level checks its own `(class, endpoint)` pair, so gates are additive down the
nesting chain and nothing is evaluated twice.
