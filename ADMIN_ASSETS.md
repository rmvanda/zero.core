# Admin Asset Loading

Admin CSS/JS is **always served inline** — never exposed as a public URL. This is enforced at the framework layer; there is no admin asset URL scheme.

> For a proposed future dynamic-URL-served approach (deferred), see `ADMIN_ASSETS_PROPOSAL.md`.

## Why inline?

- Assets never reachable at `/assets/{module}/...` → no information disclosure from `Admin/assets/` directories
- No symlink management, no path-traversal surface, no routing
- Guaranteed to load with the page

Trade-off: no cross-page browser caching. Acceptable until admin interfaces grow large enough to matter.

## Security enforcement (AdminResponse)

`core/AdminResponse.php` guarantees admin assets stay inline:

1. **Constructor (lines 47–60)** — after `parent::__construct()` (which auto-creates `/assets/{module}` symlinks for regular modules), it **deletes** any symlink matching the admin controller's path and cleans up empty parent directories. Admin asset directories cannot be reached from the web root.
2. **`getStylesheets()` / `getScripts()` overrides** — replace the default `<link>`/`<script src>` emission with inline `<style>`/`<script>` blocks via `readfile`.

## How loading works

There are two mechanisms. Both inline. Choose based on whether you want the framework's filename convention.

### Auto mode (preferred): framework filename convention

When an admin view is rendered through the normal frame, `AdminResponse::getStylesheets()` and `getScripts()` fire automatically. They delegate to `inlineAdminAssets($type)`, which looks for these filenames (matching the regular framework convention):

- `admin.{css|js}` — shared admin base (always loaded)
- `{moduleName}.{css|js}` — module-wide
- `{endpointName}.{css|js}` — endpoint-specific
- `{endpointNameOrig}.{css|js}` — original (pre-kebab) endpoint name

And searches these directories, in order:

1. `modules/Admin/assets/{css|js}/` — shared base styles/scripts
2. `{current module}/Admin/assets/{css|js}/` — module-specific (via `$this->moduleAssetDir`)

Each matching file is emitted inside a single `<style>` or `<script>` block (with a filename comment for debuggability):

```html
<style>/* admin.css */
... file contents ...
</style>
<style>/* links.css */
... file contents ...
</style>
```

**Example:** request to `/admin/links/edit/5`, controller `LinksAdmin`:
- `modules/Admin/assets/css/admin.css` → loaded (shared base)
- `modules/Links/Admin/assets/css/links.css` → loaded (module match)
- `modules/Links/Admin/assets/css/edit.css` → loaded (endpoint match)

Files that don't match the convention are ignored.

### Manual mode: explicit view calls

For views that need to load everything in a module's admin assets dir (bypassing the filename convention):

```php
<?php $this->loadAdminStyles('links'); ?>   // globs & inlines ALL *.css in modules/Links/Admin/assets/
<?php $this->loadAdminScripts('links'); ?>  // globs & inlines ALL *.js
<?php $this->loadInlineAsset('links', 'css', 'admin.css'); ?>  // single file
```

| Method | Behavior |
|--------|----------|
| `loadInlineAsset($module, $type, $filename)` | Inline one specific file |
| `loadAdminStyles($module)` | Inline every `*.css` in `modules/{Module}/Admin/assets/` |
| `loadAdminScripts($module)` | Inline every `*.js` in `modules/{Module}/Admin/assets/` |

Missing files print an HTML comment in `DEVMODE` and silently no-op otherwise.

**When to use which:** prefer auto mode and rely on naming conventions. Reach for manual mode only when an admin view needs assets that don't follow the `{module}/{endpoint}` naming pattern.

## File layout

```
modules/Admin/
└── assets/
    ├── css/
    │   ├── admin.css              # shared base — auto-loaded on every admin page
    │   └── admin-tables.css       # will NOT auto-load unless named for a module/endpoint
    └── js/
        └── admin.js

modules/Links/Admin/
└── assets/
    ├── css/
    │   ├── links.css              # auto-loaded for all /admin/links/* endpoints
    │   └── edit.css               # auto-loaded only on /admin/links/edit/*
    └── js/
        └── links.js
```

## Component assets

ShadowComponents used inside admin views follow the same inline-only rule. Since ShadowComponent HTML files are self-contained (styles + script inside the `<template>`), they can be `require`d directly:

```php
<?php require_once MODULE_PATH . 'Links/Admin/assets/components/link-manager.html'; ?>
```

The `<template>` + `<script>` block registers the custom element without exposing anything as a URL.
