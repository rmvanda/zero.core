<?php

namespace Zero\Module;

use \Zero\Core\Attribute\RequirePermission;
use \Zero\Core\Attribute\{AllowedMethods, RequiredParams, AuditLog};
use \Zero\Core\AdminResponse;

#[RequirePermission('allow.admin')]
class Admin extends AdminResponse {

    /**
     * Admin dashboard - show module management and admin modules
     */
    public function index() {
        // Get all modules with admin capabilities
        $this->adminModules = $this->getAdminModules();

        $this->build(__DIR__."/view/index.php");
    }

    /**
     * Route to module admin controllers or Admin submodules
     * Handles URLs like /admin/links, /admin/links/edit/5, etc.
     *
     * First checks for AdminResponse controllers in other modules
     * (e.g. Zero\Module\Links\Admin\LinksAdmin), then falls through
     * to Module::__call for Admin's own submodules (e.g. Firewall).
     *
     * @param string $moduleName Module name from URL
     * @param array $args Additional URL segments
     */
    public function __call($moduleName, $args) {
        // Convert kebab-case to PascalCase (tech-stack → TechStack)
        $moduleClassName = str_replace(' ', '', ucwords(str_replace('-', ' ', $moduleName)));
        $adminClass = "Zero\\Module\\{$moduleClassName}\\Admin\\{$moduleClassName}Admin";

        // Only a well-formed name of a module that is switched on is looked up:
        // class_exists() runs the autoloader, which would open a disabled
        // module's admin file, and a URL-derived name must be one class name.
        if (\Zero\Core\Application::isModuleName($moduleClassName)
            && \Zero\Core\Modules::routable($moduleClassName)
            && class_exists($adminClass)) {
            // Extract method and params from URL segments
            $method = $args[0] ?? 'index';
            $params = array_slice($args, 1);

            return $this->routeToModuleAdmin($moduleClassName, $method, $params);
        }

        // Fall through to Module::__call for Admin submodules (Firewall, etc.)
        parent::__call($moduleName, $args);
    }

    public function manage($type = null, $id = null) {
        if (!$type) {
            throw new \Zero\Core\HTTPError(400, 'Management type required');
        }

        // Drive per-view asset loading off $type so /admin/manage/users picks up
        // manage-users.{js,css}, /admin/manage/group/5 picks up manage-group.{js,css},
        // etc. (The URL's endpoint is just "manage" otherwise.)
        $this->activeEndpoint = 'manage-'.$type;

        // Route to appropriate management view
        switch($type){
            case "users":
            case "user":
            if ($id) {
                // Edit specific user's permissions
                $this->userId = $id;
                $this->build(__DIR__."/view/manage-user.php");
            } else {
                // List all users
                $this->build(__DIR__."/view/manage-users.php");
            }
            break;
            case "groups":
            case "group":
                if ($id) {
                    // Manage specific group members
                    $this->groupId = $id;
                    $this->group = $this->findGroup($id);
                    $this->build(__DIR__."/view/manage-group-members.php");
                } else {
                    // List all groups
                    $this->build(__DIR__."/view/manage-groups.php");
                }
            break;
            case "modules":
                $this->prepareModulesScreen();
                $this->saved = ($_GET['saved'] ?? null) === '1';
                $this->build(__DIR__."/view/manage-modules.php");
            break;
            default:
                throw new \Zero\Core\HTTPError(404, "Unknown management type: {$type}");
        }
    }

