<?php
namespace Zero\Core\Access;
use Zero\Core\Model;
use Zero\Core\Session;
use Zero\Core\Database;

/**
 * A user group: the row gateway for `user_group`, and the group's members in
 * `user_group_member` (schema in core/sql/migrations/group.sql).
 *
 * This is the only class that reads or writes those tables. Modules that
 * share by group ask it for a user's groups; the Admin module's group screens
 * edit groups through it.
 *
 *     $group = Group::create(['group_name' => 'Campaign 12']);
 *     $group->add($userId, 'owner');
 *     $group->has($userId);
 *     Group::forUser($userId);          // or $user->groups()
 *
 * A group that is not active grants nothing: forUser(), idsForUser() and
 * has() all leave inactive groups out. find(), all() and members() still
 * return them, so the Admin screens can show and reactivate them.
 *
 * A group is a Holder: permissions are granted to it (see
 * Zero\Core\Access\Permission), and every active member holds what it holds.
 */
class Group extends Model implements Holder
{
    use HoldsCapabilities;

    protected static string $table = 'user_group';
    protected static string $pk = 'group_id';

    /** group_id/created_at/updated_at are owned by the DDL and deliberately absent. */
    protected static array $columns = ['group_name', 'description', 'is_active', 'created_by'];

    /* ── Holder ── */

    public function holderColumn(): string
    {
        return 'group_id';
    }

    public function holderId(): int
    {
        return (int) $this->group_id;
    }

    /* ── Groups ── */

    /**
     * Create a group. created_by defaults to the logged-in user.
     *
     * @param array $data group_name, and optionally description, is_active, created_by
     * @throws \PDOException A duplicate group_name is one Model::isDuplicateKey() recognises
     */
    public static function create(array $data): static
    {
        $data['created_by'] ??= Session::userId();
        $data['is_active'] ??= 1;

        return parent::create($data);
    }

