<?php
namespace Zero\Tests\Core;

use Zero\Core\Database;
use Zero\Core\Group;
use Zero\Core\Permission;
use Zero\Core\User;

/**
 * Zero\Core\User::delete() removes the account and everything core keeps
 * about it: group memberships, the permissions granted to the user, their
 * `user_settings` rows and their profile. What was granted to a group the
 * user was in is the group's, and stays.
 *
 * Runs against the real tables with a real group, a real member and real
 * grants. Every row it makes is named zerotest-* and is removed in cleanup().
 */
class UserDeleteTest extends ZeroTestCase
{
    private const ITEM = 4444;

    /** Mirrors the shape RegistrationFields::all() returns. */
    private const DEFS = [
        ['key' => 'zerotest_field', 'sensitive' => false],
    ];

    private Permission $perm;
    private User $user;
    private User $bystander;
    private Group $group;
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connectTestDatabase();
        $this->cleanup();

        $this->perm      = new Permission('zerotest', 'thing');
        $this->user      = User::create(['name' => 'Delete Probe', 'email' => 'zerotest-delete-probe@example.invalid', 'verified' => 1]);
        $this->bystander = User::create(['name' => 'Delete Bystander', 'email' => 'zerotest-delete-bystander@example.invalid', 'verified' => 1]);
        $this->userId    = (int) $this->user->id;

        $this->group = Group::create(['group_name' => 'zerotest-delete-group', 'created_by' => $this->userId]);
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    private function cleanup(): void
    {
        $this->removeTestRows('zerotest-delete-%@example.invalid', 'zerotest-delete-%');
        Database::getConnection()
            ->prepare("DELETE FROM permission WHERE permission_key LIKE 'zerotest.%' OR permission_key LIKE 'allow.zerotest%'")
            ->execute();
    }

    /** How many rows of $table belong to the user. */
    private function rowsFor(string $table, int $userId): int
    {
        $stmt = Database::getConnection()->prepare("SELECT COUNT(*) FROM {$table} WHERE user_id = ?");
        $stmt->execute([$userId]);
        return (int) $stmt->fetchColumn();
    }

    public function testDeleteRemovesTheAccount(): void
    {
        $this->user->delete();

        $this->assertNull(User::find($this->userId));
        $this->assertNotNull(User::find($this->bystander->id));
    }

    public function testDeleteRemovesTheUsersMembershipsActiveOrNot(): void
    {
        $inactive = Group::create(['group_name' => 'zerotest-delete-inactive', 'created_by' => $this->userId]);
        $this->group->add($this->user, 'owner', $this->userId);
        $this->group->add($this->bystander, 'member', $this->userId);
        $inactive->add($this->user, 'member', $this->userId);
        $inactive->deactivate();
        $this->assertSame(2, $this->rowsFor('user_group_member', $this->userId));

        $this->user->delete();

        $this->assertSame(0, $this->rowsFor('user_group_member', $this->userId));
        $this->assertNull($this->group->role($this->userId));
        $this->assertNull($inactive->role($this->userId));
        // The groups and their other members stay.
        $this->assertNotNull(Group::find($this->group->group_id));
        $this->assertNotNull(Group::find($inactive->group_id));
        $this->assertTrue($this->group->has($this->bystander));
    }

    public function testDeleteRemovesTheUsersDirectGrantsUnderEveryKey(): void
    {
        $other = new Permission('zerotest', 'other');
        $this->perm->grant(self::ITEM, $this->user, true, true, false, $this->userId);
        $other->grant(7, $this->user, true, false, false, $this->userId);
        $this->user->grant('allow.zerotest', $this->userId);
        $this->perm->grant(self::ITEM, $this->bystander, true, false, false, $this->userId);
        $this->assertSame(3, $this->rowsFor('permission', $this->userId));

        $this->user->delete();

        $this->assertSame(0, $this->rowsFor('permission', $this->userId));
        $this->assertFalse($this->perm->can(self::ITEM, $this->userId));
        $this->assertTrue($this->perm->can(self::ITEM, $this->bystander), "Another user's grant is not theirs.");
    }

    public function testDeleteLeavesGroupGrantsAlone(): void
    {
        $this->group->add($this->user, 'member', $this->userId);
        $this->group->add($this->bystander, 'member', $this->userId);
        $this->perm->grant(self::ITEM, $this->group, true, true, false, $this->userId);
        $this->group->grant('allow.zerotest', $this->userId);
        $this->perm->grant(self::ITEM, $this->user, true, false, true, $this->userId);

        $this->user->delete();

        $row = $this->perm->find(self::ITEM, $this->group);
        $this->assertNotNull($row, "The group's grant is the group's and stays.");
        $this->assertSame([1, 1, 0], [$row['can_read'], $row['can_update'], $row['can_delete']]);
        $this->assertSame(['allow.zerotest'], Group::find($this->group->group_id)->capabilities());
        $this->assertTrue($this->perm->can(self::ITEM, $this->bystander, 'update'));
        $this->assertTrue(User::find($this->bystander->id)->can('allow.zerotest'));
        // The deleted user is out of the group, so its grant no longer reaches them.
        $this->assertFalse($this->perm->can(self::ITEM, $this->userId, 'read'));
    }

