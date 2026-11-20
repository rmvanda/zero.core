<?php
namespace Zero\Tests\Core;

use Zero\Core\Database;
use Zero\Core\Access\Group;
use Zero\Core\Access\Holder;
use Zero\Core\Model;
use Zero\Core\Access\Permission;
use Zero\Core\Access\User;

/**
 * Zero\Core\Access\Group against the real `user_group` and `user_group_member` tables, with a
 * real group and a real member. Every row it makes is named zerotest-* and is
 * removed again in cleanup().
 */
class GroupTest extends ZeroTestCase
{
    private const NAME  = 'zerotest-group';
    private const EMAIL = 'zerotest-group-member@example.invalid';
    private const OTHER = 'zerotest-group-other@example.invalid';
    private const ITEM  = 4343;

    private User $user;
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connectTestDatabase();
        $this->cleanup();

        $this->user   = User::create(['name' => 'Group Member', 'email' => self::EMAIL, 'verified' => 1]);
        $this->userId = (int) $this->user->id;
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    private function cleanup(): void
    {
        // Memberships and grants are removed by hand: no test here relies on
        // a foreign key to do it.
        $this->removeTestRows('zerotest-group-%@example.invalid', 'zerotest-group%');
        Database::getConnection()
            ->prepare("DELETE FROM permission WHERE permission_key LIKE 'zerotest.%' OR permission_key LIKE 'allow.zerotest%'")
            ->execute();
    }

    private function makeGroup(string $name = self::NAME): Group
    {
        return Group::create(['group_name' => $name, 'description' => 'temporary', 'created_by' => $this->userId]);
    }

    /** group_id of each group, in order. */
    private function ids(array $groups): array
    {
        return array_map(fn(Group $group) => $group->group_id, $groups);
    }

    /** How many `permission` rows the group holds, under any key. */
    private function grantCount(int $groupId): int
    {
        $stmt = Database::getConnection()->prepare("SELECT COUNT(*) FROM permission WHERE group_id = ?");
        $stmt->execute([$groupId]);
        return (int) $stmt->fetchColumn();
    }

    /* ── groups ── */

    public function testCreateThenFind(): void
    {
        $created = $this->makeGroup();
        $this->assertInstanceOf(Group::class, $created);
        $this->assertGreaterThan(0, $created->group_id);

        $group = Group::find($created->group_id);
        $this->assertInstanceOf(Group::class, $group);
        $this->assertSame($created->group_id, $group->group_id);
        $this->assertSame(self::NAME, $group->group_name);
        $this->assertSame('temporary', $group->description);
        $this->assertSame(1, $group->is_active);
        $this->assertSame($this->userId, $group->created_by);
        $this->assertSame(0, $group->memberCount());
    }

    public function testCreateDefaultsToActiveAndToTheLoggedInUserAsCreator(): void
    {
        $this->loginUser(['user_id' => $this->userId]);

        $group = Group::find(Group::create(['group_name' => self::NAME])->group_id);

        $this->assertSame(1, $group->is_active);
        $this->assertSame($this->userId, $group->created_by);
    }

    public function testCreateWithNobodyLoggedInHasNoCreator(): void
    {
        $this->logoutUser();

        $group = Group::find(Group::create(['group_name' => self::NAME])->group_id);

        $this->assertNull($group->created_by);
    }

    public function testFindReturnsNullForAMissingGroup(): void
    {
        $this->assertNull(Group::find(0));
    }

    public function testToArrayGivesTheRow(): void
    {
        $row = Group::find($this->makeGroup()->group_id)->toArray();

        $this->assertSame(self::NAME, $row['group_name']);
        $this->assertSame('temporary', $row['description']);
        $this->assertSame(1, $row['is_active']);
    }

    public function testCreateRejectsADuplicateName(): void
    {
        $this->makeGroup();

        try {
            $this->makeGroup();
            $this->fail('Expected a PDOException on a duplicate group_name');
        } catch (\PDOException $e) {
            $this->assertTrue(Model::isDuplicateKey($e));
        }
    }

