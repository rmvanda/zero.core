# Admin Architecture

The Admin module (`core/modules/Admin/`) is the `/admin` hub: the dashboard and the router. Its
own screens (Users, Groups, Modules, Firewall) are its submodules. Every other admin screen
belongs to the module it administers, which offers it from its own `Admin/` folder. Admin lists
the screens it finds and routes `/admin/{name}/...` to them.

- A module never depends on the Admin module. A site without Admin loses the screens and nothing
  else.
- Every admin controller extends `AdminResponse`, which answers 404 to anyone without
  `allow.admin`.
- Admin CSS and JS are inlined, never served by URL. See `core/docs/ADMIN_ASSETS.md`.
- Sessions, users, groups and capabilities (`allow.*`) are core's. See `core/docs/PERMISSIONS.md`.

---

## Where an admin screen lives

> **A feature's admin screen lives in its own module's `Admin/` directory as a module admin
> controller, whether or not the module has a public controller.**
>
> **Admin's `submodule/` directory is for Admin's own screens (Users, Groups, Modules, Firewall).**

| Feature | Location |
|---------|----------|
| Link management | `modules/Links/Admin/LinksAdmin.php` |
| ComfyUI generations | `modules/Comfy/Admin/ComfyAdmin.php` |
| LLM thread viewer | `modules/Llm/Admin/LlmAdmin.php` |
| AWS billing | `modules/Billing/Admin/BillingAdmin.php` |
| Plugin manager | `modules/Plugins/Admin/PluginsAdmin.php` |
| API key config | `modules/ApiConfig/Admin/ApiConfigAdmin.php` |
| WebSocket monitor | `modules/Ws/Admin/WsAdmin.php` |
| Entity types, records, event log | `core/modules/Entity/Admin/EntityAdmin.php` |
| Push notifications | `core/modules/Push/Admin/PushAdmin.php` |
| Users, their capabilities and settings | `core/modules/Admin/submodule/Users/` (Admin's own screen) |
| Groups and their members | `core/modules/Admin/submodule/Groups/` (Admin's own screen) |
| Which modules are on, and their order | `core/modules/Admin/submodule/Modules/` (Admin's own screen) |
| Firewall rules | `core/modules/Admin/submodule/Firewall/` (Admin's own screen) |

**Namespaces:**
- Module admin controllers: `Zero\Module\{Module}\Admin\{Module}Admin`, reached through `Admin::__call`
- Admin submodules: `Zero\Module\Admin\{Name}`, reached when `Admin::__call` falls through to `Module::__call`

---

## Core Classes

### `AdminResponse` (`core/AdminResponse.php`)

Abstract base class of every admin controller. Extends `Module`.

| Method | Purpose |
|--------|---------|
| `__construct()` | Answers 404 without `allow.admin` (see Access); removes the public asset link `Module::__construct()` made |
| `getStylesheets()` / `getScripts()` | Inline the page's admin assets (see `core/docs/ADMIN_ASSETS.md`) |
| `loadInlineAsset()`, `loadAdminStyles()`, `loadAdminScripts()` | Inline assets from a view (see `core/docs/ADMIN_ASSETS.md`) |
| `getAdminModules()` | The offerings the user may see |
| `done($message, $url, $data = [])` | Finishes an action that worked (see Actions) |
| `setBreadcrumbs(array $crumbs)`, `addBreadcrumb($label, $url = null)` | The page's breadcrumbs (see Breadcrumbs) |
| `routeToModuleAdmin($module, $method, $params)` | Call an offering's endpoint |

### `Admin` (`core/modules/Admin/Admin.php`)

`Zero\Module\Admin`, `#[RequirePermission('allow.admin')]` on the class. It holds the dashboard
and routes everything else through `__call`.

```
/admin                    → Admin::index()
/admin/users              → Admin::__call('users', ...) → Users::index()         (Admin's own)
/admin/users/edit/5       → Admin::__call('users', ...) → Users::edit('5')
/admin/groups             → Admin::__call('groups', ...) → Groups::index()
/admin/modules            → Admin::__call('modules', ...) → Modules::index()
/admin/firewall           → Admin::__call('firewall', ...) → Firewall::index()
/admin/links              → Admin::__call('links', ...) → LinksAdmin::index()    (a module's)
/admin/links/edit/5       → Admin::__call('links', ...) → LinksAdmin::edit('5')
```

The `/admin` dashboard has two sections, and the view writes no link itself. "Users and access"
holds one card per screen `Admin::ownScreens()` finds. "Modules" holds one card per offering
`getAdminModules()` returns, so a module's screens appear there with no change to Admin.

---

## Admin's own screens

```
core/modules/Admin/submodule/Users/
├── Users.php               # Zero\Module\Admin\Users, extends AdminResponse
├── admin-config.php        # The dashboard card
├── view/
└── assets/css/, assets/js/ # Inlined: users.css on every screen, {method}.css on that one
```

`Admin::ownScreens()` lists every folder in `submodule/` that holds its controller and an
`admin-config.php`. The file returns the card:

```php
<?php
return [
    'label' => 'Users',
    'icon' => 'group',
    'description' => 'Accounts, their capabilities and settings',
    'order' => 10,      // among Admin's own cards, lowest first, then by name
];
```

| Screen | URLs |
|--------|------|
| Users | `/admin/users`, `/admin/users/edit/{id}`; POST `set-capability`, `set-setting`, `remove-setting`, `disable`, `enable` |
| Groups | `/admin/groups`, `/admin/groups/members/{id}`, `/admin/groups/form[/{id}]`; POST `save[/{id}]`, `delete/{id}`, `add-member`, `remove-member`, `set-member-role` |
| Modules | `/admin/modules`; POST `set` (see The modules screen) |
| Firewall | `/admin/firewall`; `list`, POST `unblock` |

A capability (an `allow.*` row in `permission`) and a user setting (a row in `user_settings`)
are different things with different endpoints: `set-capability` grants or revokes, `set-setting`
and `remove-setting` write `user_settings` through `User::setSetting()` and
`User::removeSetting()`.

The users list is paged, 25 to a page. Its model, `Zero\Module\Admin\Model\UserList`, runs its
own SQL against `users`, `user_group`, `user_group_member` and `permission`: the one exception
to the rule that a module reads those through `Zero\Core\Access`, made because this is the only
screen that asks about every user at once.

One of Admin's own screens wins over a module of the same name: a module called `Users` cannot
take over `/admin/users`. When such a module offers an admin screen, `Admin::__call` logs a
warning that the offering is unreachable.

---

## Offering an admin screen

```
modules/YourModule/
├── YourModule.php              # Public controller (optional)
├── view/                       # Public views
└── Admin/
    ├── YourModuleAdmin.php     # Admin controller (extends AdminResponse)
    ├── admin-config.php        # Optional: the dashboard card
    ├── view/                   # Admin views
    └── assets/                 # Admin-only assets, inlined (see core/docs/ADMIN_ASSETS.md)
```

```php
<?php
namespace Zero\Module\YourModule\Admin;

use Zero\Core\AdminResponse;

class YourModuleAdmin extends AdminResponse {
    public function index() {
        $this->setBreadcrumbs([
            ['label' => 'Your Module', 'url' => '/admin/your-module']
        ]);
        $this->build(__DIR__ . '/view/index.php');
    }

    public function edit($id) {
        $this->item = $this->model->find($id);
        $this->build(__DIR__ . '/view/edit.php');
    }
}
```

Everything else (CRUD, pagination, filtering) is an ordinary Zero controller; see the
`/zeromodule` skill. `core/modules/Module/Admin/` is a working example, and
`./mkmod Name --admin` scaffolds one.

**`admin-config.php`** returns the card. Every key is optional:

```php
<?php
return [
    'label' => 'Manage Links',              // default "{Module} Management"
    'icon' => 'link',                       // Material Symbol name, default "settings"
    'description' => 'Manage shared links', // default ""
    'permissions' => ['allow.admin']        // default ['allow.admin']
];
```

`permissions` lists the same keys that gate the offering's class. Whether the module is on, and
where its card sits, are set in `app/modules.ini`, never in `admin-config.php`.

---

## Discovery

`AdminResponse::getAdminModules()` walks `Modules::all()` (every module folder in every root) and
lists a module when all of these hold:

1. It is not Admin itself, it has an `Admin/` folder and `Modules::enabled()` is true for it.
2. The class `Zero\Module\{X}\Admin\{X}Admin` exists. An `Admin/` folder without it (one holding
   only `view/`, say) is not an offering. A class that throws while loading is logged with
   `Console::warn()` and left out; the other cards still show.
3. The logged-in user holds every key in the card's `permissions`, checked with `Session::can()`.

Cards are sorted by `Modules::order()` (the `[order]` section of `app/modules.ini`), modules
without an order last, then by name. Each card carries `name`, `slug`, `label`, `icon`,
`description`, `order`, `permissions` and `url` (`/admin/{slug}`).

---

## Routing

`/admin/links/edit/5`:

1. `Application::run()` resolves `Admin`, checks its `#[RequirePermission('allow.admin')]` and
   calls `links('edit', '5')`, which `Admin` does not have.
2. `Admin::__call('links', ['edit', '5'])` turns `links` into `Links`. It looks for
   `Zero\Module\Links\Admin\LinksAdmin` only when `Application::isModuleName()` and
   `Modules::routable()` both pass, so the admin file of a module that is switched off is never
   loaded. A name that is one of Admin's own screens, or that has no such class, goes to
   `Module::__call` (Admin's submodules, then `Response::__call`).
3. `routeToModuleAdmin('Links', 'edit', ['5'])`:
   - repeats the `Modules::routable()` check and the class check (404
     `Admin interface not found for module: ...`),
   - converts a kebab-case method to camelCase (`create-workflow` → `createWorkflow`),
   - answers 404 `Admin method not found: ...` unless the method is routable (below),
   - runs `Application::checkAttributes()` on the offering's class and method, so the offering's
     own attributes (`#[RequirePermission]`, `#[AllowedMethods]`, ...) apply,
   - constructs `LinksAdmin` and calls `edit('5')`.

**Routable methods.** Only a public, non-static method that is not a constructor or magic method
and is declared outside the `Zero\Core\` namespace is an endpoint. Protected helpers and methods
inherited from the framework (`loadConfig`, `loadInlineAsset`, ...) answer 404 before the
controller is constructed.

**Names.** URL → class: `tech-stack` → `TechStack` (each word capitalised), in `Admin::__call`.
Class → URL slug: `TechStack` → `tech-stack` (a hyphen between a lowercase and an uppercase
letter, then lowercase), in `getAdminModules()`.

---

## Access

Capabilities themselves are described in `core/docs/PERMISSIONS.md`. The admin layers are:

1. **`AdminResponse::__construct()`**: anyone without `allow.admin`, signed in or not, gets the
   404 an unknown URL gets: the same page, from a plain `Response`, with the same detail. There
   is no login redirect and no access request, so nothing says an admin lives at the URL. Every
   admin controller inherits this, so an offering always needs `allow.admin` whatever else it
   asks for.
2. **`#[RequirePermission('allow.admin')]` on `Admin`** and on each of its own screens.
3. **Attributes on an offering or submodule**, class or method level
   (`#[RequirePermission('allow.aws.bill.read')]` on `BillingAdmin`): checked before the controller is constructed, by
   `routeToModuleAdmin()` for an offering and by `Module::__call` for a submodule.
4. **The card's `permissions`**: decide whether the card shows. They do not gate the URL; the
   attributes do.

---

## Actions

An endpoint that changes something is POST only and carries `#[AuditLog]`.

- **It worked**: `$this->done('Group saved', '/admin/groups', ['group_id' => $id])`. A JSON
  client gets `{"data": {"success": true, "message": ..., ...}}`; a browser is redirected to the
  URL, so a reload does not post the form again.
- **It did not**: `throw new HTTPError(409, 'A group with that name already exists')`. A browser
  gets the error page, in the admin frame, with the detail; a JSON client gets it as `detail`.
  An endpoint never redirects after a failure.

---

## Breadcrumbs

Every admin page prints its breadcrumbs above the view (`AdminResponse::beforeView()`); a view
prints none. A screen that sets nothing gets "Admin", then its own card's label. The dashboard
has none.

```php
$this->addBreadcrumb($group->group_name);              // Admin › Groups › Family
$this->setBreadcrumbs([                                // Admin › Links › Edit
    ['label' => 'Links', 'url' => '/admin/links'],
    ['label' => 'Edit'],
]);
```

The last crumb is the page itself and is not a link.

---

## The modules screen

`/admin/modules` (`core/modules/Admin/submodule/Modules/`, assets `modules.css`, `modules.js`; wording in
`core/modules/Admin/strings/admin.ini`, `ADMIN_MODULES_*`) lists `Modules::all()` ordered by tier
(required, optional, example, site) then name. Each row shows the real folder name, the tier,
where it lives (`core` under `core/modules/`, otherwise `site`), on or off, whether that is the
default or a departure, and the order.

- A required module shows no switch.
- A module whose name is an INI keyword (`On`, `Off`, `Yes`, `No`, `True`, `False`, `None`,
  `Null`) shows no controls and a note: its name cannot be a key in `app/modules.ini`.
- Each row's form carries the hash of `app/modules.ini` as the screen was drawn.

`POST /admin/modules/set` (`#[AuditLog('module_setting_change')]`, POST only, `module` and `hash`
required; `enabled` is `on`, `off` or `default`; `order` is a whole number from 0 to 9999, empty
to remove it; a field that is missing changes nothing):

- **409** with the reload text when the file no longer has that hash, so a screen left open
  never overwrites another admin's change.
- **400** for a value that is not valid, **404** for a module that does not exist.
- Otherwise the change goes to `Zero\Core\ModulesIni`, the only writer of `app/modules.ini`.
  It checks that `Modules` reads its text back as the intended entries before it replaces the
  file, and refuses with **500** (the path is in the error text) when it cannot write.
- A JSON client gets the result as JSON. A form post is redirected to the screen, which shows a
  "Saved" line (`?saved=1`), or gets the screen again with the error above the table.

The write is a temp file in the file's folder renamed over the file, so the **folder** must be
writable by the web server user. An existing file keeps its permission bits, a new one is 0664,
and a file with no write bit at all is treated as deliberately read-only and refused. A save
changes `app/modules.ini`, which a site tracks in git; the site commits the change. The lock file
`modules.ini.lock` and the `.modules.ini.*.tmp` temp files are git-ignored.

---

## Debugging

- `var_dump($this->getAdminModules())` in `Admin::index()` shows what discovery returns.
- `var_dump(Session::can('allow.admin'))` shows whether the logged-in user is an admin.
- `/admin` is the dashboard, `/admin/{name}` one of Admin's own screens or an offering,
  `/admin/invalid` a 404. Every `/admin` URL is a 404 for a user without `allow.admin`.
- An offering that is missing from the dashboard: check that the module is on
  (`/admin/modules`), that the class name and namespace are exact, and the log for
  `Admin offering {Module} failed to load`.
