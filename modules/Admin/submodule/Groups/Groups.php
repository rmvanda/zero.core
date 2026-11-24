<?php

namespace Zero\Core\Module\Admin;

use \Zero\Core\AdminResponse;
use \Zero\Core\Console;
use \Zero\Core\Model;
use \Zero\Core\Session;
use \Zero\Core\Access\Group;
use \Zero\Core\Access\User;
use \Zero\Core\Attribute\RequirePermission;
use \Zero\Core\Attribute\{AllowedMethods, RequiredParams, AuditLog};
use \Zero\Core\Exception\HTTPError;

/**
 * Admin's groups screens: the list of groups, one group's members, and the
 * form that creates or edits a group.
 *
 * Every read and write goes through Zero\Core\Access\Group. An action that
 * did not work throws an HTTPError; one that worked ends with done().
 */
#[RequirePermission('allow.admin')]
class Groups extends AdminResponse {

    /** The roles a member can have in a group. */
    private const ROLES = ['member', 'admin', 'owner'];

    // ============================================================================
    // GROUP MANAGEMENT
    // ============================================================================

    /**
     * The list of groups
     * GET /admin/groups
     */
    public function index() {
        // List all groups
        $this->groups = Group::all();

        // Pre-fetch members for each group
        $this->groupMembers = [];
        foreach ($this->groups as $group) {
            $this->groupMembers[$group->group_id] = $group->members();
        }

        $this->build(__DIR__."/view/index.php");
    }

    /**
     * One group's members
     * GET /admin/groups/members/{group_id}
     */
    public function members($id) {
        // Manage specific group members
        $group = $this->findGroup($id);
        $createdBy = $group->created_by ? User::find($group->created_by) : null;

        $this->group = $group;
        $this->members = $group->members();
        $this->nonMembers = $group->nonMembers();
        $this->createdByName = $createdBy?->name ?? 'System';

        $this->addBreadcrumb((string) $group->group_name);
        $this->build(__DIR__."/view/members.php");
    }

    /**
     * The form that creates a group, or edits the one with this id
     * GET /admin/groups/form
     * GET /admin/groups/form/{group_id}
     */
    public function form($id = null) {
        $group = null;
        if ($id !== null) {
            $group = $this->findGroup($id);
        }

        $this->group = $group;
        $this->addBreadcrumb($group ? (string) $group->group_name : 'New group');
        $this->build(__DIR__."/view/form.php");
    }

    /**
     * Create a group, or update the one with this id
     * POST /admin/groups/save
     * POST /admin/groups/save/{group_id}
     *
     * `group_name` is required and unique, `description` is optional, and the
     * group is active when `is_active` is sent at all (it is a checkbox).
     */
    #[AuditLog('group_save', includeParams: true)]
    #[AllowedMethods('POST')]
    public function save($id = null) {
        $existing = $id !== null ? $this->findGroup($id) : null;

        $name = $_POST['group_name'] ?? '';
        $description = $_POST['description'] ?? '';
        if (!is_string($name) || !is_string($description)) {
            throw new HTTPError(400, 'group_name and description must be text');
        }
        $name = trim($name);
        if ($name === '') {
            throw new HTTPError(400, 'A group name is required');
        }
        if (mb_strlen($name) > 100) {
            throw new HTTPError(400, 'A group name is at most 100 characters');
        }

        $data = [
            'group_name' => $name,
            'description' => $description,
            'is_active' => isset($_POST['is_active']) ? 1 : 0,
            'created_by' => Session::userId()
        ];

        try {
            if ($existing) {
                // Update existing group
                $existing->group_name = $data['group_name'];
                $existing->description = $data['description'];
                $existing->is_active = $data['is_active'];
                $existing->save();
                $group = $existing;
                $message = 'Group updated successfully';
            } else {
                // Create new group
                $group = Group::create($data);
                $message = 'Group created successfully';
            }
        } catch (\PDOException $e) {
            if (Model::isDuplicateKey($e)) {
                throw new HTTPError(409, 'A group with that name already exists');
            }
            Console::error("Error saving group: " . $e->getMessage());
            throw new HTTPError(500, 'The group could not be saved: ' . $e->getMessage());
        }

        $this->done($message, '/admin/groups', ['group_id' => (int) $group->group_id]);
    }

    /**
     * The group with this id.
     *
     * @param int|string $groupId From the URL or a form
     * @throws \Zero\Core\Exception\HTTPError 404 when no group has that id
     */
    private function findGroup($groupId): Group {
        $group = is_scalar($groupId) && ctype_digit((string) $groupId) ? Group::find((int) $groupId) : null;
        if (!$group) {
            throw new HTTPError(404, 'Group not found');
        }

        return $group;
    }