    public function testSaveRejectsRenamingToADuplicateName(): void
    {
        $this->makeGroup('zerotest-group-a');
        $b = $this->makeGroup('zerotest-group-b');

        $b->group_name = 'zerotest-group-a';
        try {
            $b->save();
            $this->fail('Expected a PDOException on a duplicate group_name');
        } catch (\PDOException $e) {
            $this->assertTrue(Model::isDuplicateKey($e));
        }
        $this->assertSame('zerotest-group-b', Group::find($b->group_id)->group_name);
    }

    public function testSaveChangesOnlyTheGivenFields(): void
    {
        $group = $this->makeGroup();

        $group->description = 'changed';
        $group->save();

        $fresh = Group::find($group->group_id);
        $this->assertSame('changed', $fresh->description);
        $this->assertSame(self::NAME, $fresh->group_name);
        $this->assertSame(1, $fresh->is_active);
    }

    public function testSaveIgnoresWhatIsNotAColumnOfTheGroup(): void
    {
        $group = $this->makeGroup();
        $id = $group->group_id;

        // group_id belongs to the DDL and member_count is not a column at all.
        $group->group_id = $id + 1000;
        $group->member_count = 99;
        $group->save();

        $this->assertSame($id, $group->group_id);
        $this->assertSame(self::NAME, Group::find($id)->group_name);
        $this->assertNull(Group::find($id + 1000));
    }

    public function testDeactivateAndActivate(): void
    {
        $group = $this->makeGroup();

        $group->deactivate();
        $this->assertSame(0, $group->is_active);
        $this->assertSame(0, Group::find($group->group_id)->is_active);

        $group->activate();
        $this->assertSame(1, $group->is_active);
        $this->assertSame(1, Group::find($group->group_id)->is_active);
    }

    public function testAllCarriesMemberCountAndTheCreatorsName(): void
    {
        $group = $this->makeGroup();
        $group->add($this->userId, 'member', $this->userId);

        $mine = array_values(array_filter(Group::all(), fn(Group $g) => $g->group_id === $group->group_id));

        $this->assertCount(1, $mine);
        $this->assertInstanceOf(Group::class, $mine[0]);
        $this->assertSame(self::NAME, $mine[0]->group_name);
        $this->assertSame(1, $mine[0]->member_count);
        $this->assertSame('Group Member', $mine[0]->created_by_username);
    }

    public function testAllCanLeaveInactiveGroupsOut(): void
    {
        $group = $this->makeGroup();
        $group->deactivate();

        $this->assertContains($group->group_id, $this->ids(Group::all()));
        $this->assertNotContains($group->group_id, $this->ids(Group::all(true)));
    }

    public function testDeleteRemovesTheGroupAndItsMembers(): void
    {
        $group = $this->makeGroup();
        $id = $group->group_id;
        $group->add($this->userId, 'member', $this->userId);

        $group->delete();

        $this->assertNull(Group::find($id));
        $this->assertNull($group->role($this->userId));
        $this->assertSame([], Group::forUser($this->userId, false));

        $stmt = Database::getConnection()->prepare("SELECT COUNT(*) FROM user_group_member WHERE group_id = ?");
        $stmt->execute([$id]);
        $this->assertSame(0, (int) $stmt->fetchColumn());
    }

    public function testDeleteRemovesEveryGrantToTheGroupAndNobodyElses(): void
    {
        $group = $this->makeGroup();
        $kept  = $this->makeGroup('zerotest-group-kept');
        $thing = new Permission('zerotest', 'thing');
        $other = new Permission('zerotest', 'other');
        // The user shares with groups they belong to.
        $group->add($this->userId, 'member', $this->userId);
        $kept->add($this->userId, 'member', $this->userId);

        $thing->grant(self::ITEM, $group, true, true, false, $this->userId);
        $other->grant(7, $group, true, false, false, $this->userId);
        $group->grant('allow.zerotest', $this->userId);
        $thing->grant(self::ITEM, $kept, true, false, false, $this->userId);
        $thing->grant(self::ITEM, $this->user, true, false, false, $this->userId);
        $this->assertSame(3, $this->grantCount($group->group_id));

        $id = $group->group_id;
        $group->delete();

        $this->assertSame(0, $this->grantCount($id));
        $this->assertSame(1, $this->grantCount($kept->group_id));
        $this->assertTrue($thing->can(self::ITEM, $this->user), "The user's own grant is not the group's.");
    }

