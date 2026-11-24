<?php

namespace Zero\Core\Module;

use \Zero\Core\Attribute\RequirePermission;
use \Zero\Core\AdminResponse;
use \Zero\Core\Application;
use \Zero\Core\Console;
use \Zero\Core\Modules;

/**
 * The /admin hub: the dashboard and the router.
 *
 * Admin's own screens (Users, Groups, Permissions, Audit, Firewall, Modules) are its submodules,
 * in submodule/. Every other admin screen belongs to the module it
 * administers, offered from that module's Admin/ folder.
 */
#[RequirePermission('allow.admin')]
class Admin extends AdminResponse {

    /**
     * Admin dashboard: Admin's own screens, then the modules that offer one
     */
    public function index() {
        $this->ownScreens = $this->ownScreens();
        $this->adminModules = $this->getAdminModules();

        $this->build(__DIR__."/view/index.php");
    }

    /**
     * Route /admin/{name}/... to one of Admin's own screens or to a module's
     * admin controller (e.g. /admin/links, /admin/links/edit/5).
     *
     * One of Admin's own screens (a folder in submodule/) wins over a module
     * of the same name: a module called Users cannot take over /admin/users.
     * Otherwise the name is looked up as Zero\Core\Module\{X}\Admin\{X}Admin, and
     * failing that the request falls through to Module::__call.
     *
     * @param string $moduleName Module name from URL
     * @param array $args Additional URL segments
     */
    public function __call($moduleName, $args) {
        // Convert kebab-case to PascalCase (tech-stack → TechStack)
        $moduleClassName = str_replace(' ', '', ucwords(str_replace('-', ' ', $moduleName)));
        $adminClass = Modules::controllerClass($moduleClassName) . "\\Admin\\{$moduleClassName}Admin";

        // Only a well-formed name of a module that is switched on is looked up:
        // class_exists() runs the autoloader, which would open a disabled
        // module's admin file, and a URL-derived name must be one class name.
        $offered = Application::isModuleName($moduleClassName)
            && Modules::routable($moduleClassName)
            && class_exists($adminClass);

        if ($this->isOwnScreen($moduleName)) {
            if ($offered) {
                Console::warn("/admin/{$moduleName} is Admin's own screen; the admin screen module {$moduleClassName} offers under that name is unreachable");
            }
        } elseif ($offered) {
            // Extract method and params from URL segments
            $method = $args[0] ?? 'index';
            $params = array_slice($args, 1);

            return $this->routeToModuleAdmin($moduleClassName, $method, $params);
        }

        // Module::__call serves Admin's own screens (submodule/)
        parent::__call($moduleName, $args);
    }

    /**
     * Whether a URL segment names one of Admin's own screens, by the rule
     * Module::__call finds a submodule with.
     */
    protected function isOwnScreen(string $segment): bool {
        $name = ucfirst($segment);

        return Application::isModuleName($name)
            && is_file(__DIR__ . "/submodule/{$name}/{$name}.php");
    }

    /**
     * The cards of Admin's own screens: every folder in submodule/ that holds
     * its controller and an admin-config.php. The card's label, icon and
     * description come from that file, and so does its `order` among Admin's
     * own cards (lowest first, then by name).
     *
     * @return array<int,array{name:string,label:string,icon:string,description:string,order:int,url:string}>
     */
    protected function ownScreens(): array {
        $screens = [];
        foreach (glob(__DIR__ . '/submodule/*/admin-config.php') ?: [] as $file) {
            $name = basename(dirname($file));
            if (!is_file(dirname($file) . "/{$name}.php")) {
                continue;
            }
            $config = (array) include $file;
            $screens[] = [
                'name' => $name,
                'label' => $config['label'] ?? $name,
                'icon' => $config['icon'] ?? 'settings',
                'description' => $config['description'] ?? '',
                'order' => (int) ($config['order'] ?? PHP_INT_MAX),
                'url' => '/admin/' . strtolower($name),
            ];
        }
        usort($screens, fn($a, $b) => [$a['order'], $a['name']] <=> [$b['order'], $b['name']]);

        return $screens;
    }
}
