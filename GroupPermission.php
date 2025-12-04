<?php
namespace Zero\Core;

/**
 * GroupPermission - Reusable group-based permission handler
 * Can be used by any module that needs group permissions
 */
class GroupPermission {

    private $db;
    private $permissionTable;
    private $entityTable;
    private $entityIdColumn;
    private $entityName;

    /**
     * @param \PDO $db Database connection
     * @param string $entityTable The main entity table (e.g., 'lists', 'notes')
     * @param string $entityIdColumn The entity ID column name (e.g., 'list_id', 'note_id')
     * @param string $permissionTable The permissions table (e.g., 'list_permissions', 'note_permissions')
     * @param string $entityName Human-readable entity name for error messages (e.g., 'list', 'note')
     */
    public function __construct($db, string $entityTable, string $entityIdColumn, string $permissionTable, string $entityName = 'item') {
        $this->db = $db;
        $this->entityTable = $entityTable;
        $this->entityIdColumn = $entityIdColumn;
        $this->permissionTable = $permissionTable;
        $this->entityName = $entityName;
    }

    /**
     * Grant permission to a group for an entity
     *
     * @param int $entityId
     * @param int $groupId
     * @param bool $canRead
     * @param bool $canUpdate
     * @param bool $canDelete
     * @return bool Success
     * @throws \Exception
     */
    public function grant(int $entityId, int $groupId, bool $canRead = true, bool $canUpdate = false, bool $canDelete = false): bool {
        try {
            Console::debug("Granting permissions for {$this->entityName} $entityId to group $groupId");

            $stmt = $this->db->prepare("
                INSERT INTO {$this->permissionTable} ({$this->entityIdColumn}, group_id, can_read, can_update, can_delete)
                VALUES (?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    can_read = VALUES(can_read),
                    can_update = VALUES(can_update),
                    can_delete = VALUES(can_delete)
            ");

            $stmt->execute([$entityId, $groupId, $canRead ? 1 : 0, $canUpdate ? 1 : 0, $canDelete ? 1 : 0]);
            Console::log("Permissions granted successfully");

            return true;
        } catch (\PDOException $e) {
            Console::error("Failed to grant permissions: " . $e->getMessage());
            throw new \Exception("Failed to grant permissions: " . $e->getMessage());
        }
    }

    /**
     * Revoke all permissions for a group from an entity
     *
     * @param int $entityId
     * @param int $groupId
     * @return bool Success
     * @throws \Exception
     */
    public function revoke(int $entityId, int $groupId): bool {
        try {
            Console::debug("Revoking permissions for {$this->entityName} $entityId from group $groupId");

            $stmt = $this->db->prepare("
                DELETE FROM {$this->permissionTable}
                WHERE {$this->entityIdColumn} = ? AND group_id = ?
            ");

            $stmt->execute([$entityId, $groupId]);
            Console::log("Permissions revoked successfully");

            return true;
        } catch (\PDOException $e) {
            Console::error("Failed to revoke permissions: " . $e->getMessage());
            throw new \Exception("Failed to revoke permissions: " . $e->getMessage());
        }
    }

    /**
     * Update permissions for a group
     *
     * @param int $entityId
     * @param int $groupId
     * @param array $permissions Array with keys: can_read, can_update, can_delete
     * @return bool Success
     * @throws \Exception
     */
    public function update(int $entityId, int $groupId, array $permissions): bool {
        try {
            Console::debug("Updating permissions for {$this->entityName} $entityId and group $groupId");

            $updateFields = [];
            $params = [];

            if (isset($permissions['can_read'])) {
                $updateFields[] = "can_read = ?";
                $params[] = $permissions['can_read'] ? 1 : 0;
            }

            if (isset($permissions['can_update'])) {
                $updateFields[] = "can_update = ?";
                $params[] = $permissions['can_update'] ? 1 : 0;
            }

            if (isset($permissions['can_delete'])) {
                $updateFields[] = "can_delete = ?";
                $params[] = $permissions['can_delete'] ? 1 : 0;
            }

            if (empty($updateFields)) {
                throw new \Exception("No permissions to update");
            }

            $params[] = $entityId;
            $params[] = $groupId;

            $stmt = $this->db->prepare("
                UPDATE {$this->permissionTable}
                SET " . implode(', ', $updateFields) . "
                WHERE {$this->entityIdColumn} = ? AND group_id = ?
            ");

            $stmt->execute($params);
            Console::log("Permissions updated successfully");

            return true;
        } catch (\PDOException $e) {
            Console::error("Failed to update permissions: " . $e->getMessage());
            throw new \Exception("Failed to update permissions: " . $e->getMessage());
        }
    }

    /**
     * Check if a user has permission to perform an action on an entity
     *
     * @param int $entityId
     * @param int $userId
     * @param string $permission 'read', 'update', or 'delete'
     * @return bool True if user has permission
     */
    public function hasPermission(int $entityId, int $userId, string $permission = 'read'): bool {
        try {
            // Owner has all permissions
            $stmt = $this->db->prepare("SELECT user_id FROM {$this->entityTable} WHERE {$this->entityIdColumn} = ?");
            $stmt->execute([$entityId]);
            $entity = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!$entity) {
                return false;
            }

            if ($entity['user_id'] == $userId) {
                return true;
            }

            // Check group permissions
            $permissionColumn = match($permission) {
                'read' => 'can_read',
                'update' => 'can_update',
                'delete' => 'can_delete',
                default => 'can_read'
            };

            $stmt = $this->db->prepare("
                SELECT COUNT(*) as count
                FROM {$this->permissionTable} p
                JOIN group_member gm ON p.group_id = gm.group_id
                WHERE p.{$this->entityIdColumn} = ? AND gm.user_id = ? AND p.$permissionColumn = 1
            ");

            $stmt->execute([$entityId, $userId]);
            $result = $stmt->fetch(\PDO::FETCH_ASSOC);

            return $result['count'] > 0;
        } catch (\PDOException $e) {
            Console::error("Failed to check permission: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get all permissions for an entity
     *
     * @param int $entityId
     * @return array Array of permissions with group details
     */
    public function getByEntity(int $entityId): array {
        try {
            $stmt = $this->db->prepare("
                SELECT
                    p.*,
                    g.group_name,
                    g.description as group_description,
                    (SELECT COUNT(*) FROM group_member WHERE group_id = g.group_id) as member_count
                FROM {$this->permissionTable} p
                JOIN `group` g ON p.group_id = g.group_id
                WHERE p.{$this->entityIdColumn} = ?
                ORDER BY g.group_name ASC
            ");

            $stmt->execute([$entityId]);
            return $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            Console::error("Failed to get permissions: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get permissions for a specific group on an entity
     *
     * @param int $entityId
     * @param int $groupId
     * @return array|null Permission data or null if not found
     */
    public function find(int $entityId, int $groupId): ?array {
        try {
            $stmt = $this->db->prepare("
                SELECT
                    p.*,
                    g.group_name
                FROM {$this->permissionTable} p
                JOIN `group` g ON p.group_id = g.group_id
                WHERE p.{$this->entityIdColumn} = ? AND p.group_id = ?
            ");

            $stmt->execute([$entityId, $groupId]);
            $result = $stmt->fetch(\PDO::FETCH_ASSOC);

            return $result ?: null;
        } catch (\PDOException $e) {
            Console::error("Failed to find permission: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Batch grant permissions to multiple groups
     *
     * @param int $entityId
     * @param array $groupPermissions Array of [group_id => [can_read, can_update, can_delete]]
     * @return array Results with 'success' and 'failed' arrays
     */
    public function batchGrant(int $entityId, array $groupPermissions): array {
        $results = [
            'success' => [],
            'failed' => []
        ];

        foreach ($groupPermissions as $groupId => $perms) {
            try {
                $this->grant(
                    $entityId,
                    $groupId,
                    $perms['can_read'] ?? true,
                    $perms['can_update'] ?? false,
                    $perms['can_delete'] ?? false
                );
                $results['success'][] = $groupId;
            } catch (\Exception $e) {
                $results['failed'][] = [
                    'group_id' => $groupId,
                    'error' => $e->getMessage()
                ];
            }
        }

        Console::log("Batch grant completed: " . count($results['success']) . " succeeded, " . count($results['failed']) . " failed");

        return $results;
    }

    /**
     * Get all groups that DON'T have permission to an entity
     * (useful for showing available groups to grant access to)
     *
     * @param int $entityId
     * @param int $userId User ID to filter groups by membership
     * @return array Array of groups
     */
    public function getAvailableGroups(int $entityId, int $userId): array {
        try {
            $stmt = $this->db->prepare("
                SELECT DISTINCT
                    g.group_id,
                    g.group_name,
                    g.description,
                    (SELECT COUNT(*) FROM group_member WHERE group_id = g.group_id) as member_count
                FROM `group` g
                JOIN group_member gm ON g.group_id = gm.group_id
                WHERE gm.user_id = ?
                    AND g.is_active = 1
                    AND g.group_id NOT IN (
                        SELECT group_id
                        FROM {$this->permissionTable}
                        WHERE {$this->entityIdColumn} = ?
                    )
                ORDER BY g.group_name ASC
            ");

            $stmt->execute([$userId, $entityId]);
            return $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            Console::error("Failed to get available groups: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get all users who have access to an entity through group permissions
     *
     * @param int $entityId
     * @return array Array of users with their access levels
     */
    public function getSharedUsers(int $entityId): array {
        try {
            $stmt = $this->db->prepare("
                SELECT DISTINCT
                    u.id as user_id,
                    u.name,
                    u.email,
                    g.group_name,
                    p.can_read,
                    p.can_update,
                    p.can_delete
                FROM {$this->permissionTable} p
                JOIN `group` g ON p.group_id = g.group_id
                JOIN group_member gm ON g.group_id = gm.group_id
                JOIN user_view u ON gm.user_id = u.id
                WHERE p.{$this->entityIdColumn} = ?
                ORDER BY u.name ASC
            ");

            $stmt->execute([$entityId]);
            return $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            Console::error("Failed to get shared users: " . $e->getMessage());
            return [];
        }
    }
}
