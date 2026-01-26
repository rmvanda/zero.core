# Admin Asset Management

This document describes asset serving strategies for module admin interfaces.

## Current Implementation: Inline Assets

Admin assets (CSS/JS) are currently loaded inline via PHP includes for maximum security and simplicity.

**How it works:**
```php
// In admin view:
<?php $this->loadAdminStyles('links'); ?>
<?php $this->loadAdminScripts('links'); ?>
```

**Pros:**
- ✅ Maximum security - assets never exposed as URLs
- ✅ Simple implementation - no routing required
- ✅ No symlink management
- ✅ Guaranteed to load with page

**Cons:**
- ❌ No browser caching between pages
- ❌ Larger initial page size
- ❌ Not ideal for large asset files

---

## Future Option: Dynamic Asset Serving

For better performance with larger admin interfaces, implement dynamic asset serving via controller endpoint.

### Implementation

#### 1. Add asset serving method to Admin module

```php
// In modules/Admin/Admin.php

/**
 * Serve admin assets dynamically with permission checks
 * URL: /admin/asset/{module}/{filename}
 *
 * @param array $args [module, filename]
 */
public function asset($args = []) {
    // Already protected by AdminResponse constructor permission check

    $module = $args[0] ?? null;
    $filepath = $args[1] ?? null;

    if (!$module || !$filepath) {
        http_response_code(400);
        exit;
    }

    // Security: Only allow alphanumeric module names
    if (!preg_match('/^[a-zA-Z0-9]+$/', $module)) {
        http_response_code(400);
        exit;
    }

    // Security: Only allow safe filenames (prevent directory traversal)
    $safeFilename = basename($filepath);
    if ($safeFilename !== $filepath) {
        http_response_code(400);
        exit;
    }

    // Build asset path
    $moduleClassName = ucfirst(strtolower($module));
    $assetPath = MODULE_PATH . $moduleClassName . "/Admin/assets/" . $safeFilename;

    // Validate file exists
    if (!file_exists($assetPath) || !is_file($assetPath)) {
        http_response_code(404);
        exit;
    }

    // Determine content type
    $ext = strtolower(pathinfo($safeFilename, PATHINFO_EXTENSION));
    $contentType = match($ext) {
        'css' => 'text/css',
        'js' => 'application/javascript',
        'png' => 'image/png',
        'jpg', 'jpeg' => 'image/jpeg',
        'svg' => 'image/svg+xml',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
        default => 'application/octet-stream'
    };

    // Send headers with caching
    header("Content-Type: {$contentType}");
    header("Cache-Control: public, max-age=86400"); // 24 hours
    header("Last-Modified: " . gmdate('D, d M Y H:i:s', filemtime($assetPath)) . ' GMT');
    header("Content-Length: " . filesize($assetPath));

    // Support conditional requests
    $lastModified = filemtime($assetPath);
    $etag = md5_file($assetPath);
    header("ETag: \"{$etag}\"");

    if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && $_SERVER['HTTP_IF_NONE_MATCH'] === "\"{$etag}\"") {
        http_response_code(304); // Not Modified
        exit;
    }

    // Stream file to browser
    readfile($assetPath);
    exit;
}

/**
 * Security helper: Validate asset path is within module's assets directory
 *
 * @param string $assetPath Full path to asset file
 * @param string $module Module name
 * @return bool True if path is safe
 */
private function isInAssetsDir($assetPath, $module) {
    $moduleClassName = ucfirst(strtolower($module));
    $assetsDir = realpath(MODULE_PATH . $moduleClassName . "/Admin/assets");
    $requestedFile = realpath($assetPath);

    // Ensure both paths resolved and requested file is within assets dir
    return $assetsDir !== false
        && $requestedFile !== false
        && str_starts_with($requestedFile, $assetsDir);
}
```

#### 2. Update AdminResponse helper methods

