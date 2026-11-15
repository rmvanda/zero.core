<?php

namespace Zero\Core;

use Zero\Core\Module;
use Zero\Core\Request;

/**
 * AdminResponse - Base class for all admin controllers
 *
 * Provides common functionality for admin interfaces including:
 * - Permission checking
 * - Module discovery
 * - Asset loading (inline)
 * - Admin navigation context
 *
 * Usage:
 *   - Main Admin module extends this
 *   - Module-specific admin controllers extend this (e.g., LinksAdmin)
 */
abstract class AdminResponse extends Module {

    /**
     * Current admin module name
     */
    protected $adminModule;

    /**
     * Admin navigation breadcrumbs
     */
    protected $breadcrumbs = [];

    /**
     * Constructor - enforces admin permission check
     * and prevents public symlink creation for admin assets
     */
    public function __construct() {
        // Check base admin access
        if (!Session::can('allow.admin')) {
            // Send them to the login page holding on to where they were going.
            //
            // This used to redirect to '/auth/login?redirect=<uri>', which was
            // wrong twice over: there is no Auth::login() and no __call() on
            // Auth, so /auth/login is a 404 — a denied admin visitor landed on
            // an error page rather than a login form — and nothing anywhere
            // reads a 'redirect' query parameter, so the destination was
            // discarded even if the page had existed. The login page is
            // /user/login, and the mechanism for coming back afterwards is
            // $_SESSION['return_url'], which Auth::complete() and Auth::token()
            // already consume. Same guard #[RequireLogin] uses on the way in:
            // REQUEST_URI is chosen by the requester and this value ends up in
            // a Location header.
            $requestUri = $_SERVER['REQUEST_URI'] ?? '/';
            if (Request::isSiteRelative($requestUri)) {
                $_SESSION['return_url'] = $requestUri;
            }

            header('Location: /user/login?r=y');
            exit;
        }

        parent::__construct();

        // Remove any auto-created public symlink — admin assets must not be web-accessible
        $fullClass = get_class($this);
        $modulePath = substr($fullClass, strlen('Zero\\Module\\'));
        $linkPath = WEB_ROOT . '/assets/' . strtolower(str_replace('\\', '/', $modulePath));
        if (is_link($linkPath)) {
            unlink($linkPath);
            // Clean up empty parent dirs created by Module::__construct's mkdir()
            $parentDir = dirname($linkPath);
            $assetsRoot = WEB_ROOT . '/assets';
            while ($parentDir !== $assetsRoot && is_dir($parentDir) && !(new \FilesystemIterator($parentDir))->valid()) {
                rmdir($parentDir);
                $parentDir = dirname($parentDir);
            }
        }
    }

    /**
     * Override: inline admin stylesheets instead of linking to public URLs
     *
     * Always loads shared admin base styles first, then module-specific styles.
     * Mirrors the filename-matching logic of Response::loadAssetTypeFromDir.
     */
    protected function getStylesheets() {
        $this->inlineAdminAssets('css');
    }

    /**
     * Override: inline admin scripts instead of linking to public URLs
     */
    protected function getScripts() {
        $this->inlineAdminAssets('js');
    }

    /**
     * Inline admin assets from module asset directories
     *
     * Searches the main Admin assets dir first (shared base styles like admin.css),
     * then the current module's asset dir (module-specific styles).
     * Files are matched by module name and endpoint name, same as the framework default.
     */
    private function inlineAdminAssets(string $type) {
        if (!in_array($type, ['css', 'js'], true)) return;
        $tag = $type === 'css' ? 'style' : 'script';

        // Build filename match list (mirrors Response::loadAssetTypeFromDir)
        $moduleName = $this->moduleName ?? Request::$module;
        $endpointName = $this->activeEndpoint ?? Request::$endpoint;
        $endpointNameOrig = $this->activeEndpointOrig ?? Request::$endpointOrig ?? '';

        $matches = array_unique(array_filter([
            'admin.' . $type,              // always load shared admin base
            $moduleName . '.' . $type,
            $endpointName . '.' . $type,
            $endpointNameOrig . '.' . $type,
        ]));

        $loaded = [];
        $adminDir = MODULE_PATH . 'Admin/assets/' . $type . '/';
        $moduleDir = $this->moduleAssetDir . $type . '/';

        // Search admin assets dir first, then module-specific dir
        foreach (array_unique([$adminDir, $moduleDir]) as $dir) {
            if (!is_dir($dir)) continue;
            foreach (glob($dir . '*.' . $type) as $file) {
                $filename = basename($file);
                if (isset($loaded[$filename])) continue;
                if (in_array($filename, $matches)) {
                    echo "<{$tag}>/* {$filename} */\n";
                    readfile($file);
                    echo "\n</{$tag}>\n";
                    $loaded[$filename] = true;
                }
            }
        }
    }

