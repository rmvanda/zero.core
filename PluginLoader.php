<?php

namespace Zero\Core;

use \Zero\Core\Console;
use \Zero\Plugin;

/**
 * Discovers and manages plugins for the Zero Framework.
 *
 * Scans plugin/ for subdirectories containing a matching .php file.
 * Plugins are instantiated once and their hooks are called at the
 * appropriate lifecycle points by Application.
 *
 * Usage in Application.php:
 *   $this->plugins = PluginLoader::load();
 *   PluginLoader::hook($this->plugins, 'afterAutoloaders');
 */
class PluginLoader {

    /**
     * Discover and instantiate all plugins.
     *
     * Scans plugin/ for {Name}/{Name}.php files,
     * instantiates each, sorts by priority, and returns them.
     *
     * @return Plugin[] Array of plugin instances, sorted by priority
     */
    public static function load(): array {
        $pluginDir = ZERO_ROOT . 'plugin/';
        $cacheBase = ZERO_ROOT . 'storage/cache/plugin/';
        $plugins = [];

        // Ensure Plugin base class is loaded
        require_once ZERO_ROOT . 'plugin/Plugin.php';

        if (!is_dir($pluginDir)) {
            return [];
        }

        $dirs = glob($pluginDir . '*', GLOB_ONLYDIR);

        //Console::debug("PluginLoader: Found " . count($dirs) . " plugin directories");

        foreach ($dirs as $dir) {
            $name = basename($dir);
            $file = $dir . '/' . $name . '.php';

            //Console::debug("PluginLoader: Checking {$name} at {$file}");

            if (!file_exists($file)) {
                //Console::debug("PluginLoader: File not found, skipping");
                continue;
            }

            require_once $file;

            $class = '\\Zero\\Plugin\\' . $name;
            //Console::debug("PluginLoader: Looking for class {$class}");

            if (!class_exists($class)) {
                //Console::warn("Plugin file {$file} exists but class {$class} not found");
                continue;
            }

            $plugin = new $class();

            if (!$plugin instanceof Plugin) {
                //Console::warn("Plugin {$class} does not extend \\Zero\\Plugin base class");
                continue;
            }

            // Set cache directory for this plugin
            $plugin->cacheDir = $cacheBase . $name . '/';

            $plugins[] = $plugin;
            //Console::debug("Loaded plugin: {$name} (priority: {$plugin->getPriority()})");
        }

        // Sort by priority (lower first), then alphabetical for ties
        usort($plugins, function (Plugin $a, Plugin $b) {
            $p = $a->getPriority() <=> $b->getPriority();
            return $p !== 0 ? $p : get_class($a) <=> get_class($b);
        });

        return $plugins;
    }

    /**
     * Call a lifecycle hook on all loaded plugins.
     *
     * @param Plugin[] $plugins Array of plugin instances
     * @param string $hook Hook method name (e.g., 'afterConstants')
     * @param mixed ...$args Arguments to pass to the hook
     */
    public static function hook(array $plugins, string $hook, mixed ...$args): void {
        foreach ($plugins as $plugin) {
            try {
                $plugin->$hook(...$args);
            } catch (\Throwable $e) {
                // Plugins must never break the framework
                $name = get_class($plugin);
                //Console::error("Plugin {$name}::{$hook}() threw: " . $e->getMessage());
            }
        }
    }
}
