# Admin Architecture Documentation

This document describes the pluggable admin system architecture for the Zero Framework.

---

## Overview

The admin system provides a secure, scalable way for modules to expose administrative interfaces. Key features:

- **Auto-Discovery**: Modules with `Admin/` directories automatically appear in admin dashboard
- **Secure by Default**: Permission checks enforced at base class level
- **Inline Assets**: Admin CSS/JS loaded inline for security (not exposed in web root)
- **Consistent Patterns**: All admin interfaces share common UI/UX
- **Flexible Routing**: Clean URLs with automatic controller routing

---

## Architecture Components

### 1. Core Classes

#### **`AdminResponse` (core/AdminResponse.php)**

Base class for all admin controllers. Extends `Module`.

**Key Features:**
- Enforces `allow.admin` permission in constructor
- Auto-discovers admin modules via filesystem scan
- Provides asset loading (inline CSS/JS)
- Breadcrumb navigation
- Module routing

**Key Methods:**
```php
__construct()                           // Permission check + parent init
getAdminModules()                       // Discover all modules with Admin/
loadInlineAsset($module, $type, $file)  // Load CSS/JS inline
loadAdminStyles($module)                // Load all CSS for module
loadAdminScripts($module)               // Load all JS for module
setBreadcrumbs(array $crumbs)           // Set navigation breadcrumbs
routeToModuleAdmin($module, $method, $params) // Route to module admin
```

#### **`Admin` Module (modules/Admin/Admin.php)**

Main admin hub. Extends `AdminResponse`.

**Responsibilities:**
- Admin dashboard (`/admin`)
- User/group management
- Entity management (EAV system)
- API transaction monitoring
- Route to module admin controllers via `__call()`

**URL Routing:**
```
/admin                    → Admin::index()
/admin/manage/users       → Admin::manage(['users'])
/admin/links              → Admin::__call('links', ...) → LinksAdmin::index()
/admin/links/edit/5       → Admin::__call('links', ...) → LinksAdmin::edit(5)
```

---

### 2. Module Structure

To add admin functionality to a module, create an `Admin/` subdirectory:

```
modules/YourModule/
├── YourModule.php              # Public controller
├── YourModuleModel.php
├── view/                       # Public views
└── Admin/                      # ← Admin subsystem
    ├── admin-config.php        # ← Optional: Discovery metadata
    ├── YourModuleAdmin.php     # ← Admin controller
    ├── view/                   # ← Admin views
    │   ├── index.php
    │   └── edit.php
    └── assets/                 # ← Admin-only assets
        ├── admin.css
        └── admin.js
```

---

### 3. Discovery Mechanism

**How It Works:**

1. **Scan Filesystem**: `AdminResponse::getAdminModules()` scans `MODULE_PATH/*/Admin`
2. **Load Metadata**: Reads optional `admin-config.php` for customization
3. **Apply Defaults**: Modules without config get sensible defaults
4. **Filter & Sort**: Filter by `enabled`, sort by `order`
5. **Display**: Render as cards on admin dashboard

**admin-config.php Format:**

```php
<?php
return [
    'label' => 'Manage Links',              // Display name
    'icon' => 'link',                       // Material Symbol name
    'description' => 'Manage shared links', // Short description
    'order' => 10,                          // Sort order (lower = first)
    'enabled' => true,                      // Show in discovery?
    'permissions' => ['allow.admin']        // Required permissions
];
```

**Defaults (if admin-config.php not present):**
```php
[
    'label' => 'ModuleName Management',
    'icon' => 'settings',
    'description' => '',
    'order' => 999,
    'enabled' => true,
    'permissions' => ['allow.admin']
]
```

---

### 4. Routing Flow

**Example: User visits `/admin/links/edit/5`**

```
1. Framework routes to Admin module
   ↓
2. Admin::__call('links', [['edit', '5']])
   │
   ├─ Unwraps args: ['edit', '5']
   ├─ Checks if Links\Admin\LinksAdmin exists
   └─ Calls routeToModuleAdmin('links', 'edit', [5])
   ↓
3. AdminResponse::routeToModuleAdmin()
   │
   ├─ Instantiates LinksAdmin
   ├─ Validates method exists
   └─ Calls LinksAdmin::edit(5)
   ↓
4. LinksAdmin::edit(5)
   │
   ├─ Fetches link data
   ├─ Sets breadcrumbs
   ├─ Assigns data to $this
   └─ Renders view/edit.php
   ↓
5. View renders with inline assets
```