    /**
     * Discover all modules with admin capabilities
     *
     * Scans the modules directory for directories containing an Admin subdirectory
     * and lists a module only when Zero\Module\{X}\Admin\{X}Admin exists (the
     * class Admin::__call routes to) and the logged-in user holds every key in
     * the card's `permissions`. Loads optional admin-config.php for metadata
     *
     * @param string|null $root Directory holding the modules (defaults to MODULE_PATH)
     * @return array Array of admin module configurations
     */
    protected function getAdminModules($root = null) {
        // Check cache first
        $cacheKey = 'admin_modules_discovery';
        // TODO: Implement caching when Cache class is available
        // if ($cached = Cache::get($cacheKey)) {
        //     return $cached;
        // }

        $adminModules = [];
        $adminDirs = glob(($root ?? MODULE_PATH) . '*/Admin', GLOB_ONLYDIR);

        foreach ($adminDirs as $dir) {
            // Extract module name from path
            preg_match('#/([^/]+)/Admin$#', $dir, $matches);
            $moduleName = $matches[1];

            // Skip the main Admin module itself from discovery
            if (strtolower($moduleName) === 'admin') {
                continue;
            }

            // A folder is not an offering: the class must exist
            if (!class_exists("Zero\\Module\\{$moduleName}\\Admin\\{$moduleName}Admin")) {
                continue;
            }

            // Load optional metadata file
            $metadataFile = $dir . '/admin-config.php';
            $metadata = file_exists($metadataFile)
                ? include $metadataFile
                : [];

            // Build module configuration
            // Convert PascalCase to kebab-case (TechStack -> tech-stack)
            $slug = strtolower(preg_replace('/([a-z])([A-Z])/', '$1-$2', $moduleName));
            $adminModules[] = [
                'name' => $moduleName,
                'slug' => $slug,
                'label' => $metadata['label'] ?? ucfirst($moduleName) . ' Management',
                'icon' => $metadata['icon'] ?? 'settings',
                'description' => $metadata['description'] ?? '',
                'order' => $metadata['order'] ?? 999,
                'enabled' => $metadata['enabled'] ?? true,
                'permissions' => $metadata['permissions'] ?? ['allow.admin'],
                'url' => '/admin/' . $slug
            ];
        }

        // Filter by enabled status and by the permissions the user holds
        $adminModules = array_filter($adminModules, function ($m) {
            if (!$m['enabled']) {
                return false;
            }
            foreach ((array) $m['permissions'] as $permission) {
                if (!$this->userHolds($permission)) {
                    return false;
                }
            }
            return true;
        });

        // Sort by order
        usort($adminModules, fn($a, $b) => $a['order'] <=> $b['order']);

        // TODO: Cache results for 1 hour
        // Cache::set($cacheKey, $adminModules, 3600);

        return $adminModules;
    }

    /**
     * Whether the logged-in user holds a capability
     *
     * @param string $permission Capability key (e.g., 'allow.admin')
     */
    protected function userHolds(string $permission): bool {
        return Session::can($permission);
    }

    /**
     * Whether a URL may reach a method of an offering
     *
     * Only a public, non-static method that is not a constructor or magic
     * method and is declared outside the Zero\Core\ namespace is an endpoint.
     * Methods an offering inherits from the framework (loadConfig,
     * loadInlineAsset, ...) and its protected helpers are not.
     */
    protected static function isRoutableMethod(string $class, string $method): bool {
        if (!class_exists($class) || !method_exists($class, $method)) {
            return false;
        }

        $reflection = new \ReflectionMethod($class, $method);

        return $reflection->isPublic()
            && !$reflection->isStatic()
            && !$reflection->isConstructor()
            && !str_starts_with($reflection->getName(), '__')
            && !str_starts_with($reflection->getDeclaringClass()->getName(), 'Zero\\Core\\');
    }