    /**
     * Update one of a user's capabilities or settings
     * POST /admin/update-permission
     *
     * `kind` is "capability" (a row in `permission`, granted when
     * setting_value is "1" and revoked otherwise) or "setting" (a value in
     * user_settings). Without it, an allow.* key is a capability and
     * anything else is a setting.
     */
    #[AuditLog('admin_permission_change', includeParams: true)]
    #[AllowedMethods('POST')]
    #[RequiredParams(['user_id', 'setting_key'])]
    public function updatePermission() {
        $userId = $_POST['user_id'];
        $settingKey = $_POST['setting_key'];
        $settingValue = $_POST['setting_value'] ?? null;
        $action = $_POST['action'] ?? 'update';

        $currentUserId = \Zero\Core\Session::userId();
        $kind = $_POST['kind'] ?? (str_starts_with($settingKey, 'allow.') ? 'capability' : 'setting');

        try {
            if ($kind === 'capability') {
                $targetUser = \Zero\Core\User::find($userId);
                if (!$targetUser) {
                    throw new \Exception("User {$userId} not found");
                }
                $ok = ($action !== 'delete' && $settingValue === '1')
                    ? $targetUser->grant($settingKey, $currentUserId)
                    : $targetUser->revoke($settingKey);
                if (!$ok) {
                    throw new \Exception("Failed to save capability '{$settingKey}' for user {$userId}");
                }
            } elseif ($action === 'delete') {
                // No setSetting() equivalent for removal — it only upserts —
                // so the delete path stays hand-rolled.
                $db = \Zero\Core\Database::getConnection();
                $stmt = $db->prepare("DELETE FROM user_settings WHERE user_id = ? AND setting_key = ?");
                $stmt->execute([$userId, $settingKey]);
            } else {
                // Route through User::setSetting() — $currentUserId is
                // the acting admin, recorded as updated_by via setSetting()'s
                // $actorId parameter.
                $targetUser = \Zero\Core\User::find($userId);
                if (!$targetUser) {
                    throw new \Exception("User {$userId} not found");
                }
                if (!$targetUser->setSetting($settingKey, $settingValue, $currentUserId)) {
                    throw new \Exception("Failed to save setting '{$settingKey}' for user {$userId}");
                }
            }

            $this->success('Permission updated successfully', ['user_id' => $userId]);

            // For HTML requests, redirect back to user management
            header("Location: /admin/manage/user/$userId");
            exit;
        } catch (\Exception $e) {
            error_log("Error updating permission: " . $e->getMessage());
            $this->error('Failed to update permission', ['error' => $e->getMessage()]);

            // For HTML requests, redirect back with error
            header("Location: /admin/manage/user/$userId");
            exit;
        }
    }

    // ============================================================================
    // USER MANAGEMENT
    // ============================================================================

    /**
     * Delete a user
     * POST /admin/delete-user
     */
    #[AuditLog('user_delete', includeParams: true)]
    #[AllowedMethods('POST')]
    #[RequiredParams(['user_id'])]
    public function deleteUser() {
        $userId = (int)$_POST['user_id'];

        if (!$userId) {
            $this->error('Invalid user ID');
            return;
        }

        // Prevent deleting yourself
        if ($userId == \Zero\Core\Session::userId()) {
            $this->error('You cannot delete your own account');
            return;
        }

        $db = \Zero\Core\Database::getConnection();

        try {
            $db->beginTransaction();

            // The cascade lives in UserAdmin: there are no foreign keys onto
            // `users`, so UserAdmin's table list, with what Zero\Core\User::delete()
            // clears, IS the guarantee, and it needs a home
            // with a test rather than a controller body. Previously this method
            // cleared user_settings, user_group_member, api_tokens and oauth_token
            // but not user_profile or auth_token, both added after it was written.
            (new \Zero\Module\Admin\Model\UserAdmin($db))->delete($userId);

            $db->commit();

            $this->success('User deleted successfully', ['user_id' => $userId]);
        } catch (\Exception $e) {
            $db->rollBack();
            error_log("Error deleting user: " . $e->getMessage());
            $this->error('Failed to delete user: ' . $e->getMessage());
        }
    }

    // ============================================================================
    // MODULE MANAGEMENT
    // ============================================================================

    /**
     * Turn a module on or off and set its order
     * POST /admin/set-module
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
    public function setModule() {
        $ini = $this->modulesIni();
        $hash = $_POST['hash'] ?? null;
        if (!is_string($hash)) {
            throw new \Zero\Core\HTTPError(400, 'hash must be a string');
        }

        try {
            if (!hash_equals($ini->hash(), $hash)) {
                throw new \Zero\Core\StaleFileException('app/modules.ini changed since the screen was drawn');
            }
            $this->applyModuleSetting($ini, $_POST);
            $ini->save();
        } catch (\Zero\Core\StaleFileException $e) {
            $this->failModuleSave(409, $e->getMessage() . '. ' . ADMIN_MODULES_RELOAD_HINT);
            return;
        } catch (\Zero\Core\FileNotWritableException $e) {
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
        $this->redirectTo('/admin/manage/modules?saved=1');
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
        $this->activeEndpoint = 'manage-modules';
        $this->prepareModulesScreen();
        $this->saveError = $message;
        $this->build(__DIR__."/view/manage-modules.php");
    }

    /**
     * End the request after a response that is complete.
     */
    protected function finish(): void {
        exit;
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
        } catch (\Zero\Core\HTTPError $e) {
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
            throw new \Zero\Core\HTTPError(500, 'Modules are not configured');
        }
        try {
            return new \Zero\Core\ModulesIni($file);
        } catch (\RuntimeException $e) {
            throw new \Zero\Core\HTTPError(500, $e->getMessage());
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
            throw new \Zero\Core\HTTPError(400, 'A module name is required');
        }
        $real = \Zero\Core\Modules::name($module);
        if ($real === null) {
            throw new \Zero\Core\HTTPError(404, 'Unknown module');
        }

