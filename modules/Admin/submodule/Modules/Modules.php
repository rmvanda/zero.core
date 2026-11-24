<?php

namespace Zero\Core\Module\Admin;

use \Zero\Core\AdminResponse;
use \Zero\Core\Attribute\RequirePermission;
use \Zero\Core\Attribute\{AllowedMethods, RequiredParams, AuditLog};

/**
 * Admin's modules screen: which modules are on, and their order.
 * /admin/modules lists them; POST /admin/modules/set changes one.
 */
#[RequirePermission('allow.admin')]
class Modules extends AdminResponse {

    /**
     * The list of modules
     * GET /admin/modules
     */
    public function index() {
        $this->prepareModulesScreen();
        $this->saved = ($_GET['saved'] ?? null) === '1';
        $this->build(__DIR__."/view/index.php");
    }


    /**
     * Turn a module on or off and set its order
     * POST /admin/modules/set
     *
     * `module` is any spelling of a module's name, `hash` is the hash the
     * screen was drawn with (ModulesIni::hash()), `enabled` is on, off or
     * default (a missing one changes nothing), `order` is a whole number from
     * 0 to 9999 (empty removes it, a missing one changes nothing). Only
     * ModulesIni writes app/modules.ini. A refusal is an HTTPError; a file
     * that changed since the screen was drawn is a 409 and one the web server
     * cannot write a 500, both shown as the error text.
     */
    #[AuditLog('module_setting_change', includeParams: true)]
    #[AllowedMethods('POST')]
    #[RequiredParams(['module', 'hash'])]
    public function set() {
        $ini = $this->modulesIni();
        $hash = $_POST['hash'] ?? null;
        if (!is_string($hash)) {
            throw new \Zero\Core\Exception\HTTPError(400, 'hash must be a string');
        }

        try {
            if (!hash_equals($ini->hash(), $hash)) {
                throw new \Zero\Core\Exception\StaleFileException('app/modules.ini changed since the screen was drawn');
            }
            $this->applyModuleSetting($ini, $_POST);
            $ini->save();
        } catch (\Zero\Core\Exception\StaleFileException $e) {
            $this->failModuleSave(409, $e->getMessage() . '. ' . ADMIN_MODULES_RELOAD_HINT);
            return;
        } catch (\Zero\Core\Exception\FileNotWritableException $e) {
            $this->failModuleSave(500, $e->getMessage());
            return;
        } catch (\RuntimeException $e) {
            $this->failModuleSave(500, $e->getMessage());
            return;
        }

        if (\Zero\Core\Request::$acceptsJSON) {
            $this->export(['success' => true, 'message' => ADMIN_MODULES_SAVED, 'module' => \Zero\Core\Modules::name((string) $_POST['module'])], ['status' => 'success', 'code' => 200]);
            $this->finish();
            return;
        }
        $this->redirectTo('/admin/modules?saved=1');
    }

    /**
     * Send the browser to another URL and end the request.
     */
    protected function redirectTo(string $url): void {
        header("Location: {$url}");
        $this->finish();
    }

    /**
     * Answer a save that did not happen: JSON clients get the error with the
     * HTTP status as `code`, a form post gets the screen again with the error
     * text above the table.
     */
    protected function failModuleSave(int $code, string $message): void {
        http_response_code($code);
        if (\Zero\Core\Request::$acceptsJSON) {
            $this->export(['success' => false, 'error' => $message], ['status' => 'error', 'code' => $code]);
            $this->finish();
            return;
        }

        // For HTML requests, show the screen again with the error
        $this->activeEndpoint = 'index';
        $this->prepareModulesScreen();
        $this->saveError = $message;
        $this->build(__DIR__."/view/index.php");
    }

    /**
     * What the modules screen shows: one row per module and the hash of the
     * file as it is now, carried back by each row's form. When the file is
     * present but not what ModulesIni accepts, the screen shows the error and
     * no forms.
     */
    protected function prepareModulesScreen(): void {
        $this->moduleRows = $this->moduleRows();
        $this->saveError = null;
        $this->saved = false;
        $this->modulesHash = null;
        try {
            // modulesIni() refuses a file the writer would refuse; the hash is
            // Modules' own, of the text the rows above were read from.
            $this->modulesIni();
            $this->modulesHash = \Zero\Core\Modules::iniHash();
        } catch (\Zero\Core\Exception\HTTPError $e) {
            if ($e->getCode() !== 500 || $e->detail === 'Modules are not configured') {
                throw $e;
            }
            $this->saveError = $e->detail;
        }
    }