    /**
     * Find a module's real directory name under MODULE_PATH
     *
     * Matches case-insensitively with hyphens ignored, so 'ApiConfig',
     * 'apiconfig' and 'api-config' all resolve to the directory that exists
     * ('ApiConfig'). Returns null when no such module directory exists.
     *
     * @param string $moduleName Module name in any casing, optionally kebab-case
     * @param string|null $root Directory holding the modules (defaults to MODULE_PATH)
     * @return string|null Directory name as it is on disk
     */
    protected static function resolveModuleDir($moduleName, $root = null) {
        $root = $root ?? MODULE_PATH;
        $wanted = strtolower(str_replace('-', '', (string) $moduleName));
        if ($wanted === '' || !is_dir($root)) {
            return null;
        }

        foreach (scandir($root) as $entry) {
            if ($entry[0] === '.' || !is_dir(rtrim($root, '/') . '/' . $entry)) {
                continue;
            }
            if (strtolower(str_replace('-', '', $entry)) === $wanted) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * Load admin assets inline (CSS and JS)
     *
     * Includes asset files directly in the view for security
     * Assets are not exposed as separate files in web root
     *
     * @param string $moduleName Module name (e.g., 'Links', 'Admin')
     * @param string $type Asset type: 'css' or 'js'
     * @param string $filename Filename within assets directory
     */
    protected function loadInlineAsset($moduleName, $type, $filename) {
        $moduleDir = static::resolveModuleDir($moduleName);
        $assetPath = MODULE_PATH . ($moduleDir ?? $moduleName) . "/Admin/assets/{$filename}";

        if ($moduleDir === null || !file_exists($assetPath)) {
            Console::warn("Admin asset not found: {$assetPath}");
            if (defined('DEVMODE') && DEVMODE) {
                echo "<!-- Admin asset not found: {$assetPath} -->\n";
            }
            return;
        }

        if ($type === 'css') {
            echo "<style>\n";
            include $assetPath;
            echo "\n</style>\n";
        } elseif ($type === 'js') {
            echo "<script>\n";
            include $assetPath;
            echo "\n</script>\n";
        }
    }

    /**
     * Load all CSS assets for a module's admin
     *
     * @param string $moduleName Module name
     */
    protected function loadAdminStyles($moduleName) {
        $moduleName = static::resolveModuleDir($moduleName) ?? $moduleName;
        $assetsDir = MODULE_PATH . $moduleName . "/Admin/assets";

        if (!is_dir($assetsDir)) {
            return;
        }

        $cssFiles = glob($assetsDir . "/*.css");
        foreach ($cssFiles as $cssFile) {
            $filename = basename($cssFile);
            $this->loadInlineAsset($moduleName, 'css', $filename);
        }
    }

    /**
     * Load all JS assets for a module's admin
     *
     * @param string $moduleName Module name
     */
    protected function loadAdminScripts($moduleName) {
        $moduleName = static::resolveModuleDir($moduleName) ?? $moduleName;
        $assetsDir = MODULE_PATH . $moduleName . "/Admin/assets";

        if (!is_dir($assetsDir)) {
            return;
        }

        $jsFiles = glob($assetsDir . "/*.js");
        foreach ($jsFiles as $jsFile) {
            $filename = basename($jsFile);
            $this->loadInlineAsset($moduleName, 'js', $filename);
        }
    }

    /**
     * Set breadcrumb navigation for admin pages
     *
     * @param array $crumbs Array of ['label' => 'Text', 'url' => '/path']
     */
    protected function setBreadcrumbs(array $crumbs) {
        // Always start with Admin home
        $this->breadcrumbs = [
            ['label' => 'Admin', 'url' => '/admin']
        ];

        // Append provided crumbs
        $this->breadcrumbs = array_merge($this->breadcrumbs, $crumbs);
    }

    /**
     * Route to a module's admin controller
     *
     * @param string $moduleClassName PascalCase module class name (e.g., 'Links', 'TechStack')
     * @param string $method Method name (defaults to 'index')
     * @param array $params Method parameters
     * @return mixed Result of controller method
     */
    protected function routeToModuleAdmin($moduleClassName, $method = 'index', $params = []) {
        $adminClass = "Zero\\Module\\{$moduleClassName}\\Admin\\{$moduleClassName}Admin";

        if (!class_exists($adminClass)) {
            throw new HTTPError(404, "Admin interface not found for module: {$moduleClassName}");
        }

        // Convert kebab-case to camelCase (e.g., create-workflow → createWorkflow)
        if (is_string($method) && str_contains($method, '-')) {
            $method = lcfirst(str_replace(' ', '', ucwords(str_replace('-', ' ', $method))));
        }

        // Ensure method is a string and is one of the offering's own public
        // endpoints. Checked against the class name rather than an instance so
        // the 404 — and the gate below — both settle before anything is
        // constructed.
        if (!is_string($method) || !static::isRoutableMethod($adminClass, $method)) {
            throw new HTTPError(404, "Admin method not found: " . (is_string($method) ? $method : 'invalid'));
        }

        // This is the third dispatcher, alongside Application::run() and
        // Module::__call(), and it needs its own gate for the same reason: it
        // calls a controller method directly, so attributes declared on a
        // module-admin controller never ran. /admin/* is still covered by
        // Admin's own class-level #[RequirePermission('allow.admin')], which
        // Application::run() resolves — but the per-controller attributes were
        // dead, so a controller could not require anything FINER than the
        // blanket admin permission.
        //
        // Before construction, so the gate precedes the controller's own
        // constructor work.
        Application::checkAttributes($adminClass, $method);

        $controller = new $adminClass();

        return $controller->$method(...$params);
    }
}