```php
// In core/AdminResponse.php

/**
 * Load admin assets via dynamic URLs
 *
 * @param string $moduleName Module name
 * @param string $type Asset type: 'css' or 'js'
 * @param string $filename Filename within assets directory
 */
protected function loadDynamicAsset($moduleName, $type, $filename) {
    $slug = strtolower($moduleName);
    $url = "/admin/asset/{$slug}/{$filename}";

    if ($type === 'css') {
        echo "<link rel=\"stylesheet\" href=\"{$url}\">\n";
    } elseif ($type === 'js') {
        echo "<script src=\"{$url}\"></script>\n";
    }
}

/**
 * Load all CSS assets dynamically
 */
protected function loadAdminStylesDynamic($moduleName) {
    $moduleClassName = ucfirst(strtolower($moduleName));
    $assetsDir = MODULE_PATH . $moduleClassName . "/Admin/assets";

    if (!is_dir($assetsDir)) {
        return;
    }

    $cssFiles = glob($assetsDir . "/*.css");
    foreach ($cssFiles as $cssFile) {
        $filename = basename($cssFile);
        $this->loadDynamicAsset($moduleName, 'css', $filename);
    }
}

/**
 * Load all JS assets dynamically
 */
protected function loadAdminScriptsDynamic($moduleName) {
    $moduleClassName = ucfirst(strtolower($moduleName));
    $assetsDir = MODULE_PATH . $moduleClassName . "/Admin/assets";

    if (!is_dir($assetsDir)) {
        return;
    }

    $jsFiles = glob($assetsDir . "/*.js");
    foreach ($jsFiles as $jsFile) {
        $filename = basename($jsFile);
        $this->loadDynamicAsset($moduleName, 'js', $filename);
    }
}
```

#### 3. Usage in admin views

```html
<!-- Instead of inline loading: -->
<?php $this->loadAdminStylesDynamic('links'); ?>
<?php $this->loadAdminScriptsDynamic('links'); ?>

<!-- Or individual files: -->
<link rel="stylesheet" href="/admin/asset/links/admin.css">
<script src="/admin/asset/links/admin.js"></script>
```

### Apache Configuration (Optional)

For even better performance, add rewrite rules to bypass PHP for cached assets:

```apache
# In .htaccess (if using Apache)
<IfModule mod_rewrite.c>
    # Cache admin assets in browser
    <FilesMatch "\.(css|js|png|jpg|jpeg|svg|woff|woff2)$">
        Header set Cache-Control "public, max-age=86400"
    </FilesMatch>
</IfModule>
```

### Security Considerations

1. **Permission Check**: Asset endpoint MUST verify `allow.admin` permission before serving
2. **Path Traversal**: Use `basename()` and path validation to prevent `../../` attacks
3. **File Type Validation**: Only serve expected file types (CSS, JS, images, fonts)
4. **Module Validation**: Ensure module name is alphanumeric only
5. **Real Path Checking**: Verify final path is within module's assets directory

### Performance Comparison

| Method | Security | Speed | Caching | Complexity |
|--------|----------|-------|---------|------------|
| **Inline** (current) | ⭐⭐⭐⭐⭐ | ⭐⭐⭐ | ⭐ | ⭐ |
| **Dynamic** (this doc) | ⭐⭐⭐⭐ | ⭐⭐⭐⭐ | ⭐⭐⭐⭐ | ⭐⭐⭐ |
| **Symlinks** (not recommended) | ⭐ | ⭐⭐⭐⭐⭐ | ⭐⭐⭐⭐⭐ | ⭐⭐⭐⭐⭐ |

### When to Migrate

Consider switching from inline to dynamic serving when:
- Admin interfaces have >50KB of CSS/JS
- Multiple admin pages share the same assets
- Browser caching would provide significant UX improvement
- Performance profiling shows asset loading is a bottleneck

---

## Component Assets

For ShadowComponent-based admin components, follow the same pattern:

```
modules/Links/Admin/
├── assets/
│   ├── links-admin.css
│   ├── links-admin.js
│   └── components/
│       └── link-manager.html  (ShadowComponent)
```

Load components with:
```php
<?php require_once MODULE_PATH . 'Links/Admin/assets/components/link-manager.html'; ?>
```

ShadowComponents with inline styles/scripts remain self-contained and secure.