**URL → Method Mapping:**
```
/admin/links              → LinksAdmin::index()
/admin/links/edit/5       → LinksAdmin::edit(5)
/admin/links/delete/5     → LinksAdmin::delete(5)
/admin/links/bulk-delete  → LinksAdmin::bulkDelete()
```

---

### 5. Permission Security

**Multi-Layer Protection:**

#### **Layer 1: AdminResponse Constructor**
```php
// In core/AdminResponse.php
public function __construct() {
    if (!User::hasPermission('allow.admin')) {
        header('Location: /auth/login?redirect=' . urlencode($_SERVER['REQUEST_URI']));
        exit;
    }
    parent::__construct();
}
```
✅ **Every admin controller inherits this check**
✅ **Executes before ANY admin code runs**
✅ **Unauthorized users redirected to login**

#### **Layer 2: Module Attribute**
```php
// In modules/Admin/Admin.php
#[RequirePermission('allow.admin')]
class Admin extends AdminResponse { ... }
```
✅ **Framework enforces via attribute processor**
✅ **Redundant protection if base class bypassed**

#### **Layer 3: Method-Level (Optional)**
```php
// Future granular permissions
#[RequirePermission('blog.edit')]
public function edit($id) { ... }
```
✅ **Fine-grained control per action**
✅ **Admin module already uses this pattern**

**Security Guarantees:**
- ❌ Unauthenticated users → Redirected to login
- ❌ Authenticated but not admin → Redirected to login
- ✅ Admin users → Full access
- ✅ Future: Role-based access per module/action

---

### 6. Asset Management

**Current: Inline Loading (Secure)**

Assets loaded via PHP `include` directly in views:

```php
<?php $this->loadAdminStyles('links'); ?>
<!-- Outputs: <style>... contents of admin.css ...</style> -->

<?php $this->loadAdminScripts('links'); ?>
<!-- Outputs: <script>... contents of admin.js ...</script> -->
```

**Benefits:**
- ✅ Assets never exposed as URLs
- ✅ No symlink management
- ✅ No directory traversal vulnerabilities
- ✅ Simple implementation

**Trade-offs:**
- ❌ No browser caching between pages
- ❌ Larger initial page load

**Future: Dynamic Serving**

See `core/ADMIN_ASSETS.md` for implementation of permission-gated asset endpoint.

---

### 7. Creating a Module Admin

**Step-by-Step Example:**

#### **1. Create Directory Structure**
```bash
mkdir -p modules/Blog/Admin/{view,assets}
```

#### **2. Create admin-config.php (Optional)**
```php
<?php
// modules/Blog/Admin/admin-config.php
return [
    'label' => 'Manage Blog',
    'icon' => 'article',
    'description' => 'Manage blog posts and comments',
    'order' => 20,
    'enabled' => true
];
```

#### **3. Create Admin Controller**
```php
<?php
// modules/Blog/Admin/BlogAdmin.php
namespace Zero\Module\Blog\Admin;

use Zero\Core\AdminResponse;

class BlogAdmin extends AdminResponse {

    public function __construct() {
        parent::__construct();
        // Initialize model, etc.
    }

    public function index() {
        $this->setBreadcrumbs([
            ['label' => 'Manage Blog', 'url' => '/admin/blog']
        ]);

        // Your admin logic
        $this->posts = $this->getAllPosts();

        $this->build(__DIR__ . '/view/index.php');
    }

    public function edit($id) {
        $this->setBreadcrumbs([
            ['label' => 'Manage Blog', 'url' => '/admin/blog'],
            ['label' => 'Edit Post', 'url' => '']
        ]);

        $this->post = $this->getPostById($id);
        $this->build(__DIR__ . '/view/edit.php');
    }

    public function update() {
        // Handle POST to update post
        // Redirect back to index
    }

    public function delete($id) {
        // Handle POST to delete post
        // Redirect back to index
    }
}
```