    public function testDeletingAGroupThatWasNeverSavedDoesNothing(): void
    {
        $real = Database::$connection;
        $mockPdo = $this->createMock(\PDO::class);
        $mockPdo->expects($this->never())->method('prepare');
        Database::$connection = $mockPdo;

        try {
            (new Group(['group_name' => self::NAME]))->delete();
        } finally {
            Database::$connection = $real;
        }
    }

    /* ── a group is a Holder ── */

    public function testAGroupIsAHolderInTheGroupColumn(): void
    {
        $group = $this->makeGroup();

        $this->assertInstanceOf(Holder::class, $group);
        $this->assertSame('group_id', $group->holderColumn());
        $this->assertSame($group->group_id, $group->holderId());
    }

    public function testGrantCanCapabilitiesAndRevokeOnAGroup(): void
    {
        $group = $this->makeGroup();
        $group->add($this->userId, 'member', $this->userId);
        $this->assertFalse($group->can('allow.zerotest'));
        $this->assertSame([], $group->capabilities());

        $this->assertTrue($group->grant('allow.zerotest', $this->userId));
        $this->assertTrue($group->grant('allow.zerotest.second', $this->userId));

        // The same instance sees its own write, and so does a fresh one.
        $this->assertTrue($group->can('allow.zerotest'));
        $this->assertSame(
            ['allow.zerotest', 'allow.zerotest.second'],
            Group::find($group->group_id)->capabilities()
        );

        $this->assertTrue($group->revoke('allow.zerotest'));

        $this->assertFalse($group->can('allow.zerotest'));
        $this->assertSame(['allow.zerotest.second'], Group::find($group->group_id)->capabilities());
    }

    public function testAGroupsCapabilityIsARowOnEntityZeroInTheGroupColumn(): void
    {
        $group = $this->makeGroup();
        $group->add($this->userId, 'member', $this->userId);
        $group->grant('allow.zerotest', $this->userId);

        $stmt = Database::getConnection()->prepare(
            "SELECT entity_id, user_id, group_id, can_read, can_update, can_delete, granted_by FROM permission
             WHERE permission_key = ?"
        );
        $stmt->execute(['allow.zerotest']);

        $this->assertSame(
            [[
                'entity_id' => 0, 'user_id' => 0, 'group_id' => $group->group_id,
                'can_read' => 1, 'can_update' => 0, 'can_delete' => 0, 'granted_by' => $this->userId,
            ]],
            $stmt->fetchAll(\PDO::FETCH_ASSOC)
        );
    }

    public function testGrantOnAGroupRefusesAKeyThatIsNotACapabilityKey(): void
    {
        $group = $this->makeGroup();

        $this->assertFalse($group->grant('Not A Key'));
        $this->assertFalse($group->grant('lists.list'), 'A capability key starts with allow.');
        $this->assertSame(0, $this->grantCount($group->group_id));
    }

    public function testAGroupThatWasNeverSavedHoldsAndIsGrantedNothing(): void
    {
        $real = Database::$connection;
        $mockPdo = $this->createMock(\PDO::class);
        $mockPdo->expects($this->never())->method('prepare');
        Database::$connection = $mockPdo;

        try {
            $group = new Group(['group_name' => self::NAME]);
            $this->assertSame(0, $group->holderId());
            $this->assertSame([], $group->capabilities());
            $this->assertFalse($group->grant('allow.zerotest'), 'a grant to group 0 would be a row with no holder.');
            $this->assertFalse($group->revoke('allow.zerotest'));
        } finally {
            Database::$connection = $real;
        }
    }

    public function testMembersOfAnActiveGroupHoldItsCapabilities(): void
    {
        $group = $this->makeGroup();
        // Granted by nobody (the system): the user is not a member yet, so
        // could not grant it themselves.
        $this->logoutUser();
        $this->assertTrue($group->grant('allow.zerotest'));
        $outsider = User::create(['name' => 'Group Other', 'email' => self::OTHER, 'verified' => 1]);

        $this->assertFalse(User::find($this->userId)->can('allow.zerotest'), 'Not a member yet.');

        $group->add($this->userId, 'member', $this->userId);

        $this->assertTrue(User::find($this->userId)->can('allow.zerotest'));
        $this->assertSame(['allow.zerotest'], User::find($this->userId)->capabilities());
        $this->assertFalse(User::find($outsider->id)->can('allow.zerotest'));

        $group->remove($this->userId);
        $this->assertFalse(User::find($this->userId)->can('allow.zerotest'));
    }

