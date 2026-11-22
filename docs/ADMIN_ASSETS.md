# Admin Asset Loading

Admin CSS and JS are always written into the page inline. There is no admin asset URL: nothing
under a module's `Admin/assets/` (or the Admin module's own `assets/`) is reachable from the web
root. The price is no browser caching between admin pages.

> A URL-served design that would allow caching is kept, not implemented, in
> `core/archive/ADMIN_ASSETS_PROPOSAL.md`.

## How it is enforced

Every admin controller extends `AdminResponse` (`core/AdminResponse.php`), which does two things:

1. **Constructor.** `Module::__construct()` links a module's `assets/` folder into
   `WEB_ROOT/assets/`. After calling it, `AdminResponse::__construct()` deletes the link made for
   the admin class and removes the parent folders it left empty.
2. **`getStylesheets()` / `getScripts()`.** The frame calls these to emit a page's assets.
   `AdminResponse` overrides both to print `<style>` and `<script>` blocks through
   `inlineAdminAssets()` instead of `<link>` and `<script src>` tags.

## Auto mode: files named by convention

Auto mode runs whenever an admin page is built through the frame. Nothing is called from the view.

**Folders searched**, in order:

1. `core/modules/Admin/assets/{css|js}/`: the shared base
2. `{folder of the controller's class}/assets/{css|js}/`: for an offering that is
   `modules/Links/Admin/assets/{css|js}/`, for an Admin submodule
   `core/modules/Admin/submodule/Firewall/assets/{css|js}/`

**Filenames loaded** (anything else in those folders is ignored; a name found in both folders is
loaded once, from the first):

| File | Is |
|------|----|
| `admin.{css\|js}` | The shared base, on every admin page |
| `{class}.{css\|js}` | The controller's class name in lowercase: `linksadmin.css` for `LinksAdmin`, `firewall.css` for `Firewall`, `admin.css` for `Admin` |
| `{endpoint}.{css\|js}` | The endpoint of the request (below) |

What "the endpoint" is depends on the controller:

- **Admin itself**: the dashboard, `index`.
- **An offering** (`/admin/links/edit/5`): the URL's second segment is the module, so the endpoint
  is `links` on every page of the offering. `linksadmin.css` and `links.css` both load on all of
  them; `edit.css` does not load. An offering that wants a file for one page loads it from the
  view (manual mode) or sets `$this->activeEndpoint` before `build()`.
- **An Admin submodule** (`/admin/groups/members/5`): the submodule's own endpoint, the method
  name: `members.css`, and `setMemberRole.css` for `/admin/groups/set-member-role`. `groups.css`
  loads on every Groups page.

Each file is printed in its own block, with the filename in a comment:

```html
<style>/* admin.css */
... file contents ...
</style>
<style>/* linksadmin.css */
... file contents ...
</style>
```

## Manual mode: calls from a view

```php
<?php $this->loadInlineAsset('Links', 'css', 'edit.css'); ?>   // one file
<?php $this->loadAdminStyles('Links'); ?>                      // every *.css
<?php $this->loadAdminScripts('Links'); ?>                     // every *.js
```

| Method | Inlines |
|--------|---------|
| `loadInlineAsset($module, $type, $filename)` | `{module}/Admin/assets/{filename}`, wrapped in `<style>` for type `css` or `<script>` for `js` |
| `loadAdminStyles($module)` | Every `*.css` directly in `{module}/Admin/assets/` |
| `loadAdminScripts($module)` | Every `*.js` directly in `{module}/Admin/assets/` |

- Manual mode reads `Admin/assets/` itself, not the `css/` and `js/` folders auto mode reads. A
  module uses one layout or the other for a given file.
- The module is found through `Modules::path()`, whatever the case or hyphens of the name
  (`ApiConfig`, `apiconfig` and `api-config` all reach `modules/ApiConfig/`).
