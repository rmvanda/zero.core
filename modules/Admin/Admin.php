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