    public function testADeactivatedGroupGrantsItsMembersNoCapability(): void
    {
        $group = $this->makeGroup();
        $group->add($this->userId, 'member', $this->userId);
        $group->grant('allow.zerotest', $this->userId);
        $this->assertTrue(User::find($this->userId)->can('allow.zerotest'));

        $group->deactivate();

        $this->assertFalse(User::find($this->userId)->can('allow.zerotest'));
        // The grant itself stays, so activating the group gives it back.
        $this->assertSame(1, $this->grantCount($group->group_id));

        $group->activate();
        $this->assertTrue(User::find($this->userId)->can('allow.zerotest'));
    }

    /* ── members ── */

    public function testAddMakesTheUserAMember(): void
    {
        $group = $this->makeGroup();
        $this->assertFalse($group->has($this->userId));
        $this->assertNull($group->role($this->userId));

        $group->add($this->userId, 'admin', $this->userId);

        $this->assertTrue($group->has($this->userId));
        $this->assertSame('admin', $group->role($this->userId));
        $this->assertSame(1, $group->memberCount());
    }

    public function testAddDefaultsToTheMemberRole(): void
    {
        $group = $this->makeGroup();

        $group->add($this->userId);

        $this->assertSame('member', $group->role($this->userId));
    }

    public function testAddRecordsWhoAddedTheMember(): void
    {
        $group = $this->makeGroup();
        $other = User::create(['name' => 'Group Other', 'email' => self::OTHER, 'verified' => 1]);

        $group->add($this->userId, 'member', (int) $other->id);
        $this->assertSame((int) $other->id, $group->members()[0]['added_by']);
        $this->assertSame('Group Other', $group->members()[0]['added_by_username']);

        // With nobody named, it is the logged-in user.
        $this->loginUser(['user_id' => $this->userId]);
        $group->add($other);
        $byUser = array_column($group->members(), 'added_by', 'user_id');
        $this->assertSame($this->userId, $byUser[(int) $other->id]);
    }

    public function testMembershipMethodsTakeAUserAsWellAsAnId(): void
    {
        $group = $this->makeGroup();

        $group->add($this->user, 'member', $this->userId);
        $this->assertTrue($group->has($this->user));
        $this->assertSame('member', $group->role($this->user));

        $group->setRole($this->user, 'admin');
        $this->assertSame('admin', $group->role($this->userId));
        $this->assertSame([$group->group_id], Group::idsForUser($this->user));

        $group->remove($this->user);
        $this->assertFalse($group->has($this->userId));
    }

    public function testAddingTwiceThrows(): void
    {
        $group = $this->makeGroup();
        $group->add($this->userId, 'member', $this->userId);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('User is already a member of this group');
        $group->add($this->userId, 'member', $this->userId);
    }

    public function testMembersAndNonMembers(): void
    {
        $group = $this->makeGroup();
        $this->assertContains($this->userId, array_column($group->nonMembers(), 'user_id'));

        $group->add($this->userId, 'member', $this->userId);

        $members = $group->members();
        $this->assertCount(1, $members);
        $this->assertSame($this->userId, $members[0]['user_id']);
        $this->assertSame(self::EMAIL, $members[0]['email']);
        $this->assertSame('Group Member', $members[0]['username']);
        $this->assertSame('member', $members[0]['role']);
        $this->assertNotContains($this->userId, array_column($group->nonMembers(), 'user_id'));
    }

    public function testSetRole(): void
    {
        $group = $this->makeGroup();
        $group->add($this->userId, 'member', $this->userId);

        $group->setRole($this->userId, 'owner');
        $this->assertSame('owner', $group->role($this->userId));

        // Setting the role it already has is not an error.
        $group->setRole($this->userId, 'owner');
        $this->assertSame('owner', $group->role($this->userId));
    }