- The loaders only look in a module's `Admin/` folder, so they cannot load the Admin module's
  shared files. Auto mode has already loaded `admin.css` and `admin.js`.
- The file is `include`d, so PHP tags in it run.
- `loadInlineAsset()` on a missing file logs `Console::warn()` and, in `DEVMODE`, prints an HTML
  comment. `loadAdminStyles()` and `loadAdminScripts()` on a module with no `Admin/assets/` do
  nothing.

Prefer auto mode. Use manual mode for a file that belongs to one page of an offering, or one
whose name does not fit the convention.

## File layout

```
core/modules/Admin/assets/
├── css/
│   └── admin.css              # shared base: every admin page
├── js/
│   └── admin.js
└── component/
    └── admin-expandable-table.html

modules/Comfy/Admin/assets/    # auto mode
├── css/comfyadmin.css         # every /admin/comfy/* page
└── js/comfyadmin.js

modules/ApiConfig/Admin/assets/    # manual mode: each view calls loadInlineAsset()
├── api-config.css
├── mappings.css
└── mappings.js
```

## Component assets

A ShadowComponent used in an admin view is inline too. Its HTML file holds its styles and script
inside the `<template>`, so the view `require`s it and nothing is served by URL:

```php
<?php require_once __DIR__ . '/../assets/aws-billing-dashboard.html'; ?>
```

---

## Look

Every admin screen, Admin's own and a module's offering alike, is printed inside
`<div class="admin">` (`AdminResponse::beforeView()` / `afterView()`), and `admin.css` scopes its
rules to `.admin`. A frame may load the admin styles before or after the site's `main.css`; the
scope makes them win either way.

**Colours come from the site's theme.** `admin.css` declares `--admin-*` tokens on `:root`. Each
reads the theme's variable, falls back to the skeleton's name for the same thing, then to a
neutral value, so a screen is legible under any `main.css`:

| Token | Reads |
|-------|-------|
| `--admin-accent` | `--accent-primary`, `--accent` |
| `--admin-text`, `--admin-text-strong` | `--text-primary` / `--text-bright`, `--text` |
| `--admin-line` | `--border-color`, `--border` |
| `--admin-surface` | `--surface`, `--surface-bg-rgb` |
| `--admin-success`, `--admin-danger`, `--admin-warning` | `--color-success`, `--color-error`, `--color-warning` |
| `--admin-radius`, `--admin-mono`, `--admin-blur` | `--radius`, `--font-mono`, `--surface-blur` |

Tints (`--admin-accent-soft`, `--admin-lift`, `--admin-text-dim`, `--admin-danger-text`, ...) are
mixed from those with `color-mix()`. A stylesheet of an admin screen uses the tokens and names no
colour of its own.

**Shared pieces** a view builds from:

| Class | Is |
|-------|----|
| `content-wrapper` | The root of a view |
| `admin-head`, `admin-lede`, `admin-head-actions` | The title and what the screen is for, with its actions on the right |
| `admin-section`, `admin-section-head`, `admin-note`, `admin-count` | A part of a screen under its own `h2` |
| `admin-panel`, `admin-facts` | A surface for a form or a `dl` of facts |
| `admin-table-wrap` > `admin-table` | A table; cells take `num`, `dim`, `nowrap`, `text-right`, `actions-cell` |
| `btn-primary`, `btn-secondary`, `btn-small`, `btn-danger`, `btn-icon` | Buttons |
| `admin-form-field`, `admin-form-row`, `admin-input`, `admin-select`, `admin-switch` | Fields; a switch is a checkbox that takes effect when it changes |
| `status-badge status-active|status-inactive`, `event-badge`, `badge` | States and keys |
| `admin-info-box` (`is-error`, `is-success`), `admin-empty` | A note, and nothing-to-show |
| `pagination`, `pagination-btn` | Paging |

**Icons** are Material Symbols Outlined. `AdminResponse::getStylesheets()` links the font
(`AdminResponse::ICON_FONT`), so a frame does not have to.