        $enabled = $post['enabled'] ?? null;
        if ($enabled !== null && (!is_string($enabled) || !in_array($enabled, ['on', 'off', 'default'], true))) {
            throw new \Zero\Core\HTTPError(400, 'enabled must be on, off or default');
        }

        $order = $post['order'] ?? null;
        $orderValue = null;
        if ($order !== null) {
            if (!is_string($order)) {
                throw new \Zero\Core\HTTPError(400, 'order must be a whole number');
            }
            $order = trim($order);
            if ($order !== '') {
                if (!preg_match('/^[0-9]{1,4}$/', $order)) {
                    throw new \Zero\Core\HTTPError(400, 'order must be a whole number from 0 to 9999');
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
            throw new \Zero\Core\HTTPError(400, $e->getMessage());
        }
    }

    /**
     * The folder core's own modules live in (trailing slash).
     */
    protected function coreModulesDir(): string {
        return dirname(__DIR__) . '/';
    }

    /**
     * One row per module of every root for the modules screen, ordered by tier
     * (required, optional, example, site) then name.
     *
     * @return array<int,array{name:string,tier:string,where:string,enabled:bool,default:bool,departure:bool,order:?int,canSwitch:bool,settable:bool}>
     */
    protected function moduleRows(): array {
        $core = realpath($this->coreModulesDir());
        $core = $core === false ? null : rtrim($core, '/') . '/';
        $tiers = ['required' => 0, 'optional' => 1, 'example' => 2, 'site' => 3];

        $rows = [];
        foreach (\Zero\Core\Modules::all() as $name => $path) {
            $name = (string) $name;
            $tier = \Zero\Core\Modules::tier($name);
            $real = realpath($path);
            $settable = \Zero\Core\ModulesIni::isSettable($name);
            $enabled = \Zero\Core\Modules::enabled($name);
            $default = $tier !== 'example';
            $rows[] = [
                'name' => $name,
                'tier' => $tier,
                'where' => ($core !== null && $real !== false && str_starts_with(rtrim($real, '/') . '/', $core)) ? 'core' : 'site',
                'enabled' => $enabled,
                'default' => $default,
                'departure' => $enabled !== $default,
                'order' => \Zero\Core\Modules::order($name),
                'canSwitch' => $tier !== 'required' && $settable,
                'settable' => $settable,
            ];
        }
        usort($rows, fn($a, $b) => $tiers[$a['tier']] <=> $tiers[$b['tier']] ?: strcasecmp($a['name'], $b['name']));

        return $rows;
    }

    // ============================================================================
    // GROUP MANAGEMENT
    // ============================================================================

    /**
     * Create or edit a group
     * GET: Show form, POST: Handle creation/update
     */
    public function groupForm($groupId = null) {

        // Handle POST request
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $existing = $groupId ? $this->findGroup($groupId) : null;

            try {
                $data = [
                    'group_name' => $_POST['group_name'] ?? '',
                    'description' => $_POST['description'] ?? '',
                    'is_active' => isset($_POST['is_active']) ? 1 : 0,
                    'created_by' => \Zero\Core\Session::userId()
                ];

                if ($existing) {
                    // Update existing group
                    $existing->group_name = $data['group_name'];
                    $existing->description = $data['description'];
                    $existing->is_active = $data['is_active'];
                    $existing->save();
                    $this->success('Group updated successfully', ['group_id' => $groupId]);
                    header("Location: /admin/manage/groups");
                    exit;
                } else {
                    // Create new group
                    $newGroupId = \Zero\Core\Group::create($data)->group_id;
                    $this->success('Group created successfully', ['group_id' => $newGroupId]);
                    header("Location: /admin/manage/groups");
                    exit;
                }
            } catch (\Exception $e) {
                error_log("Error saving group: " . $e->getMessage());
                $duplicate = $e instanceof \PDOException && \Zero\Core\Model::isDuplicateKey($e);
                $this->error('Failed to save group', [
                    'error' => $duplicate ? 'A group with that name already exists' : $e->getMessage()
                ]);
            }
        }

        // GET request - show form
        $group = null;
        if ($groupId) {
            $group = $this->findGroup($groupId);
        }

        $this->group = $group;
        $this->build(__DIR__."/view/group-form.php");
    }