#### **4. Create Admin View**
```php
<?php
// modules/Blog/Admin/view/index.php

// Load admin styles
$this->loadAdminStyles('admin'); // Main admin CSS
$this->loadAdminStyles('blog');  // Blog-specific CSS
?>

<div class="content-wrapper">
    <!-- Breadcrumbs -->
    <?php if (!empty($this->breadcrumbs)): ?>
    <nav class="breadcrumbs">
        <?php foreach ($this->breadcrumbs as $index => $crumb): ?>
            <?php if ($index > 0): ?> / <?php endif; ?>
            <?php if (!empty($crumb['url'])): ?>
                <a href="<?= $crumb['url'] ?>"><?= $crumb['label'] ?></a>
            <?php else: ?>
                <span><?= $crumb['label'] ?></span>
            <?php endif; ?>
        <?php endforeach; ?>
    </nav>
    <?php endif; ?>

    <h1>Manage Blog Posts</h1>

    <table class="admin-table">
        <thead>
            <tr>
                <th>Title</th>
                <th>Author</th>
                <th>Published</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($this->posts as $post): ?>
            <tr>
                <td><?= htmlspecialchars($post['title']) ?></td>
                <td><?= htmlspecialchars($post['author']) ?></td>
                <td><?= $post['published_at'] ?></td>
                <td>
                    <a href="/admin/blog/edit/<?= $post['id'] ?>" class="btn-small">Edit</a>
                    <form method="POST" action="/admin/blog/delete/<?= $post['id'] ?>" class="form-inline">
                        <button type="submit" class="btn-small btn-danger">Delete</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php $this->loadAdminScripts('blog'); ?>
```

#### **5. Access Your Admin**

Visit: `/admin/blog`

The module will automatically appear on the admin dashboard at `/admin`.

---

### 8. Common Patterns

#### **CRUD Operations**

```php
class YourModuleAdmin extends AdminResponse {

    // List all
    public function index() {
        $this->items = $this->model->getAll();
        $this->build(__DIR__ . '/view/index.php');
    }

    // Show create form
    public function create() {
        $this->build(__DIR__ . '/view/create.php');
    }

    // Handle create submission
    public function store() {
        // Validate and insert
        $this->success('Created successfully');
        header('Location: /admin/yourmodule');
        exit;
    }

    // Show edit form
    public function edit($id) {
        $this->item = $this->model->find($id);
        $this->build(__DIR__ . '/view/edit.php');
    }

    // Handle update submission
    public function update() {
        // Validate and update
        $this->success('Updated successfully');
        header('Location: /admin/yourmodule');
        exit;
    }

    // Handle delete
    public function delete($id) {
        $this->model->delete($id);
        $this->success('Deleted successfully');
        header('Location: /admin/yourmodule');
        exit;
    }
}
```

#### **Pagination**

```php
public function index() {
    $page = max(1, (int)($_GET['page'] ?? 1));
    $perPage = 25;
    $offset = ($page - 1) * $perPage;

    $this->items = $this->model->paginate($perPage, $offset);
    $this->totalItems = $this->model->count();
    $this->totalPages = (int)ceil($this->totalItems / $perPage);
    $this->currentPage = $page;

    $this->build(__DIR__ . '/view/index.php');
}
```

#### **Filtering**

```php
public function index() {
    $status = $_GET['status'] ?? null;
    $category = $_GET['category'] ?? null;

    $this->items = $this->model->filter([
        'status' => $status,
        'category' => $category
    ]);

    $this->filters = compact('status', 'category');
    $this->build(__DIR__ . '/view/index.php');
}
```

#### **Flash Messages**

```php
// In controller
$this->success('Operation successful', ['id' => $newId]);
$this->error('Operation failed', ['error' => $message]);

// Framework handles redirect with flash message
```

---

### 9. Styling

**CSS Organization:**

```
modules/Admin/assets/css/
├── admin.css           # Main admin styles (tables, forms, cards)
└── admin-tables.css    # Extended table styles

modules/YourModule/Admin/assets/
└── admin.css           # Module-specific overrides
```

**Key CSS Classes:**

```css
/* Layout */
.content-wrapper        /* Main content container */
.breadcrumbs           /* Navigation breadcrumbs */

/* Tables */
.admin-table           /* Data tables */
.expandable-table      /* Tables with expandable rows */

/* Forms */
.admin-form-field      /* Form field wrapper */
.admin-input           /* Text inputs */
.admin-select          /* Dropdowns */

/* Buttons */
.btn-primary           /* Primary action */
.btn-small             /* Secondary action */
.btn-danger            /* Destructive action */

/* Cards */
.admin-card-grid       /* Grid of cards */
.admin-card            /* Individual card */
.admin-card-title      /* Card title */
.admin-card-desc       /* Card description */

/* Filters */
.filter-container      /* Filter section */
.filter-grid           /* Filter inputs grid */

/* Pagination */
.pagination            /* Pagination wrapper */
.pagination-controls   /* Pagination buttons */
.pagination-btn        /* Individual button */

/* Badges */
.event-badge           /* Status/category badge */
.status-badge          /* Status indicator */
```

