<?php
namespace Zero\Core\Access;
use Zero\Core\Database;
use Zero\Core\Console;
use Zero\Core\Session;

/**
 * Who may read, update or delete a module's things: the `permission` table
 * (schema in core/sql/migrations/permission.sql). This is the only class that reads or writes
 * that table; a module never keeps a permission table of its own.
 *
 * One object answers for one permission key, "{module}.{entity}":
 *
 *     $perm = new Permission('lists', 'list');          // key "lists.list"
 *     $perm->grant($listId, $group, true, true);        // group may read + update
 *     $perm->grant($listId, $user);                     // one user may read
 *     $perm->can($listId, $userId, 'update');
 *
 * Rules:
 *
 * - A grant goes to a Holder: a Group or a User.
 * - A user grants to a group only if they belong to it, or are an admin.
 * - A user holds a permission if their own row or the row of any ACTIVE group
 *   they belong to grants it. Rows only grant; nothing denies.
 * - entity_id 0 means every item under the key, so a row on 0 grants its
 *   rights on all of them. Nothing writes such a row unless a module asks.
 * - Ownership is the module's business. This class never reads a module's
 *   tables, so a module checks "is the owner, OR can()". An owner needs no row.
 * - Nothing cascades. A module that deletes an item calls forget().
 *
 * A capability ("may this user use this feature at all?", allow.polly) is a
 * row on entity_id 0 with can_read set, under the capability's own key. See
 * capability() and capabilities(); a holder's can(), grant() and revoke()
 * (User, Group) are the usual way in.
 */
class Permission {

    public const ACTIONS = ['read' => 'can_read', 'update' => 'can_update', 'delete' => 'can_delete'];

    private \PDO $db;
    private string $key;

    /**
     * @param string $module The module's name as it appears in its URL: lowercase,
     *                       words joined by hyphens (lists, tucker-tapper)
     * @param string $entity What the module is granting access to (list, board)
     * @param \PDO|null $db A connection to use instead of the framework's.
     *                      Only code running outside the framework needs one.
     * @throws \InvalidArgumentException
     */
    public function __construct(string $module, string $entity, ?\PDO $db = null) {
        $this->db = $db ?? Database::getConnection();
        $this->key = self::key($module, $entity);
    }

    /**
     * The permission key for a module's entity: "{module}.{entity}".
     *
     * Every key a module owns starts with its name and a dot, so all of one
     * module's rows are `permission_key LIKE 'lists.%'`. "allow" is refused as
     * a module name because allow.* keys are site-wide capabilities.
     *
     * @throws \InvalidArgumentException
     */
    public static function key(string $module, string $entity): string {
        if (!preg_match('/^[a-z][a-z0-9]*(-[a-z0-9]+)*$/', $module) || $module === 'allow') {
            throw new \InvalidArgumentException("Invalid permission module name '{$module}'");
        }
        if (!preg_match('/^[a-z][a-z0-9_-]*$/', $entity)) {
            throw new \InvalidArgumentException("Invalid permission entity name '{$entity}'");
        }
        return $module . '.' . $entity;
    }

    /* ── Capabilities ── */

    /**
     * The object for one capability key (allow.polly). A capability is held
     * on entity 0, so grant, revoke and check it there:
     *
     *     $cap = Permission::capability('allow.polly');
     *     $cap->grant(0, $holder);          // a User or a Group
     *     $cap->revoke(0, $holder);
     *     $cap->canAll($userId);
     *
     * $user->grant('allow.polly') and $group->grant('allow.polly') do the
     * same and are the usual way in.
     *
     * @throws \InvalidArgumentException
     */
    public static function capability(string $key, ?\PDO $db = null): self {
        $permission = (new \ReflectionClass(self::class))->newInstanceWithoutConstructor();
        $permission->db = $db ?? Database::getConnection();
        $permission->key = self::capabilityKey($key);

        return $permission;
    }

    /**
     * Validate a capability key: "allow." and then lowercase words joined by
     * dots (allow.polly, allow.receipt.view). No module's key can start with
     * "allow.", so a capability is never mistaken for a module's permission.
     *
     * @throws \InvalidArgumentException
     */
    public static function capabilityKey(string $key): string {
        if (strlen($key) > 100 || !preg_match('/^allow(\.[a-z0-9][a-z0-9_-]*)+$/', $key)) {
            throw new \InvalidArgumentException("Invalid capability key '{$key}'");
        }
        return $key;
    }