    /**
     * The id of the user a form names, once it is known to be a user.
     *
     * @param mixed $userId From a form
     * @throws \Zero\Core\Exception\HTTPError 400 when it is not a number, 404 when no user has that id
     */
    private function findUserId($userId): int {
        if (!is_scalar($userId) || !ctype_digit((string) $userId)) {
            throw new HTTPError(400, 'user_id must be a number');
        }
        if (!User::find((int) $userId)) {
            throw new HTTPError(404, 'User not found');
        }

        return (int) $userId;
    }

    /**
     * A role a form names, once it is known to be one a member can have.
     *
     * @param mixed $role From a form
     * @throws \Zero\Core\Exception\HTTPError 400 when it is not member, admin or owner
     */
    private function checkRole($role): string {
        if (!is_string($role) || !in_array($role, self::ROLES, true)) {
            throw new HTTPError(400, 'role must be member, admin or owner');
        }

        return $role;
    }

    /**
     * Delete a group
     * POST /admin/groups/delete/{group_id}
     *
     * Deactivates the group; with hard_delete=1 it is deleted for good, with
     * its memberships and everything granted to it.
     */
    #[AuditLog('group_delete', includeParams: true)]
    #[AllowedMethods('POST')]
    public function delete($id) {
        $group = $this->findGroup($id);
        $groupId = (int) $group->group_id;

        $hardDelete = isset($_POST['hard_delete']) && $_POST['hard_delete'] == '1';

        try {
            if ($hardDelete) {
                $group->delete();
                $message = 'Group permanently deleted';
            } else {
                $group->deactivate();
                $message = 'Group deactivated';
            }
        } catch (\PDOException $e) {
            Console::error("Error deleting group: " . $e->getMessage());
            throw new HTTPError(500, 'The group could not be ' . ($hardDelete ? 'deleted' : 'deactivated') . ': ' . $e->getMessage());
        }

        $this->done($message, '/admin/groups', ['group_id' => $groupId]);
    }

    /**
     * Add a user to a group
     * POST /admin/groups/add-member
     */
    #[AuditLog('group_member_add', includeParams: true)]
    #[AllowedMethods('POST')]
    #[RequiredParams(['group_id', 'user_id'])]
    public function addMember() {
        $group = $this->findGroup($_POST['group_id']);
        $groupId = (int) $group->group_id;
        $userId = $this->findUserId($_POST['user_id']);
        $role = $this->checkRole($_POST['role'] ?? 'member');

        try {
            $group->add($userId, $role, Session::userId());
        } catch (\DomainException $e) {
            // Already a member
            throw new HTTPError(409, $e->getMessage());
        } catch (\PDOException $e) {
            Console::error("Error adding user to group: " . $e->getMessage());
            throw new HTTPError(500, 'The user could not be added to the group: ' . $e->getMessage());
        }

        $this->done('User added to group successfully', "/admin/groups/members/{$groupId}", ['group_id' => $groupId, 'user_id' => $userId, 'role' => $role]);
    }

    /**
     * Remove a user from a group
     * POST /admin/groups/remove-member
     */
    #[AuditLog('group_member_remove', includeParams: true)]
    #[AllowedMethods('POST')]
    #[RequiredParams(['group_id', 'user_id'])]
    public function removeMember() {
        $group = $this->findGroup($_POST['group_id']);
        $groupId = (int) $group->group_id;

        // Not findUserId(): a membership whose user is gone can still be removed.
        $userId = $_POST['user_id'];
        if (!is_scalar($userId) || !ctype_digit((string) $userId)) {
            throw new HTTPError(400, 'user_id must be a number');
        }
        $userId = (int) $userId;

        try {
            $group->remove($userId);
        } catch (\DomainException $e) {
            // Not a member
            throw new HTTPError(404, $e->getMessage());
        } catch (\PDOException $e) {
            Console::error("Error removing user from group: " . $e->getMessage());
            throw new HTTPError(500, 'The user could not be removed from the group: ' . $e->getMessage());
        }

        $this->done('User removed from group successfully', "/admin/groups/members/{$groupId}", ['group_id' => $groupId, 'user_id' => $userId]);
    }

    /**
     * Update a user's role in a group
     * POST /admin/groups/set-member-role
     */
    #[AuditLog('group_member_role_update', includeParams: true)]
    #[AllowedMethods('POST')]
    #[RequiredParams(['group_id', 'user_id', 'role'])]
    public function setMemberRole() {
        $group = $this->findGroup($_POST['group_id']);
        $groupId = (int) $group->group_id;
        $userId = $this->findUserId($_POST['user_id']);
        $role = $this->checkRole($_POST['role']);

        try {
            $group->setRole($userId, $role);
        } catch (\DomainException $e) {
            // Not a member
            throw new HTTPError(404, $e->getMessage());
        } catch (\PDOException $e) {
            Console::error("Error updating user role: " . $e->getMessage());
            throw new HTTPError(500, 'The role could not be changed: ' . $e->getMessage());
        }

        $this->done('User role updated successfully', "/admin/groups/members/{$groupId}", ['group_id' => $groupId, 'user_id' => $userId, 'role' => $role]);
    }

}