    public function testSetRoleForANonMemberThrows(): void
    {
        $group = $this->makeGroup();

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('User is not a member of this group');
        $group->setRole($this->userId, 'owner');
    }

    public function testRemove(): void
    {
        $group = $this->makeGroup();
        $group->add($this->userId, 'member', $this->userId);

        $group->remove($this->userId);

        $this->assertFalse($group->has($this->userId));
        $this->assertNull($group->role($this->userId));
        $this->assertSame(0, $group->memberCount());
    }

    public function testRemovingANonMemberThrows(): void
    {
        $group = $this->makeGroup();

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('User is not a member of this group');
        $group->remove($this->userId);
    }

    public function testMembershipIsPerGroup(): void
    {
        $a = $this->makeGroup('zerotest-group-a');
        $b = $this->makeGroup('zerotest-group-b');

        $a->add($this->userId, 'owner', $this->userId);

        $this->assertTrue($a->has($this->userId));
        $this->assertFalse($b->has($this->userId));
        $this->assertNull($b->role($this->userId));
    }

    /* ── who may share with a group ── */

    public function testAGroupIsUsableByItsMembers(): void
    {
        $group = $this->makeGroup();
        $this->assertFalse($group->usableBy($this->userId), 'Not a member yet.');

        $group->add($this->userId, 'member', $this->userId);

        $this->assertTrue($group->usableBy($this->userId));
        $this->assertTrue($group->usableBy($this->user), 'A User works as well as an id.');
    }

    public function testAGroupIsNotUsableBySomeoneOutsideIt(): void
    {
        $group = $this->makeGroup();
        $group->add($this->userId, 'member', $this->userId);
        $outsider = User::create(['name' => 'Group Other', 'email' => self::OTHER, 'verified' => 1]);

        $this->assertFalse($group->usableBy((int) $outsider->id));
        $this->assertFalse($group->usableBy($outsider));
    }

    public function testADeactivatedGroupIsNotUsableByItsMembers(): void
    {
        $group = $this->makeGroup();
        $group->add($this->userId, 'member', $this->userId);

        $group->deactivate();

        $this->assertFalse($group->usableBy($this->userId));
        $this->assertFalse(Group::find($group->group_id)->usableBy($this->userId));

        $group->activate();
        $this->assertTrue($group->usableBy($this->userId));
    }

    public function testAnyGroupIsUsableByAnAdmin(): void
    {
        $group = $this->makeGroup();
        $admin = User::create(['name' => 'Group Other', 'email' => self::OTHER, 'verified' => 1]);
        $this->assertFalse($group->usableBy((int) $admin->id));

        $this->logoutUser();
        $this->assertTrue($admin->grant('allow.admin'));

        $this->assertFalse($group->has($admin), 'An admin is not made a member.');
        $this->assertTrue($group->usableBy((int) $admin->id));
        $this->assertTrue($group->usableBy(User::find($admin->id)));

        // An admin may use a deactivated group too.
        $group->deactivate();
        $this->assertTrue($group->usableBy((int) $admin->id));
    }

    public function testAGroupIsNotUsableByAnUnknownUser(): void
    {
        $group = $this->makeGroup();
        // A user id that is certainly not in use: one that was just deleted.
        $gone = User::create(['name' => 'Group Other', 'email' => self::OTHER, 'verified' => 1]);
        $goneId = (int) $gone->id;
        $gone->delete();

        $this->assertFalse($group->usableBy($goneId));
        $this->assertFalse($group->usableBy(0));
    }

    public function testACapabilityGrantToAGroupIsRefusedFromSomeoneOutsideIt(): void
    {
        $group = $this->makeGroup();
        $outsider = (int) User::create(['name' => 'Group Other', 'email' => self::OTHER, 'verified' => 1])->id;

        $this->assertFalse($group->grant('allow.zerotest', $outsider));

        $this->assertSame(0, $this->grantCount($group->group_id));
        $this->assertFalse($group->can('allow.zerotest'));
        $this->assertSame([], Group::find($group->group_id)->capabilities());

        // The same for the logged-in user when nobody is named.
        $this->loginUser(['user_id' => $outsider]);
        $this->assertFalse($group->grant('allow.zerotest'));
        $this->assertSame(0, $this->grantCount($group->group_id));
    }