    /**
     * The writer of app/modules.ini for the path Modules was configured with.
     */
    protected function modulesIni(): \Zero\Core\ModulesIni {
        $file = \Zero\Core\Modules::iniFile();
        if ($file === null) {
            throw new \Zero\Core\Exception\HTTPError(500, 'Modules are not configured');
        }
        try {
            return new \Zero\Core\ModulesIni($file);
        } catch (\RuntimeException $e) {
            throw new \Zero\Core\Exception\HTTPError(500, $e->getMessage());
        }
    }

    /**
     * Check a POST and apply it to a ModulesIni (not yet saved). Anything that
     * is not a single string, a module name Modules knows, one of on/off/default
     * or a whole number in range is a 400 (404 for a module that does not exist).
     */
    protected function applyModuleSetting(\Zero\Core\ModulesIni $ini, array $post): void {
        $module = $post['module'] ?? null;
        if (!is_string($module) || $module === '') {
            throw new \Zero\Core\Exception\HTTPError(400, 'A module name is required');
        }
        $real = \Zero\Core\Modules::name($module);
        if ($real === null) {
            throw new \Zero\Core\Exception\HTTPError(404, 'Unknown module');
        }

        $enabled = $post['enabled'] ?? null;
        if ($enabled !== null && (!is_string($enabled) || !in_array($enabled, ['on', 'off', 'default'], true))) {
            throw new \Zero\Core\Exception\HTTPError(400, 'enabled must be on, off or default');
        }

        $order = $post['order'] ?? null;
        $orderValue = null;
        if ($order !== null) {
            if (!is_string($order)) {
                throw new \Zero\Core\Exception\HTTPError(400, 'order must be a whole number');
            }
            $order = trim($order);
            if ($order !== '') {
                if (!preg_match('/^[0-9]{1,4}$/', $order)) {
                    throw new \Zero\Core\Exception\HTTPError(400, 'order must be a whole number from 0 to 9999');
                }
                $orderValue = (int) $order;
            }
        }

        try {
            if ($enabled !== null) {
                $ini->setEnabled($real, $enabled === 'default' ? null : $enabled === 'on');
            }
            if ($order !== null) {
                $ini->setOrder($real, $orderValue);
            }
        } catch (\DomainException $e) {
            throw new \Zero\Core\Exception\HTTPError(400, $e->getMessage());
        }
    }

    /**
     * One row per module of every root for the modules screen, ordered by tier
     * (core, example, site) then name. `where` is the root the module that
     * answers lives in; `overridden` says a site module stands in for core's.
     *
     * @return array<int,array{name:string,tier:string,where:string,overridden:bool,enabled:bool,default:bool,departure:bool,order:?int,settable:bool}>
     */
    protected function moduleRows(): array {
        $tiers = ['core' => 0, 'example' => 1, 'site' => 2];

        $rows = [];
        foreach (\Zero\Core\Modules::all() as $name => $path) {
            $name = (string) $name;
            $tier = \Zero\Core\Modules::tier($name);
            $settable = \Zero\Core\ModulesIni::isSettable($name);
            $enabled = \Zero\Core\Modules::enabled($name);
            $default = $tier !== 'example';
            $rows[] = [
                'name' => $name,
                'tier' => $tier,
                'where' => \Zero\Core\Modules::isCore($name) ? 'core' : 'site',
                'overridden' => \Zero\Core\Modules::overridden($name),
                'enabled' => $enabled,
                'default' => $default,
                'departure' => $enabled !== $default,
                'order' => \Zero\Core\Modules::order($name),
                'settable' => $settable,
            ];
        }
        usort($rows, fn($a, $b) => $tiers[$a['tier']] <=> $tiers[$b['tier']] ?: strcasecmp($a['name'], $b['name']));

        return $rows;
    }
}
