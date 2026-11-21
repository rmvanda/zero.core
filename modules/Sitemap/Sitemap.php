<?php

namespace Zero\Module;

use \Zero\Core\Attribute\RequireAuthLevel;

#[RequireAuthLevel(1)]
class Sitemap extends \Zero\Core\Response {

    /**
     * Display HTML sitemap
     */
    public function index() {
        // Static pages
        $this->staticPages = [
            '/' => 'Home',
            '/about' => 'About',
            '/privacy' => 'Privacy Policy',
            '/help' => 'Help',
            '/zero-framework' => 'Zero Framework',
            '/ruin-the-web' => 'How to Ruin the Web',
        ];

        // Discover modules with their routes
        $this->modulesWithRoutes = $this->discoverModulesWithRoutes();

        $this->build(__DIR__ . '/view/index.php');
    }

    /**
     * Generate XML sitemap
     */
    public function xml() {
        header('Content-Type: application/xml; charset=utf-8');

        $baseUrl = 'https://' . PRIMARY_DOMAIN;
        $staticPages = [
            '/' => ['priority' => '1.0', 'changefreq' => 'daily'],
            '/about' => ['priority' => '0.8', 'changefreq' => 'monthly'],
            '/privacy' => ['priority' => '0.5', 'changefreq' => 'monthly'],
            '/help' => ['priority' => '0.7', 'changefreq' => 'monthly'],
            '/zero-framework' => ['priority' => '0.9', 'changefreq' => 'weekly'],
            '/ruin-the-web' => ['priority' => '0.6', 'changefreq' => 'monthly'],
        ];

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

        foreach ($staticPages as $url => $meta) {
            $xml .= "  <url>\n";
            $xml .= "    <loc>{$baseUrl}{$url}</loc>\n";
            $xml .= "    <changefreq>{$meta['changefreq']}</changefreq>\n";
            $xml .= "    <priority>{$meta['priority']}</priority>\n";
            $xml .= "  </url>\n";
        }

        // Add discovered module routes
        $moduleRoutes = $this->getAllModuleRoutes();
        foreach ($moduleRoutes as $route) {
            $xml .= "  <url>\n";
            $xml .= "    <loc>{$baseUrl}{$route}</loc>\n";
            $xml .= "    <changefreq>weekly</changefreq>\n";
            $xml .= "    <priority>0.7</priority>\n";
            $xml .= "  </url>\n";
        }

        $xml .= '</urlset>';

        echo $xml;
    }

    /**
     * Discover available modules with their routes
     */
    private function discoverModulesWithRoutes(): array {
        $modulesData = [];

        // Every module of every root that is switched on, by name
        $all = \Zero\Core\Modules::all();
        ksort($all);
        foreach ($all as $dir => $modulePath) {
            $dir = (string) $dir;
            if (!\Zero\Core\Modules::enabled($dir)) continue;
            $modulePath = rtrim($modulePath, '/');

            // Check if module has a main class file
            $classFile = $modulePath . '/' . $dir . '.php';
            if (!file_exists($classFile)) continue;

            // Skip certain modules
            if (in_array($dir, ['Admin', 'Sitemap'])) continue;

            // Convert to route (PascalCase -> kebab-case)
            $baseRoute = '/' . strtolower(preg_replace('/(?<!^)[A-Z]/', '-$0', $dir));

            // Discover routes within this module
            $routes = $this->discoverModuleRoutes($dir, $baseRoute, $classFile, $modulePath);

            $modulesData[] = [
                'name' => $dir,
                'base_route' => $baseRoute,
                'routes' => $routes
            ];
        }

        return $modulesData;
    }

    /**
     * Discover routes within a specific module
     */
    private function discoverModuleRoutes(string $moduleName, string $baseRoute, string $classFile, string $modulePath): array {
        $routes = [];

        // Add base route (index)
        $routes[] = [
            'url' => $baseRoute,
            'name' => 'Index',
            'type' => 'method'
        ];

        // Discover public methods from class file
        $className = "Zero\\Module\\{$moduleName}";

        if (class_exists($className)) {
            try {
                $reflection = new \ReflectionClass($className);
                $methods = $reflection->getMethods(\ReflectionMethod::IS_PUBLIC);

                foreach ($methods as $method) {
                    // Skip magic methods, inherited methods, and index
                    if (strpos($method->name, '__') === 0) continue;
                    if ($method->class !== $className) continue;
                    if ($method->name === 'index') continue;

                    // Convert camelCase to kebab-case
                    $methodRoute = strtolower(preg_replace('/(?<!^)[A-Z]/', '-$0', $method->name));

                    $routes[] = [
                        'url' => $baseRoute . '/' . $methodRoute,
                        'name' => ucwords(str_replace('-', ' ', $methodRoute)),
                        'type' => 'method'
                    ];
                }
            } catch (\Exception $e) {
                \Zero\Core\Console::warn("Sitemap: Error reflecting {$className}: " . $e->getMessage());
            }
        }

        // Discover view files
        $viewPath = $modulePath . '/view';
        if (is_dir($viewPath)) {
            $viewFiles = scandir($viewPath);
            foreach ($viewFiles as $file) {
                if ($file === '.' || $file === '..') continue;
                if (!str_ends_with($file, '.php')) continue;
                if (str_starts_with($file, '_')) continue; // Skip partials

                $viewName = basename($file, '.php');

                // Skip if we already have this as a method route
                $viewRoute = $viewName === 'index' ? $baseRoute : $baseRoute . '/' . $viewName;
                $exists = false;
                foreach ($routes as $route) {
                    if ($route['url'] === $viewRoute) {
                        $exists = true;
                        break;
                    }
                }

                if (!$exists) {
                    $routes[] = [
                        'url' => $viewRoute,
                        'name' => ucwords(str_replace(['-', '_'], ' ', $viewName)),
                        'type' => 'view'
                    ];
                }
            }
        }

        return $routes;
    }

    /**
     * Get flat list of all module routes for XML sitemap
     */
    private function getAllModuleRoutes(): array {
        $allRoutes = [];
        $modulesData = $this->discoverModulesWithRoutes();

        foreach ($modulesData as $module) {
            foreach ($module['routes'] as $route) {
                $allRoutes[] = $route['url'];
            }
        }

        return $allRoutes;
    }
}