**Design Principles:**
- Inherit from `main.css` variables
- Use glassmorphism for surfaces
- Material Symbols for icons
- Responsive by default

---

### 10. Testing & Debugging

**Check Discovery:**
```php
// In Admin::index()
var_dump($this->getAdminModules());
```

**Enable Dev Mode:**
```ini
# In app/config/constants.ini
DEVMODE = true
```

**Check Permissions:**
```php
var_dump(User::hasPermission('allow.admin'));
var_dump(User::getAuthLevel());
```

**Test URLs:**
```
/admin                  # Should show dashboard with discovered modules
/admin/yourmodule       # Should route to YourModuleAdmin::index()
/admin/invalid          # Should show 404 error
```

---

### 11. Future Enhancements

**Planned:**
- [ ] Cache implementation in `getAdminModules()`
- [ ] Dynamic asset serving (see ADMIN_ASSETS.md)
- [ ] Granular permission system (per-module, per-action)
- [ ] Admin dashboard widgets/stats
- [ ] Audit logging integration
- [ ] Bulk operations framework
- [ ] Export/import functionality
- [ ] Admin API endpoints (JSON responses)

**Extensibility Points:**
- Custom admin themes
- Admin middleware hooks
- Custom dashboard widgets
- Module health checks
- Automated backups

---

## 12. Lessons from Real-World Usage

### Case Study: TechStack Module (January 2026)

The TechStack module provided valuable insights into AdminResponse system capabilities and limitations.

**What Worked Well:**

1. **Auto-Discovery**: Module appeared on admin dashboard immediately upon creating `Admin/` directory
2. **Routing**: Kebab-case URLs (`/admin/tech-stack`) correctly mapped to PascalCase classes (`TechStackAdmin`)
3. **Asset Loading**: Inline CSS/JS loading worked without symlink management
4. **CRUD Patterns**: Standard patterns for list/create/edit/delete worked smoothly
5. **Relationship Management**: Complex many-to-many relationships with visual previews, strength indicators, validation

**Bugs Discovered & Fixed:**

1. **Kebab-case to PascalCase Conversion** (`/modules/Admin/Admin.php:36`)
   - **Bug**: `ucfirst(strtolower($moduleName))` converted `tech-stack` → `Techstack` (wrong)
   - **Fix**: `str_replace(' ', '', ucwords(str_replace('-', ' ', $moduleName)))` → `TechStack` (correct)

2. **PascalCase to Kebab-case Generation** (`/core/AdminResponse.php:83`)
   - **Bug**: `strtolower($moduleName)` converted `TechStack` → `techstack` (wrong)
   - **Fix**: `strtolower(preg_replace('/([a-z])([A-Z])/', '$1-$2', $moduleName))` → `tech-stack` (correct)

**Design Patterns Validated:**

- **Live Preview Forms**: Show visual preview of data before submission (e.g., relationship preview)
- **Strength Indicators**: Visual dots (1-10) for indicating connection strength
- **Color-Coded Badges**: Different colors for relationship types
- **Empty States**: Friendly messaging when no data exists with call-to-action
- **Self-Referential Validation**: Prevent resources from relating to themselves

**Architecture Insights:**

The AdminResponse system scaled well to complex modules with:
- Multiple entity types (technologies, relationships)
- Complex relationships (many-to-many with metadata)
- Visual data requirements (icons, colors, strength indicators)
- Integration needs (graph visualization)

However, the experience highlighted that **admin capability alone doesn't justify module complexity**. The best modules combine admin functionality with clear daily utility.

---

## Summary

The admin architecture provides:

✅ **Security**: Multi-layer permission checks
✅ **Simplicity**: Drop in `Admin/` folder, it works
✅ **Scalability**: Each module owns its admin interface
✅ **Consistency**: Shared UI patterns and components
✅ **Flexibility**: Override any behavior as needed

**Core Philosophy**: Convention over configuration, secure by default, pluggable by design.