    /**
     * The group with this id.
     *
     * @param int|string $groupId From the URL or a form
     * @throws \Zero\Core\HTTPError 404 when no group has that id
     */
    private function findGroup($groupId): \Zero\Core\Group {
        $group = ctype_digit((string) $groupId) ? \Zero\Core\Group::find((int) $groupId) : null;
        if (!$group) {
            throw new \Zero\Core\HTTPError(404, 'Group not found');
        }

        return $group;
    }

    /**
     * Delete a group
     * POST /admin/delete-group/{group_id}
     */
    #[AuditLog('group_delete', includeParams: true)]
    #[AllowedMethods('POST')]
    public function deleteGroup($groupId = null) {
        if (!$groupId) {
            $this->error('Group ID required');
            header("Location: /admin/manage/groups");
            exit;
        }

        $group = $this->findGroup($groupId);

        try {
            $hardDelete = isset($_POST['hard_delete']) && $_POST['hard_delete'] == '1';

            if ($hardDelete) {
                $group->delete();
                $message = 'Group permanently deleted';
            } else {
                $group->deactivate();
                $message = 'Group deactivated';
            }

            $this->success($message, ['group_id' => $groupId]);
            header("Location: /admin/manage/groups");
            exit;
        } catch (\Exception $e) {
            error_log("Error deleting group: " . $e->getMessage());
            $this->error('Failed to delete group: ' . $e->getMessage());
            header("Location: /admin/manage/groups");
            exit;
        }
    }

    /**
     * Add a user to a group
     * POST /admin/add-group-member
     */
    #[AuditLog('group_member_add', includeParams: true)]
    #[AllowedMethods('POST')]
    #[RequiredParams(['group_id', 'user_id'])]
    public function addGroupMember() {
        $groupId = $_POST['group_id'];
        $userId = $_POST['user_id'];
        $role = $_POST['role'] ?? 'member';

        $group = $this->findGroup($groupId);

        try {
            $group->add($userId, $role, \Zero\Core\Session::userId());
            $this->success('User added to group successfully', ['group_id' => $groupId, 'user_id' => $userId]);
            header("Location: /admin/manage/group/$groupId");
            exit;
        } catch (\Exception $e) {
            error_log("Error adding user to group: " . $e->getMessage());
            $this->error('Failed to add user to group: ' . $e->getMessage());
            header("Location: /admin/manage/group/$groupId");
            exit;
        }
    }

    /**
     * Remove a user from a group
     * POST /admin/remove-group-member
     */
    #[AuditLog('group_member_remove', includeParams: true)]
    #[AllowedMethods('POST')]
    #[RequiredParams(['group_id', 'user_id'])]
    public function removeGroupMember() {
        $groupId = $_POST['group_id'];
        $userId = $_POST['user_id'];

        $group = $this->findGroup($groupId);

        try {
            $group->remove($userId);
            $this->success('User removed from group successfully', ['group_id' => $groupId, 'user_id' => $userId]);
            header("Location: /admin/manage/group/$groupId");
            exit;
        } catch (\Exception $e) {
            error_log("Error removing user from group: " . $e->getMessage());
            $this->error('Failed to remove user from group: ' . $e->getMessage());
            header("Location: /admin/manage/group/$groupId");
            exit;
        }
    }

    /**
     * Update a user's role in a group
     * POST /admin/update-group-member-role
     */
    #[AuditLog('group_member_role_update', includeParams: true)]
    #[AllowedMethods('POST')]
    #[RequiredParams(['group_id', 'user_id', 'role'])]
    public function updateGroupMemberRole() {
        $groupId = $_POST['group_id'];
        $userId = $_POST['user_id'];
        $role = $_POST['role'];

        $group = $this->findGroup($groupId);

        try {
            $group->setRole($userId, $role);
            $this->success('User role updated successfully', ['group_id' => $groupId, 'user_id' => $userId, 'role' => $role]);
            header("Location: /admin/manage/group/$groupId");
            exit;
        } catch (\Exception $e) {
            error_log("Error updating user role: " . $e->getMessage());
            $this->error('Failed to update user role: ' . $e->getMessage());
            header("Location: /admin/manage/group/$groupId");
            exit;
        }
    }

}