    /**
     * Every group, active or not unless asked.
     *
     * @return static[] Each also carries member_count and created_by_username
     */
    public static function all(bool $activeOnly = false): array
    {
        $stmt = Database::getConnection()->prepare("
            SELECT
                g.*,
                (SELECT COUNT(*) FROM user_group_member WHERE group_id = g.group_id) as member_count,
                u.name as created_by_username
            FROM user_group g
            LEFT JOIN users u ON g.created_by = u.id
            " . ($activeOnly ? "WHERE g.is_active = 1" : "") . "
            ORDER BY g.group_name ASC
        ");
        $stmt->execute();

        return self::hydrate($stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /** Stop the group granting anything. Its row, members and grants stay. */
    public function deactivate(): void
    {
        $this->is_active = 0;
        $this->save();
    }

    /** Let a deactivated group grant again. */
    public function activate(): void
    {
        $this->is_active = 1;
        $this->save();
    }

    /**
     * Delete the group, its memberships and every permission granted to it.
     * No foreign key is relied on for any of this.
     */
    public function delete(): void
    {
        if (!$this->exists || $this->holderId() <= 0) {
            return;
        }
        Database::getConnection()
            ->prepare("DELETE FROM user_group_member WHERE group_id = ?")
            ->execute([$this->group_id]);
        Permission::forgetHolder($this);

        parent::delete();
    }

    /* ── A user's groups ── */

    /**
     * A subquery for the ids of the active groups a user belongs to, with one
     * `?` placeholder for the user id.
     *
     * For code that has to stay in SQL (Zero\Core\Access\Permission's checks and
     * listings). It is the one definition of "the groups this user is in":
     * use it as `x.group_id IN (` . Group::activeIdsSql() . `)` instead of
     * joining user_group_member.
     */
    public static function activeIdsSql(): string
    {
        return "SELECT gm.group_id FROM user_group_member gm"
             . " JOIN user_group g ON g.group_id = gm.group_id"
             . " WHERE gm.user_id = ? AND g.is_active = 1";
    }

    /**
     * The groups a user belongs to.
     *
     * @param int|User $user
     * @param bool $activeOnly False to include inactive groups too. Only a
     *                         screen that manages groups wants those; an
     *                         inactive group grants nothing.
     * @return static[] Each also carries role, added_at and member_count
     */
    public static function forUser(int|User $user, bool $activeOnly = true): array
    {
        $stmt = Database::getConnection()->prepare("
            SELECT
                g.*,
                gm.role,
                gm.added_at,
                (SELECT COUNT(*) FROM user_group_member WHERE group_id = g.group_id) as member_count
            FROM user_group g
            JOIN user_group_member gm ON g.group_id = gm.group_id
            WHERE gm.user_id = ?" . ($activeOnly ? " AND g.is_active = 1" : "") . "
            ORDER BY g.group_name ASC
        ");
        $stmt->execute([self::userId($user)]);

        return self::hydrate($stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * The ids of the active groups a user belongs to.
     *
     * @return int[]
     */
    public static function idsForUser(int|User $user): array
    {
        return array_map(fn(self $group) => $group->holderId(), self::forUser($user));
    }

    /**
     * Take a user out of every group, active or not. User::delete() calls
     * this; nothing else should need to.
     *
     * @return int How many memberships were removed
     */
    public static function removeFromAll(int|User $user): int
    {
        $stmt = Database::getConnection()->prepare("DELETE FROM user_group_member WHERE user_id = ?");
        $stmt->execute([self::userId($user)]);

        return $stmt->rowCount();
    }

    /* ── Members ── */

    /** Is the user a member, and is the group active? */
    public function has(int|User $user): bool
    {
        return (bool) $this->is_active && $this->role($user) !== null;
    }

    /**
     * May this user share things with the group, or attach things to it? A
     * member of the group while it is active may, and so may an admin
     * (allow.admin). Zero\Core\Access\Permission refuses a grant to a group from
     * anyone else.
     */
    public function usableBy(int|User $user): bool
    {
        if ($this->has($user)) {
            return true;
        }
        $user = $user instanceof User ? $user : User::find($user);

        return $user !== null && $user->can('allow.admin');
    }

    /** The user's role in this group, or null if they are not a member. */
    public function role(int|User $user): ?string
    {
        $stmt = Database::getConnection()->prepare(
            "SELECT role FROM user_group_member WHERE group_id = ? AND user_id = ?"
        );
        $stmt->execute([$this->group_id, self::userId($user)]);
        $role = $stmt->fetchColumn();

        return $role === false ? null : (string) $role;
    }

    /** How many members the group has. */
    public function memberCount(): int
    {
        $stmt = Database::getConnection()->prepare("SELECT COUNT(*) FROM user_group_member WHERE group_id = ?");
        $stmt->execute([$this->group_id]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * The group's members.
     *
     * @return array Membership rows (user_id, role, added_by, added_at), each
     *               with the member's username and email and added_by_username
     */
    public function members(): array
    {
        $stmt = Database::getConnection()->prepare("
            SELECT
                gm.*,
                u.name as username,
                u.email,
                adder.name as added_by_username
            FROM user_group_member gm
            JOIN users u ON gm.user_id = u.id
            LEFT JOIN users adder ON gm.added_by = adder.id
            WHERE gm.group_id = ?
            ORDER BY gm.role DESC, u.name ASC
        ");
        $stmt->execute([$this->group_id]);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Every user who is not in the group.
     *
     * @return array Rows of user_id, username, email
     */
    public function nonMembers(): array
    {
        $stmt = Database::getConnection()->prepare("
            SELECT u.id as user_id, u.name as username, u.email
            FROM users u
            WHERE u.id NOT IN (
                SELECT user_id
                FROM user_group_member
                WHERE group_id = ?
            )
            ORDER BY u.name ASC
        ");
        $stmt->execute([$this->group_id]);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Add a user to the group.
     *
     * @param int|User $user
     * @param string $role member, admin or owner
     * @param int|null $addedBy Who added them; defaults to the logged-in user
     * @throws \DomainException If the user is already a member
     */
    public function add(int|User $user, string $role = 'member', ?int $addedBy = null): void
    {
        try {
            Database::getConnection()->prepare("
                INSERT INTO user_group_member (group_id, user_id, role, added_by)
                VALUES (?, ?, ?, ?)
            ")->execute([$this->group_id, self::userId($user), $role, $addedBy ?? Session::userId()]);
        } catch (\PDOException $e) {
            if (Model::isDuplicateKey($e)) {
                throw new \DomainException("User is already a member of this group");
            }
            throw $e;
        }
    }

    /**
     * Remove a user from the group.
     *
     * @throws \DomainException If the user is not a member
     */
    public function remove(int|User $user): void
    {
        $stmt = Database::getConnection()->prepare(
            "DELETE FROM user_group_member WHERE group_id = ? AND user_id = ?"
        );
        $stmt->execute([$this->group_id, self::userId($user)]);

        if ($stmt->rowCount() === 0) {
            throw new \DomainException("User is not a member of this group");
        }
    }

    /**
     * Change a member's role.
     *
     * @throws \DomainException If the user is not a member
     */
    public function setRole(int|User $user, string $role): void
    {
        $stmt = Database::getConnection()->prepare(
            "UPDATE user_group_member SET role = ? WHERE group_id = ? AND user_id = ?"
        );
        $stmt->execute([$role, $this->group_id, self::userId($user)]);

        // rowCount() is 0 when the role is unchanged, so ask whether the
        // member exists rather than whether a row changed.
        if ($stmt->rowCount() === 0 && $this->role($user) === null) {
            throw new \DomainException("User is not a member of this group");
        }
    }

    /* ── internals ── */

    /** @return static[] */
    private static function hydrate(array $rows): array
    {
        return array_map(fn(array $row) => new static($row, true), $rows);
    }

    private static function userId(int|User $user): int
    {
        return $user instanceof User ? $user->holderId() : $user;
    }
}
