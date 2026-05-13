# Admin Architecture

Pluggable admin system for the Zero Framework.

- **Auto-Discovery**: Modules with an `Admin/` directory appear on the admin dashboard
- **Secure by Default**: `allow.admin` permission enforced at base class + framework layers
- **Inline Assets**: Admin CSS/JS served inline; never exposed as public URLs (see `ADMIN_ASSETS.md`)
- **Flexible Routing**: Clean URLs with automatic controller routing

---

## Module Admin Controller vs Admin Submodule

> **If a public module exists for the feature, its admin interface MUST be a Module Admin Controller inside that module's `Admin/` directory.**
>
> **If no public module exists, it belongs as an Admin submodule under `modules/Admin/submodule/`.**

This keeps domain logic co-located with its module and prevents the Admin module from becoming a dumping ground for unrelated controllers.

| Feature | Public Module? | Correct Location |
|---------|---------------|-----------------|
| Link management | Yes (`Links`) | `modules/Links/Admin/LinksAdmin.php` |
| ComfyUI generations | Yes (`Comfy`) | `modules/Comfy/Admin/ComfyAdmin.php` |
| LLM thread viewer | Yes (`Llm`) | `modules/Llm/Admin/LlmAdmin.php` |
| Billing config | No | `modules/Admin/submodule/Billing/` |
| API key config | No | `modules/Admin/submodule/ApiConfig/` |
| Firewall rules | No | `modules/Admin/submodule/Firewall/` |

**Namespaces:**
- Admin submodules: `Zero\Module\Admin` (routed via `Module::__call` fallthrough)
- Module Admin controllers: `Zero\Module\{Module}\Admin` (routed via `Admin::__call` class-existence check)

---

## Core Classes

### `AdminResponse` (`core/AdminResponse.php`)

Base class for all admin controllers. Extends `Module`.

| Method | Purpose |
|--------|---------|
| `__construct()` | Enforces `allow.admin`; removes any auto-created public symlink so admin assets are never web-accessible |
| `getStylesheets()` / `getScripts()` | Override — inline admin assets using the framework's filename-matching convention |
| `getAdminModules()` | Discover all modules with `Admin/` directories |
| `loadInlineAsset($module, $type, $filename)` | Include a single asset file inline |
| `loadAdminStyles($module)` / `loadAdminScripts($module)` | Include all CSS/JS for a module inline (manual usage in views) |
| `setBreadcrumbs(array $crumbs)` | Prepend "Admin" and set navigation |
| `routeToModuleAdmin($module, $method, $params)` | Route to a module's admin controller |

### `Admin` Module (`modules/Admin/Admin.php`)

Main admin hub. Extends `AdminResponse`. Handles the `/admin` dashboard, user/group/entity management, and routes `/admin/{module}/*` to module admin controllers via `__call`.

```
/admin                    → Admin::index()
/admin/manage/users       → Admin::manage(['users'])
/admin/links              → Admin::__call('links', ...) → LinksAdmin::index()
/admin/links/edit/5       → Admin::__call('links', ...) → LinksAdmin::edit(5)
```

---

## Module Admin Structure

```
modules/YourModule/
├── YourModule.php              # Public controller
├── view/                       # Public views
└── Admin/                      # ← Admin subsystem
    ├── admin-config.php        # Optional: discovery metadata
    ├── YourModuleAdmin.php     # Admin controller (extends AdminResponse)
    ├── view/                   # Admin views
    └── assets/                 # Admin-only assets (never web-accessible)
        ├── admin.css
        └── admin.js
```

Minimal admin controller:

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

Standard CRUD/pagination/filtering patterns are just regular Zero controller patterns — see the `/zeromodule` skill.

---

## Discovery Mechanism

`AdminResponse::getAdminModules()` scans `MODULE_PATH/*/Admin`, reads optional `admin-config.php` for metadata, applies defaults, filters by `enabled`, and sorts by `order`.

**admin-config.php format:**

```php
<?php
return [
    'label' => 'Manage Links',              // Display name
    'icon' => 'link',                       // Material Symbol name
    'description' => 'Manage shared links',
    'order' => 10,                          // Lower = first
    'enabled' => true,
    'permissions' => ['allow.admin']
];
```

**Defaults (if missing):** `label="{Module} Management"`, `icon="settings"`, `order=999`, `enabled=true`, `permissions=['allow.admin']`.

---

## Routing Flow

Example: `/admin/links/edit/5`

1. Framework routes to `Admin` module
2. `Admin::__call('links', [['edit', '5']])` — checks if `Links\Admin\LinksAdmin` exists
3. Calls `routeToModuleAdmin('links', 'edit', [5])`
4. `AdminResponse::routeToModuleAdmin()` instantiates `LinksAdmin` and calls `edit(5)`
5. `LinksAdmin::edit(5)` fetches data, sets breadcrumbs, renders `view/edit.php` with inline assets

**Kebab-case ↔ PascalCase conversion:**
- URL → Class: `tech-stack` → `TechStack` (word-by-word capitalization, not `ucfirst(strtolower())`)
- Class → URL slug: `TechStack` → `tech-stack` (insert hyphen before each uppercase, then lowercase)

Both conversions live in `Admin::__call` and `AdminResponse::getAdminModules()`.

---

## Permission Security

Multi-layer protection:

1. **AdminResponse constructor** — redirects to login if user lacks `allow.admin`. Every admin controller inherits this check.
2. **Class attribute** on `Admin`: `#[RequirePermission('allow.admin')]` — enforced by the attribute processor, redundant protection if the base class check were bypassed.
3. **Method-level** (optional) — `#[RequirePermission('blog.edit')]` for fine-grained per-action control.

**Asset protection:** the constructor deletes any public symlink that `Module::__construct()` auto-created, and `getStylesheets()`/`getScripts()` are overridden to emit assets inline. Admin assets are never reachable as `/assets/{module}/...` URLs.

---

## Asset Management

See `core/ADMIN_ASSETS.md` for how inline loading works and the filename-matching convention used by `inlineAdminAssets()`.

---

## Debugging

- `var_dump($this->getAdminModules())` in `Admin::index()` to inspect discovery
- `var_dump(User::hasPermission('allow.admin'))` / `User::getAuthLevel()` to check permission state
- Test URLs: `/admin` (dashboard), `/admin/{module}` (module admin), `/admin/invalid` (404)

---

## Summary

- **Security**: multi-layer permission checks, assets never web-accessible
- **Simplicity**: drop in `Admin/` folder, auto-discovered
- **Scalability**: each module owns its admin interface
- **Consistency**: shared UI patterns via `modules/Admin/assets/`

Core philosophy: convention over configuration, secure by default, pluggable by design.