    /**
     * Every allow.* key the holder has on entity 0 with can_read set: its
     * capabilities. A user has their own and those of every active group
     * they belong to; a group has its own. Lets a database error through, so
     * the caller decides what a failed lookup means (can() denies).
     *
     * @param Holder $holder
     * @param bool $direct True for the holder's own rows only: for a user,
     *                     leaving out what their groups grant
     * @return string[] Sorted keys
     * @throws \PDOException
     */
    public static function capabilities(Holder $holder, bool $direct = false, ?\PDO $db = null): array {
        $column = self::holderColumn($holder);
        $viaGroups = !$direct && $column === 'user_id';
        $subject = $viaGroups
            ? "(user_id = ? OR group_id IN (" . Group::activeIdsSql() . "))"
            : "$column = ?";

        $stmt = ($db ?? Database::getConnection())->prepare("
            SELECT DISTINCT permission_key FROM permission
            WHERE permission_key LIKE 'allow.%' AND entity_id = 0 AND can_read = 1 AND $subject
            ORDER BY permission_key
        ");
        $stmt->execute($viaGroups ? [$holder->holderId(), $holder->holderId()] : [$holder->holderId()]);

        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }

    /**
     * Every allow.* key anyone holds on entity 0: the capabilities in use on
     * this site. For a screen that lists what can be granted.
     *
     * @return string[] Sorted keys
     * @throws \PDOException
     */
    public static function capabilityKeysInUse(?\PDO $db = null): array {
        return ($db ?? Database::getConnection())->query("
            SELECT DISTINCT permission_key FROM permission
            WHERE permission_key LIKE 'allow.%' AND entity_id = 0
            ORDER BY permission_key
        ")->fetchAll(\PDO::FETCH_COLUMN);
    }

    /**
     * How many holders each capability has: the users granted it directly and
     * the active groups granted it. A user who has it only through a group is
     * counted in neither. For a screen that lists the capabilities.
     *
     * @return array<string,array{users:int,groups:int}> Keyed by capability key
     * @throws \PDOException
     */
    public static function capabilityHolderCounts(?\PDO $db = null): array {
        $rows = ($db ?? Database::getConnection())->query("
            SELECT p.permission_key,
                   SUM(p.user_id <> 0) AS users,
                   SUM(g.group_id IS NOT NULL) AS groups_holding
            FROM permission p
            LEFT JOIN user_group g ON g.group_id = p.group_id AND g.is_active = 1
            WHERE p.permission_key LIKE 'allow.%' AND p.entity_id = 0 AND p.can_read = 1
            GROUP BY p.permission_key
        ")->fetchAll(\PDO::FETCH_ASSOC);

        $counts = [];
        foreach ($rows as $row) {
            $counts[$row['permission_key']] = ['users' => (int) $row['users'], 'groups' => (int) $row['groups_holding']];
        }

        return $counts;
    }

    /** This object's permission key. */
    public function permissionKey(): string {
        return $this->key;
    }

    /* ── Checking ── */

    /**
     * Does the user hold this permission on the item, through their own row or
     * an active group's? Says nothing about ownership.
     *
     * @param int $entityId
     * @param int|User $user
     * @param string $action 'read', 'update' or 'delete'
     * @return bool False on a database error
     * @throws \InvalidArgumentException For an unknown action
     */
    public function can(int $entityId, int|User $user, string $action = 'read'): bool {
        [$sql, $params] = $this->sqlCan('?', $user, $action);
        try {
            // The item id is the fragment's second placeholder (after the key).
            array_splice($params, 1, 0, [$entityId]);
            $stmt = $this->db->prepare("SELECT $sql");
            $stmt->execute($params);

            return (bool) $stmt->fetchColumn();
        } catch (\PDOException $e) {
            Console::error("Failed to check permission: " . $e->getMessage());
            return false;
        }
    }

    /**
     * A SQL condition that is true for the rows of a module's query the user
     * holds this permission on. For listings ("every list I can read"):
     *
     *     [$canRead, $params] = $perm->sqlCan('l.list_id', $userId);
     *     $stmt = $db->prepare("SELECT l.* FROM lists l WHERE l.user_id = ? OR $canRead");
     *     $stmt->execute([$userId, ...$params]);
     *
     * The fragment uses positional `?` placeholders, so the query it goes into
     * must too. Splice $params in at the fragment's position.
     *
     * @param string $idColumn The column holding the item's id, as the outer
     *                         query names it (l.list_id). Never user input.
     * @param int|User $user
     * @param string $action 'read', 'update' or 'delete'
     * @return array{0: string, 1: array} The condition and its parameters
     * @throws \InvalidArgumentException For an unknown action or a column
     *                                   name that is not a plain identifier
     */
    public function sqlCan(string $idColumn, int|User $user, string $action = 'read'): array {
        $userId = self::userId($user);
        $flag = self::ACTIONS[$action] ?? null;
        if ($flag === null) {
            throw new \InvalidArgumentException("Unknown permission action '{$action}'");
        }
        if ($idColumn !== '?' && !preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)?$/', $idColumn)) {
            throw new \InvalidArgumentException("Invalid id column '{$idColumn}'");
        }

        $sql = "EXISTS (SELECT 1 FROM permission zp"
             . " WHERE zp.permission_key = ? AND zp.entity_id IN ($idColumn, 0) AND zp.$flag = 1"
             . " AND (zp.user_id = ? OR zp.group_id IN (" . Group::activeIdsSql() . ")))";

        return [$sql, [$this->key, $userId, $userId]];
    }

    /**
     * The ids of the items the user holds this permission on. Does not include
     * items the user owns, and does not expand a grant on every item (use
     * canAll() for that).
     *
     * @param int|User $user
     * @param string $action 'read', 'update' or 'delete'
     * @return int[]
     * @throws \InvalidArgumentException For an unknown action
     */
    public function ids(int|User $user, string $action = 'read'): array {
        $userId = self::userId($user);
        $flag = self::ACTIONS[$action] ?? null;
        if ($flag === null) {
            throw new \InvalidArgumentException("Unknown permission action '{$action}'");
        }
        try {
            $stmt = $this->db->prepare("
                SELECT DISTINCT zp.entity_id
                FROM permission zp
                WHERE zp.permission_key = ? AND zp.entity_id <> 0 AND zp.$flag = 1
                    AND (zp.user_id = ? OR zp.group_id IN (" . Group::activeIdsSql() . "))
                ORDER BY zp.entity_id
            ");
            $stmt->execute([$this->key, $userId, $userId]);

            return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
        } catch (\PDOException $e) {
            Console::error("Failed to list permitted ids: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Does the user hold this permission on every item under the key (a row
     * on entity 0)?
     *
     * @throws \InvalidArgumentException For an unknown action
     */
    public function canAll(int|User $user, string $action = 'read'): bool {
        $userId = self::userId($user);
        $flag = self::ACTIONS[$action] ?? null;
        if ($flag === null) {
            throw new \InvalidArgumentException("Unknown permission action '{$action}'");
        }
        try {
            $stmt = $this->db->prepare("
                SELECT 1 FROM permission zp
                WHERE zp.permission_key = ? AND zp.entity_id = 0 AND zp.$flag = 1
                    AND (zp.user_id = ? OR zp.group_id IN (" . Group::activeIdsSql() . "))
                LIMIT 1
            ");
            $stmt->execute([$this->key, $userId, $userId]);

            return (bool) $stmt->fetchColumn();
        } catch (\PDOException $e) {
            Console::error("Failed to check permission: " . $e->getMessage());
            return false;
        }
    }

    /* ── Granting ── */

    /**
     * Give a holder (a Group or a User) rights on an item, replacing any
     * rights its own row had. All three flags false removes the row.
     *
     * @param int $entityId
     * @param Holder $holder
     * @param bool $canRead
     * @param bool $canUpdate
     * @param bool $canDelete
     * @param int|null $by Who is granting; defaults to the logged-in user
     * @return bool Success
     * @throws \DomainException When the holder is a group the granter may not
     *                          share with (see Group::usableBy())
     * @throws \Exception
     */
    public function grant(int $entityId, Holder $holder, bool $canRead = true, bool $canUpdate = false, bool $canDelete = false, ?int $by = null): bool {
        return $this->write($entityId, $holder, $canRead, $canUpdate, $canDelete, $by);
    }

    /**
     * Give several groups rights on an item.
     *
     * @param int $entityId
     * @param array $groupPermissions [group_id => [can_read, can_update, can_delete]]
     * @param int|null $by Who is granting; defaults to the logged-in user
     * @return array ['success' => int[], 'failed' => [['group_id' => .., 'error' => ..]]]
     */
    public function batchGrant(int $entityId, array $groupPermissions, ?int $by = null): array {
        $results = [
            'success' => [],
            'failed' => []
        ];

        foreach ($groupPermissions as $groupId => $perms) {
            try {
                $group = Group::find((int) $groupId);
                if ($group === null) {
                    throw new \Exception("Group not found");
                }
                $this->grant(
                    $entityId,
                    $group,
                    (bool) ($perms['can_read'] ?? true),
                    (bool) ($perms['can_update'] ?? false),
                    (bool) ($perms['can_delete'] ?? false),
                    $by
                );
                $results['success'][] = $groupId;
            } catch (\Exception $e) {
                $results['failed'][] = [
                    'group_id' => $groupId,
                    'error' => $e->getMessage()
                ];
            }
        }

        return $results;
    }

    /**
     * Take away everything a holder's own row granted on an item. Rights a
     * user has through a group are untouched.
     *
     * @throws \Exception
     */
    public function revoke(int $entityId, Holder $holder): bool {
        return $this->erase($entityId, $holder);
    }

    /**
     * Remove every grant on an item. Call it when the item is deleted: no
     * foreign key does it for you.
     *
     * @throws \Exception
     */
    public function forget(int $entityId): bool {
        try {
            $stmt = $this->db->prepare("DELETE FROM permission WHERE permission_key = ? AND entity_id = ?");
            $stmt->execute([$this->key, $entityId]);

            return true;
        } catch (\PDOException $e) {
            Console::error("Failed to forget permissions: " . $e->getMessage());
            throw new \Exception("Failed to forget permissions: " . $e->getMessage());
        }
    }

    /**
     * Remove every grant made to a holder, under every key. User::delete()
     * and Group::delete() call this; nothing else should need to.
     *
     * @throws \InvalidArgumentException For a holder with no usable id
     * @throws \PDOException
     */
    public static function forgetHolder(Holder $holder, ?\PDO $db = null): void {
        $column = self::holderColumn($holder);
        // 0 marks "not this kind of holder" on every row of the other kind.
        if ($holder->holderId() <= 0) {
            throw new \InvalidArgumentException("Invalid {$column} {$holder->holderId()}");
        }
        ($db ?? Database::getConnection())
            ->prepare("DELETE FROM permission WHERE $column = ?")
            ->execute([$holder->holderId()]);
    }

    /* ── The sharing form ── */

    /**
     * The active groups granted rights on an item, with what each may do.
     *
     * @param int $entityId
     * @return array Rows of entity_id, group_id, can_read, can_update,
     *               can_delete, granted_by, granted_on, group_name,
     *               group_description, member_count
     */
    public function groups(int $entityId): array {
        try {
            $stmt = $this->db->prepare("
                SELECT
                    zp.entity_id, zp.group_id, zp.can_read, zp.can_update, zp.can_delete,
                    zp.granted_by, zp.granted_on,
                    g.group_name,
                    g.description as group_description,
                    (SELECT COUNT(*) FROM user_group_member WHERE group_id = g.group_id) as member_count
                FROM permission zp
                JOIN user_group g ON zp.group_id = g.group_id
                WHERE zp.permission_key = ? AND zp.entity_id = ? AND g.is_active = 1
                ORDER BY g.group_name ASC
            ");
            $stmt->execute([$this->key, $entityId]);

            return $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            Console::error("Failed to get permissions: " . $e->getMessage());
            return [];
        }
    }

    /**
     * What one group was granted on an item.
     *
     * @return array|null entity_id, group_id, the three flags and group_name
     */
    public function find(int $entityId, int|Group $group): ?array {
        $groupId = $group instanceof Group ? $group->holderId() : $group;
        try {
            $stmt = $this->db->prepare("
                SELECT
                    zp.entity_id, zp.group_id, zp.can_read, zp.can_update, zp.can_delete,
                    zp.granted_by, zp.granted_on,
                    g.group_name
                FROM permission zp
                JOIN user_group g ON zp.group_id = g.group_id
                WHERE zp.permission_key = ? AND zp.entity_id = ? AND zp.group_id = ?
            ");
            $stmt->execute([$this->key, $entityId, $groupId]);
            $result = $stmt->fetch(\PDO::FETCH_ASSOC);

            return $result ?: null;
        } catch (\PDOException $e) {
            Console::error("Failed to find permission: " . $e->getMessage());
            return null;
        }
    }

    /**
     * The user's active groups that have no grant on the item yet: the ones
     * a sharing form offers to add.
     *
     * @param int $entityId
     * @param int|User $user
     * @return array Rows of group_id, group_name, description, member_count
     */
    public function availableGroups(int $entityId, int|User $user): array {
        $userId = self::userId($user);
        try {
            $stmt = $this->db->prepare("
                SELECT
                    g.group_id,
                    g.group_name,
                    g.description,
                    (SELECT COUNT(*) FROM user_group_member WHERE group_id = g.group_id) as member_count
                FROM user_group g
                WHERE g.group_id IN (" . Group::activeIdsSql() . ")
                    AND g.group_id NOT IN (
                        SELECT zp.group_id
                        FROM permission zp
                        WHERE zp.permission_key = ? AND zp.entity_id = ?
                    )
                ORDER BY g.group_name ASC
            ");
            $stmt->execute([$userId, $this->key, $entityId]);

            return $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            Console::error("Failed to get available groups: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Everyone with rights on an item: members of the active groups granted,
     * and users granted directly. A user in two such groups appears twice,
     * once per group. A directly granted user has group_name null.
     *
     * @param int $entityId
     * @return array Rows of user_id, name, email, group_name, can_read,
     *               can_update, can_delete
     */
    public function users(int $entityId): array {
        try {
            $stmt = $this->db->prepare("
                SELECT u.id as user_id, u.name, u.email, g.group_name,
                       zp.can_read, zp.can_update, zp.can_delete
                FROM permission zp
                JOIN user_group g ON zp.group_id = g.group_id AND g.is_active = 1
                JOIN user_group_member gm ON g.group_id = gm.group_id
                JOIN users u ON gm.user_id = u.id
                WHERE zp.permission_key = ? AND zp.entity_id = ?
                UNION ALL
                SELECT u.id as user_id, u.name, u.email, NULL as group_name,
                       zp.can_read, zp.can_update, zp.can_delete
                FROM permission zp
                JOIN users u ON zp.user_id = u.id
                WHERE zp.permission_key = ? AND zp.entity_id = ?
                ORDER BY name ASC
            ");
            $stmt->execute([$this->key, $entityId, $this->key, $entityId]);

            return $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            Console::error("Failed to get shared users: " . $e->getMessage());
            return [];
        }
    }

    /* ── internals ── */

    /**
     * Insert or replace one holder's row. All three flags false means "no
     * rights", which is not stored: the row is removed instead.
     */
    private function write(int $entityId, Holder $holder, bool $canRead, bool $canUpdate, bool $canDelete, ?int $by): bool {
        if (!$canRead && !$canUpdate && !$canDelete) {
            return $this->erase($entityId, $holder);
        }

        [$userId, $groupId] = self::holderIds($holder);
        $by = $by ?? Session::userId();

        // A user grants only to a group they belong to, unless they are an
        // admin. With nobody granting (no $by and no session) the caller is
        // the system itself: a migration, a script, a test fixture.
        if ($holder instanceof Group && $by !== null && !$holder->usableBy($by)) {
            throw new \DomainException("You can only share with a group you belong to");
        }

        try {
            // Each placeholder is bound once: native prepares reject a reused name.
            $stmt = $this->db->prepare("
                INSERT INTO permission
                    (permission_key, entity_id, user_id, group_id, can_read, can_update, can_delete, granted_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    can_read = VALUES(can_read),
                    can_update = VALUES(can_update),
                    can_delete = VALUES(can_delete),
                    updated_by = ?,
                    updated_on = NOW()
            ");
            $stmt->execute([
                $this->key, $entityId, $userId, $groupId,
                $canRead ? 1 : 0, $canUpdate ? 1 : 0, $canDelete ? 1 : 0,
                $by, $by,
            ]);

            return true;
        } catch (\PDOException $e) {
            Console::error("Failed to grant permissions: " . $e->getMessage());
            throw new \Exception("Failed to grant permissions: " . $e->getMessage());
        }
    }

    private function erase(int $entityId, Holder $holder): bool {
        [$userId, $groupId] = self::holderIds($holder);

        try {
            $stmt = $this->db->prepare("
                DELETE FROM permission
                WHERE permission_key = ? AND entity_id = ? AND user_id = ? AND group_id = ?
            ");
            $stmt->execute([$this->key, $entityId, $userId, $groupId]);

            return true;
        } catch (\PDOException $e) {
            Console::error("Failed to revoke permissions: " . $e->getMessage());
            throw new \Exception("Failed to revoke permissions: " . $e->getMessage());
        }
    }

    /** The holder's column, checked: it is spliced into SQL. */
    private static function holderColumn(Holder $holder): string {
        $column = $holder->holderColumn();
        if ($column !== 'user_id' && $column !== 'group_id') {
            throw new \InvalidArgumentException("Unknown holder column '{$column}'");
        }
        return $column;
    }

    /**
     * [user_id, group_id] for a holder's row: its id in its own column and 0
     * in the other.
     *
     * @throws \InvalidArgumentException For a holder with no usable id
     */
    private static function holderIds(Holder $holder): array {
        $id = $holder->holderId();
        if ($id <= 0) {
            throw new \InvalidArgumentException("A permission needs a holder with an id");
        }
        return self::holderColumn($holder) === 'user_id' ? [$id, 0] : [0, $id];
    }

    private static function userId(int|User $user): int {
        return $user instanceof User ? $user->holderId() : $user;
    }
}
