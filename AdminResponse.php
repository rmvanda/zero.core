<?php

namespace Zero\Core;
use Zero\Core\Exception\HTTPError;

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
    /** The icon font of the admin screens (Material Symbols Outlined), linked by getStylesheets(). */
    protected const ICON_FONT = 'https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200';


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
        // Anyone who is not an admin, signed in or not, gets the 404 an unknown
        // URL gets: the same page, from the same plain Response, with the same
        // detail (the endpoint). Nothing says an admin lives here, and there is
        // no login redirect or access request to give it away.
        if (!Session::can('allow.admin')) {
            static::refuse();
        }

        parent::__construct();

        // Remove any auto-created public symlink — admin assets must not be web-accessible
        $fullClass = get_class($this);
        $modulePath = (string) Modules::classRest($fullClass);
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
     * Answer as if nothing were at this URL, and end the request.
     */
    protected static function refuse(): void {
        (new Response())->renderError(new HTTPError(404, Request::$endpoint));
    }

    /**
     * Finish an action that worked: a JSON client gets the message and $data,
     * a browser is sent to $url (so a reload does not post the form again).
     * An action that did not work throws an HTTPError instead: the error page,
     * or `detail` for a JSON client, says why.
     */
    protected function done(string $message, string $url, array $data = []): void {
        if (Request::$acceptsJSON) {
            $this->export(array_merge(['success' => true, 'message' => $message], $data));
        } else {
            header("Location: {$url}");
        }
        $this->finish();
    }

    /**
     * End the request after a response that is complete.
     */
    protected function finish(): void {
        exit;
    }

    /**
     * Override: inline admin stylesheets instead of linking to public URLs
     *
     * Always loads shared admin base styles first, then module-specific styles.
     * Mirrors the filename-matching logic of Response::loadAssetTypeFromDir.
     */
    protected function getStylesheets() {
        // The admin screens draw their icons with this font. A frame that already
        // links the same URL costs nothing: the browser fetches it once.
        echo '<link rel="stylesheet" href="' . self::ICON_FONT . '">' . "\n";
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
        $adminModuleDir = Modules::path('Admin');
        $adminDir = $adminModuleDir === null ? null : $adminModuleDir . 'assets/' . $type . '/';
        $moduleDir = $this->moduleAssetDir . $type . '/';

        // Search admin assets dir first, then module-specific dir
        foreach (array_unique(array_filter([$adminDir, $moduleDir])) as $dir) {
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
     * Walks Modules::all() for modules with an Admin subdirectory and lists a
     * module only when it is switched on (Modules::enabled()), Zero\Module\{X}\Admin\{X}Admin
     * exists (the class Admin::__call routes to) and the logged-in user holds
     * every key in the card's `permissions`. Loads optional admin-config.php
     * for metadata. Cards are sorted by Modules::order(), modules without an
     * order last, then by name.
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

        foreach (Modules::all() as $moduleName => $moduleDir) {
            $moduleName = (string) $moduleName;
            $dir = $moduleDir . 'Admin';

            // Skip the main Admin module itself from discovery
            if (strtolower($moduleName) === 'admin') {
                continue;
            }

            if (!is_dir($dir) || !Modules::enabled($moduleName)) {
                continue;
            }

            // A folder is not an offering: the class must exist
            // (a load failure in one offering leaves only that module unlisted)
            try {
                $offeringExists = class_exists(Modules::controllerClass($moduleName) . "\\Admin\\{$moduleName}Admin");
            } catch (\Throwable $e) {
                Console::warn("Admin offering {$moduleName} failed to load: " . $e->getMessage());
                continue;
            }
            if (!$offeringExists) {
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
                'order' => Modules::order($moduleName),
                'permissions' => $metadata['permissions'] ?? ['allow.admin'],
                'url' => '/admin/' . $slug
            ];
        }

        // Filter by the permissions the user holds
        $adminModules = array_filter($adminModules, function ($m) {
            foreach ((array) $m['permissions'] as $permission) {
                if (!$this->userHolds($permission)) {
                    return false;
                }
            }
            return true;
        });

        // Sort by order, modules without one last, then by name
        usort($adminModules, fn($a, $b) =>
            [$a['order'] === null, $a['order'] ?? 0, $a['name']]
            <=> [$b['order'] === null, $b['order'] ?? 0, $b['name']]
        );

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
     * method and is declared by a module, not by the framework itself, is an
     * endpoint (a module of core, Zero\Core\Module\, is a module).
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
            // a method the framework declares is no endpoint; one a module of core declares is
            && (!str_starts_with($reflection->getDeclaringClass()->getName(), 'Zero\\Core\\')
                || Modules::classRest($reflection->getDeclaringClass()->getName()) !== null);
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
        $moduleDir = Modules::path($moduleName);
        $assetPath = ($moduleDir ?? '') . "Admin/assets/{$filename}";

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
        $moduleDir = Modules::path($moduleName);
        $moduleName = Modules::name($moduleName) ?? $moduleName;

        if ($moduleDir === null || !is_dir($assetsDir = $moduleDir . "Admin/assets")) {
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
        $moduleDir = Modules::path($moduleName);
        $moduleName = Modules::name($moduleName) ?? $moduleName;

        if ($moduleDir === null || !is_dir($assetsDir = $moduleDir . "Admin/assets")) {
            return;
        }

        $jsFiles = glob($assetsDir . "/*.js");
        foreach ($jsFiles as $jsFile) {
            $filename = basename($jsFile);
            $this->loadInlineAsset($moduleName, 'js', $filename);
        }
    }

    /**
     * Set the breadcrumbs of this page: "Admin", then $crumbs.
     *
     * A screen that sets none gets "Admin", then its own card's label. Every
     * admin page prints them above its view (beforeView()); a view prints none.
     *
     * @param array $crumbs Array of ['label' => 'Text', 'url' => '/path']; the
     *                      last one is the page itself and needs no url
     */
    protected function setBreadcrumbs(array $crumbs) {
        $this->breadcrumbs = array_merge([['label' => 'Admin', 'url' => '/admin']], $crumbs);
    }

    /**
     * Add one crumb after the ones this page already has.
     */
    protected function addBreadcrumb(string $label, ?string $url = null): void {
        if (!$this->breadcrumbs) {
            $this->breadcrumbs = $this->defaultBreadcrumbs();
        }
        $this->breadcrumbs[] = ['label' => $label, 'url' => $url];
    }

    /**
     * "Admin", then this screen's card: the label in the admin-config.php
     * beside the controller and the URL the card links to. The dashboard
     * itself has none.
     */
    protected function defaultBreadcrumbs(): array {
        $card = $this->card();
        if ($card === null) {
            return [];
        }
        return [['label' => 'Admin', 'url' => '/admin'], $card];
    }

    /**
     * This controller's own card, ['label' => ..., 'url' => ...], or null for
     * the Admin module itself.
     */
    protected function card(): ?array {
        $class = (string) Modules::classRest(static::class);
        $parts = explode('\\', $class);
        if (count($parts) < 2) {
            return null;
        }
        // An offering is Zero\Module\{X}\Admin\{X}Admin; anything else under
        // Zero\Module\Admin\ is one of Admin's own screens.
        $name = $parts[0] === 'Admin' ? $parts[1] : $parts[0];
        $slug = strtolower(preg_replace('/([a-z])([A-Z])/', '$1-$2', $name));
        $file = dirname((new \ReflectionClass(static::class))->getFileName()) . '/admin-config.php';
        $config = is_file($file) ? (array) include $file : [];

        return ['label' => $config['label'] ?? $name, 'url' => '/admin/' . $slug];
    }

    /**
     * Open the element every admin screen sits in, and print the breadcrumbs above the view.
     *
     * admin.css scopes its rules to `.admin`, so they outrank a site's main.css
     * whichever of the two the frame loads first.
     */
    protected function beforeView(): void {
        echo '<div class="admin">' . "\n";
        $crumbs = $this->breadcrumbs ?: $this->defaultBreadcrumbs();
        if (!$crumbs) {
            return;
        }
        $last = count($crumbs) - 1;
        echo '<nav class="admin-breadcrumbs" aria-label="Breadcrumb"><ol>';
        foreach (array_values($crumbs) as $i => $crumb) {
            $label = htmlspecialchars((string) ($crumb['label'] ?? ''));
            echo $i === $last || empty($crumb['url'])
                ? '<li' . ($i === $last ? ' aria-current="page"' : '') . '>' . $label . '</li>'
                : '<li><a href="' . htmlspecialchars((string) $crumb['url']) . '">' . $label . '</a></li>';
        }
        echo "</ol></nav>\n";
    }

    /**
     * Close the element beforeView() opened.
     */
    protected function afterView(): void {
        echo "</div>\n";
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
        $adminClass = Modules::controllerClass($moduleClassName) . "\\Admin\\{$moduleClassName}Admin";

        // A module that is switched off has no admin: answered before this
        // method looks for the class. Admin::__call makes the same check before
        // its own class_exists(), so the autoloader never opens the file.
        if (!Modules::routable($moduleClassName)) {
            throw new HTTPError(404, "Admin interface not found for module: {$moduleClassName}");
        }

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

        // The offering's module is not the live module (that is Admin), so
        // its wording and its constants are defined here, before construction.
        Application::loadModuleConfig($moduleClassName);

        $controller = new $adminClass();

        return $controller->$method(...$params);
    }
}