    public function testDeleteLeavesAGroupWithTheSameIdAsTheUserAlone(): void
    {
        // The row a group of the same id would hold: group_id = the user's id.
        $sameId = new Group(['group_id' => $this->userId, 'group_name' => 'zerotest-delete-same-id'], true);
        // Granted by nobody (the system): this group has no members to grant it.
        $this->logoutUser();
        $this->perm->grant(self::ITEM, $sameId, true, false, false);
        $this->perm->grant(self::ITEM, $this->user, true, false, false, $this->userId);

        try {
            $this->user->delete();

            $stmt = Database::getConnection()->prepare(
                "SELECT user_id, group_id FROM permission WHERE permission_key = 'zerotest.thing' AND entity_id = ?"
            );
            $stmt->execute([self::ITEM]);
            $this->assertSame(
                [['user_id' => 0, 'group_id' => $this->userId]],
                $stmt->fetchAll(\PDO::FETCH_ASSOC)
            );
        } finally {
            $this->perm->forget(self::ITEM);
        }
    }

    public function testDeleteRemovesTheUsersSettings(): void
    {
        $this->user->setSetting('zerotest.setting', '1');
        $this->user->setSetting('auth.level', '2');
        $this->bystander->setSetting('zerotest.setting', '1');
        $this->assertSame(2, $this->rowsFor('user_settings', $this->userId));

        $this->user->delete();

        $this->assertSame(0, $this->rowsFor('user_settings', $this->userId));
        $this->assertSame(1, $this->rowsFor('user_settings', (int) $this->bystander->id));
    }

    public function testDeleteRemovesTheUsersProfile(): void
    {
        $this->user->storeProfile(['zerotest_field' => 'mine'], self::DEFS);
        $this->bystander->storeProfile(['zerotest_field' => 'theirs'], self::DEFS);
        $this->assertSame(1, $this->rowsFor('user_profile', $this->userId));

        $this->user->delete();

        $this->assertSame(0, $this->rowsFor('user_profile', $this->userId));
        $this->assertSame(['zerotest_field' => 'theirs'], $this->bystander->profile());
    }

    public function testDeleteRemovesEverythingAtOnce(): void
    {
        $this->group->add($this->user, 'member', $this->userId);
        $this->perm->grant(self::ITEM, $this->user, true, false, false, $this->userId);
        $this->perm->grant(self::ITEM, $this->group, true, false, false, $this->userId);
        $this->user->grant('allow.zerotest', $this->userId);
        $this->user->setSetting('zerotest.setting', '1');
        $this->user->storeProfile(['zerotest_field' => 'mine'], self::DEFS);

        // Loaded fresh, the way a caller that deletes an account has it.
        User::find($this->userId)->delete();

        $this->assertNull(User::find($this->userId));
        foreach (['user_group_member', 'permission', 'user_settings', 'user_profile'] as $table) {
            $this->assertSame(0, $this->rowsFor($table, $this->userId), "rows left in {$table}");
        }
        $this->assertNotNull($this->perm->find(self::ITEM, $this->group));
    }

    public function testDeleteLeavesTheTransactionToTheCaller(): void
    {
        $this->group->add($this->user, 'member', $this->userId);
        $this->user->grant('allow.zerotest', $this->userId);
        $this->user->setSetting('zerotest.setting', '1');
        $this->user->storeProfile(['zerotest_field' => 'mine'], self::DEFS);

        $db = Database::getConnection();
        $db->beginTransaction();
        try {
            $this->user->delete();
            $this->assertTrue($db->inTransaction(), 'delete() must not commit the transaction it was called in.');
        } finally {
            $db->rollBack();
        }

        // Rolled back: all of it is still there.
        $this->assertNotNull(User::find($this->userId));
        foreach (['user_group_member', 'permission', 'user_settings', 'user_profile'] as $table) {
            $this->assertSame(1, $this->rowsFor($table, $this->userId), "{$table} was not rolled back");
        }
    }

    /**
     * id 0 is not a user: in `permission` it marks a row as a group's. A PDO
     * that must never be called proves delete() refuses before any query.
     */
    public function testDeleteDoesNothingForAUserWithNoUsableId(): void
    {
        $real = Database::$connection;
        $mockPdo = $this->createMock(\PDO::class);
        $mockPdo->expects($this->never())->method('prepare');
        Database::$connection = $mockPdo;

        try {
            (new User(['id' => 0], true))->delete();
            (new User(['id' => -5], true))->delete();
            // Never saved, so there is nothing to delete.
            (new User(['name' => 'Unsaved', 'email' => 'zerotest-delete-unsaved@example.invalid']))->delete();
        } finally {
            Database::$connection = $real;
        }
    }
}