    public function testACapabilityGrantToAGroupIsAllowedFromAnAdminOutsideIt(): void
    {
        $group = $this->makeGroup();
        $admin = User::create(['name' => 'Group Other', 'email' => self::OTHER, 'verified' => 1]);
        $this->logoutUser();
        $admin->grant('allow.admin');

        $this->assertTrue($group->grant('allow.zerotest', (int) $admin->id));

        $this->assertSame(['allow.zerotest'], Group::find($group->group_id)->capabilities());
    }

    /* ── a user's groups ── */

    public function testForUserReturnsTheUsersGroupsWithTheirRole(): void
    {
        $a = $this->makeGroup('zerotest-group-a')->group_id;
        $b = $this->makeGroup('zerotest-group-b');
        $this->makeGroup('zerotest-group-not-mine');
        $b->add($this->userId, 'owner', $this->userId);
        Group::find($a)->add($this->userId, 'member', $this->userId);
        $b = $b->group_id;

        $mine = Group::forUser($this->userId);

        $this->assertContainsOnlyInstancesOf(Group::class, $mine);
        $this->assertSame([$a, $b], $this->ids($mine));
        $this->assertSame(['member', 'owner'], array_map(fn(Group $g) => $g->role, $mine));
        $this->assertSame(1, $mine[0]->member_count);
        $this->assertNotNull($mine[0]->added_at);
        $this->assertSame([$a, $b], Group::idsForUser($this->userId));
    }

    public function testForUserCanIncludeInactiveGroups(): void
    {
        $group = $this->makeGroup();
        $group->add($this->userId, 'member', $this->userId);
        $group->deactivate();

        $this->assertSame([], Group::forUser($this->userId));
        $this->assertSame([], Group::forUser($this->userId, true));
        $this->assertSame([$group->group_id], $this->ids(Group::forUser($this->userId, false)));
    }

    public function testAUsersGroupsAreTheSameAsForUser(): void
    {
        $active   = $this->makeGroup('zerotest-group-a');
        $inactive = $this->makeGroup('zerotest-group-b');
        $active->add($this->userId, 'owner', $this->userId);
        $inactive->add($this->userId, 'member', $this->userId);
        $inactive->deactivate();

        $groups = $this->user->groups();
        $this->assertSame([$active->group_id], $this->ids($groups));
        $this->assertSame('owner', $groups[0]->role);
        $this->assertSame(1, $groups[0]->member_count);

        $this->assertSame([$active->group_id], $this->ids($this->user->groups(true)));
        $this->assertSame(
            [$active->group_id, $inactive->group_id],
            $this->ids($this->user->groups(false))
        );
    }

    public function testRemoveFromAllLeavesEveryGroupActiveOrNot(): void
    {
        $a = $this->makeGroup('zerotest-group-a');
        $b = $this->makeGroup('zerotest-group-b');
        $other = User::create(['name' => 'Group Other', 'email' => self::OTHER, 'verified' => 1]);
        $a->add($this->userId, 'member', $this->userId);
        $a->add($other, 'member', $this->userId);
        $b->add($this->userId, 'owner', $this->userId);
        $b->deactivate();

        $this->assertSame(2, Group::removeFromAll($this->userId));

        $this->assertSame([], Group::forUser($this->userId, false));
        $this->assertTrue($a->has($other), 'Nobody else leaves with them.');
        $this->assertSame(0, Group::removeFromAll($this->userId));
        $this->assertSame(1, Group::removeFromAll($other), 'A User works as well as an id.');
    }

    public function testAnInactiveGroupGrantsNothing(): void
    {
        $group = $this->makeGroup();
        $group->add($this->userId, 'member', $this->userId);

        $group->deactivate();

        $this->assertSame([], Group::forUser($this->userId));
        $this->assertSame([], Group::idsForUser($this->userId));
        $this->assertFalse($group->has($this->userId));
        $this->assertFalse(Group::find($group->group_id)->has($this->userId));
        // Still there for the Admin screens.
        $this->assertSame('member', $group->role($this->userId));
        $this->assertCount(1, $group->members());
        $this->assertSame(0, Group::find($group->group_id)->is_active);
    }
}
