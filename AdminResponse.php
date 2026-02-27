<?php

namespace Zero\Core;

use Zero\Core\Module;
use Zero\Core\User;

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
     */
    public function __construct() {
        // Check base admin access
        if (!User::hasPermission('allow.admin')) {
            // Redirect to login with return URL
            header('Location: /auth/login?redirect=' . urlencode($_SERVER['REQUEST_URI']));
            exit;
        }

        parent::__construct();
    }

    /**
     * Discover all modules with admin capabilities
     *
     * Scans MODULE_PATH for directories containing an Admin subdirectory
     * Loads optional admin-config.php for metadata
     *
     * @return array Array of admin module configurations
     */
    protected function getAdminModules() {
        // Check cache first
        $cacheKey = 'admin_modules_discovery';
        // TODO: Implement caching when Cache class is available
        // if ($cached = Cache::get($cacheKey)) {
        //     return $cached;
        // }

        $adminModules = [];
        $adminDirs = glob(MODULE_PATH . '*/Admin', GLOB_ONLYDIR);

        foreach ($adminDirs as $dir) {
            // Extract module name from path
            preg_match('#/([^/]+)/Admin$#', $dir, $matches);
            $moduleName = $matches[1];

            // Skip the main Admin module itself from discovery
            if (strtolower($moduleName) === 'admin') {
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

        // Filter by enabled status
        $adminModules = array_filter($adminModules, fn($m) => $m['enabled']);

        // Sort by order
        usort($adminModules, fn($a, $b) => $a['order'] <=> $b['order']);

        // TODO: Cache results for 1 hour
        // Cache::set($cacheKey, $adminModules, 3600);

        return $adminModules;
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
        $moduleName = ucfirst(strtolower($moduleName));
        $assetPath = MODULE_PATH . $moduleName . "/Admin/assets/{$filename}";

        if (!file_exists($assetPath)) {
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
        $moduleName = ucfirst(strtolower($moduleName));
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
        $moduleName = ucfirst(strtolower($moduleName));
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
            new Error(404, "Admin interface not found for module: {$moduleClassName}");
            return;
        }

        $controller = new $adminClass();

        // Ensure method is a string and exists
        if (!is_string($method) || !method_exists($controller, $method)) {
            new Error(404, "Admin method not found: " . (is_string($method) ? $method : 'invalid'));
            return;
        }

        return $controller->$method(...$params);
    }
}
