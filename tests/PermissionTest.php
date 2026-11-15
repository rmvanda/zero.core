<?php
namespace Zero\Tests\Core;

use Zero\Core\Database;
use Zero\Core\Group;
use Zero\Core\Permission;
use Zero\Core\User;

/**
 * Zero\Core\Permission against the real `permission`, `user_group` and
 * `user_group_member` tables, with real groups and members. Every row it makes
 * is under a zerotest.* key or a zerotest-* name and is removed in cleanup().
 */
class PermissionTest extends ZeroTestCase
{
    private const ITEM = 4242;

    private Permission $perm;
    private Group $group;
    private User $memberUser;
    private User $outsiderUser;
    private int $member;
    private int $outsider;
    private int $groupId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connectTestDatabase();
        $this->cleanup();

        $this->perm = new Permission('zerotest', 'thing');

        $this->memberUser   = User::create(['name' => 'Perm Member', 'email' => 'zerotest-perm-member@example.invalid', 'verified' => 1]);
        $this->outsiderUser = User::create(['name' => 'Perm Outsider', 'email' => 'zerotest-perm-outsider@example.invalid', 'verified' => 1]);
        $this->member   = (int) $this->memberUser->id;
        $this->outsider = (int) $this->outsiderUser->id;

        $this->group = Group::create(['group_name' => 'zerotest-perm-group', 'created_by' => $this->member]);
        $this->group->add($this->member, 'member', $this->member);
        $this->groupId = $this->group->group_id;
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    private function cleanup(): void
    {
        $this->removeTestRows('zerotest-perm-%@example.invalid', 'zerotest-perm-%');
        Database::getConnection()
            ->prepare("DELETE FROM permission WHERE permission_key LIKE 'zerotest.%' OR permission_key LIKE 'allow.zerotest%'")
            ->execute();
    }

    private function row(int $entityId, int $userId, int $groupId): array|false
    {
        $stmt = Database::getConnection()->prepare(
            "SELECT * FROM permission WHERE permission_key = 'zerotest.thing' AND entity_id = ? AND user_id = ? AND group_id = ?"
        );
        $stmt->execute([$entityId, $userId, $groupId]);
        return $stmt->fetch(\PDO::FETCH_ASSOC);
    }

    /* ── keys ── */

    public function testTheKeyIsTheModuleNameADotAndTheEntity(): void
    {
        $this->assertSame('lists.list', Permission::key('lists', 'list'));
        $this->assertSame('tucker-tapper.high_score', Permission::key('tucker-tapper', 'high_score'));
        $this->assertSame('zerotest.thing', $this->perm->permissionKey());
    }

    /** @return array<string, array{string, string}> */
    public static function badKeys(): array
    {
        return [
            'capital in the module'  => ['Lists', 'list'],
            'dot in the module'      => ['lists.sub', 'list'],
            'empty module'           => ['', 'list'],
            'allow is for the site'  => ['allow', 'polly'],
            'wildcard in the module' => ['li%', 'list'],
            'dot in the entity'      => ['lists', 'list.item'],
            'empty entity'           => ['lists', ''],
            'space in the entity'    => ['lists', 'my list'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badKeys')]
    public function testABadKeyIsRefused(string $module, string $entity): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Permission::key($module, $entity);
    }

    /* ── group grants ── */

    public function testNobodyCanAnythingBeforeAGrant(): void
    {
        $this->assertFalse($this->perm->can(self::ITEM, $this->member, 'read'));
        $this->assertSame([], $this->perm->ids($this->member));
    }

    public function testAGroupGrantReachesItsMembersAndNobodyElse(): void
    {
        $this->perm->grant(self::ITEM, $this->group, true, true, false, $this->member);

        $this->assertTrue($this->perm->can(self::ITEM, $this->member, 'read'));
        $this->assertTrue($this->perm->can(self::ITEM, $this->member, 'update'));
        $this->assertFalse($this->perm->can(self::ITEM, $this->member, 'delete'));
        $this->assertFalse($this->perm->can(self::ITEM, $this->outsider, 'read'));
        $this->assertFalse($this->perm->can(self::ITEM + 1, $this->member, 'read'));
    }

    public function testAGrantRecordsWhoGrantedIt(): void
    {
        $this->perm->grant(self::ITEM, $this->group, true, false, false, $this->member);

        $row = $this->row(self::ITEM, 0, $this->groupId);
        $this->assertSame($this->member, $row['granted_by']);
        $this->assertNull($row['updated_by']);
        $this->assertNull($row['updated_on']);
    }

    public function testGrantingAgainReplacesTheRightsAndRecordsTheUpdate(): void
    {
        // A second member of the group changes what the first one granted.
        $second = (int) User::create(['name' => 'Perm Second', 'email' => 'zerotest-perm-second@example.invalid', 'verified' => 1])->id;
        $this->group->add($second, 'member', $this->member);

        $this->perm->grant(self::ITEM, $this->group, true, true, true, $this->member);
        $this->perm->grant(self::ITEM, $this->group, true, false, false, $second);

        $row = $this->row(self::ITEM, 0, $this->groupId);
        $this->assertSame([1, 0, 0], [$row['can_read'], $row['can_update'], $row['can_delete']]);
        $this->assertSame($this->member, $row['granted_by']);
        $this->assertSame($second, $row['updated_by']);
        $this->assertNotNull($row['updated_on']);
    }

    public function testGrantingNoRightsRemovesTheRow(): void
    {
        $this->perm->grant(self::ITEM, $this->group, true, false, false, $this->member);
        $this->perm->grant(self::ITEM, $this->group, false, false, false, $this->member);

        $this->assertFalse($this->row(self::ITEM, 0, $this->groupId));
    }

    public function testRevoke(): void
    {
        $this->perm->grant(self::ITEM, $this->group, true, true, true, $this->member);

        $this->perm->revoke(self::ITEM, $this->group);

        $this->assertFalse($this->perm->can(self::ITEM, $this->member, 'read'));
    }

    public function testAnInactiveGroupGrantsNothing(): void
    {
        $this->perm->grant(self::ITEM, $this->group, true, true, true, $this->member);

        $this->group->deactivate();

        $this->assertFalse($this->perm->can(self::ITEM, $this->member, 'read'));
        $this->assertSame([], $this->perm->ids($this->member));
        $this->assertSame([], $this->perm->groups(self::ITEM));
        $this->assertSame([], $this->perm->users(self::ITEM));
    }

    public function testAnUnknownActionThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->perm->can(self::ITEM, $this->member, 'drop');
    }

    /* ── who may grant to a group ── */

    private function makeAdmin(): int
    {
        $admin = User::create(['name' => 'Perm Admin', 'email' => 'zerotest-perm-admin@example.invalid', 'verified' => 1]);
        // Granted by nobody: no session, no actor.
        $this->logoutUser();
        $this->assertTrue($admin->grant('allow.admin'));

        return (int) $admin->id;
    }

    /** How many rows there are under the test key, for any item and holder. */
    private function rowCount(): int
    {
        $stmt = Database::getConnection()->query("SELECT COUNT(*) FROM permission WHERE permission_key = 'zerotest.thing'");
        return (int) $stmt->fetchColumn();
    }

    public function testAGrantToAGroupIsRefusedFromSomeoneOutsideIt(): void
    {
        try {
            $this->perm->grant(self::ITEM, $this->group, true, true, true, $this->outsider);
            $this->fail('Someone outside the group must not grant to it');
        } catch (\DomainException $e) {
            $this->assertSame('You can only share with a group you belong to', $e->getMessage());
        }

        $this->assertSame(0, $this->rowCount(), 'Nothing is written.');
        $this->assertFalse($this->perm->can(self::ITEM, $this->member, 'read'));
    }

    public function testAGrantToAGroupIsRefusedFromALoggedInUserOutsideIt(): void
    {
        $this->loginUser(['user_id' => $this->outsider]);

        try {
            $this->perm->grant(self::ITEM, $this->group);
            $this->fail('Someone outside the group must not grant to it');
        } catch (\DomainException $e) {
            $this->assertSame('You can only share with a group you belong to', $e->getMessage());
        }

        $this->assertSame(0, $this->rowCount(), 'Nothing is written.');
    }

    public function testTheGranterNamedCountsNotTheLoggedInUser(): void
    {
        // A member is logged in, but the grant is made in an outsider's name.
        $this->loginUser(['user_id' => $this->member]);

        try {
            $this->perm->grant(self::ITEM, $this->group, true, false, false, $this->outsider);
            $this->fail('Someone outside the group must not grant to it');
        } catch (\DomainException $e) {
            $this->assertSame(0, $this->rowCount());
        }

        // And the other way round.
        $this->loginUser(['user_id' => $this->outsider]);
        $this->assertTrue($this->perm->grant(self::ITEM, $this->group, true, false, false, $this->member));
        $this->assertSame($this->member, $this->row(self::ITEM, 0, $this->groupId)['granted_by']);
    }

    public function testARefusedGrantLeavesTheRightsTheGroupHad(): void
    {
        $this->perm->grant(self::ITEM, $this->group, true, false, false, $this->member);

        try {
            $this->perm->grant(self::ITEM, $this->group, true, true, true, $this->outsider);
            $this->fail('Someone outside the group must not grant to it');
        } catch (\DomainException $e) {
            $this->addToAssertionCount(1);
        }

        $row = $this->row(self::ITEM, 0, $this->groupId);
        $this->assertSame([1, 0, 0], [$row['can_read'], $row['can_update'], $row['can_delete']]);
        $this->assertSame($this->member, $row['granted_by']);
        $this->assertNull($row['updated_by']);
    }

    public function testAGrantToAGroupIsAllowedFromAMember(): void
    {
        $this->assertTrue($this->perm->grant(self::ITEM, $this->group, true, false, false, $this->member));
        $this->assertNotFalse($this->row(self::ITEM, 0, $this->groupId));

        // And from a member who is the logged-in user.
        $this->loginUser(['user_id' => $this->member]);
        $this->assertTrue($this->perm->grant(self::ITEM + 1, $this->group));
        $this->assertSame($this->member, $this->row(self::ITEM + 1, 0, $this->groupId)['granted_by']);
    }

    public function testAGrantToAGroupIsRefusedFromAMemberOnceItIsDeactivated(): void
    {
        $this->group->deactivate();

        $this->expectException(\DomainException::class);
        $this->perm->grant(self::ITEM, $this->group, true, false, false, $this->member);
    }

    public function testAGrantToAGroupIsAllowedFromAnAdminOutsideIt(): void
    {
        $admin = $this->makeAdmin();
        $this->assertFalse($this->group->has($admin));

        $this->assertTrue($this->perm->grant(self::ITEM, $this->group, true, true, false, $admin));

        $row = $this->row(self::ITEM, 0, $this->groupId);
        $this->assertSame([1, 1, 0], [$row['can_read'], $row['can_update'], $row['can_delete']]);
        $this->assertSame($admin, $row['granted_by']);
    }

    public function testAGrantToAGroupWithNobodyGrantingIsAllowed(): void
    {
        // No granter named and nobody logged in: the system itself.
        $this->logoutUser();
        $empty = Group::create(['group_name' => 'zerotest-perm-empty']);

        $this->assertTrue($this->perm->grant(self::ITEM, $empty, true, false, false));

        $row = $this->row(self::ITEM, 0, $empty->group_id);
        $this->assertNotFalse($row);
        $this->assertNull($row['granted_by']);
    }

    public function testAnyoneMayGrantToAUser(): void
    {
        // The outsider belongs to no group; a grant to a user asks nothing of the granter.
        $this->assertTrue($this->perm->grant(self::ITEM, $this->memberUser, true, false, false, $this->outsider));
        $this->assertSame($this->outsider, $this->row(self::ITEM, $this->member, 0)['granted_by']);

        $this->loginUser(['user_id' => $this->outsider]);
        $this->assertTrue($this->perm->grant(self::ITEM + 1, $this->memberUser));
        $this->assertSame($this->outsider, $this->row(self::ITEM + 1, $this->member, 0)['granted_by']);
    }

    public function testSomeoneOutsideAGroupMayRevokeItsGrant(): void
    {
        $this->perm->grant(self::ITEM, $this->group, true, true, false, $this->member);

        $this->loginUser(['user_id' => $this->outsider]);
        $this->assertTrue($this->perm->revoke(self::ITEM, $this->group));

        $this->assertFalse($this->row(self::ITEM, 0, $this->groupId));
    }

    public function testGrantingNoRightsIsARevokeAndIsOpenToSomeoneOutsideTheGroup(): void
    {
        $this->perm->grant(self::ITEM, $this->group, true, true, false, $this->member);

        $this->assertTrue($this->perm->grant(self::ITEM, $this->group, false, false, false, $this->outsider));

        $this->assertFalse($this->row(self::ITEM, 0, $this->groupId));
    }

    /* ── user grants ── */

    public function testAUserGrantReachesOnlyThatUser(): void
    {
        $this->perm->grant(self::ITEM, $this->outsiderUser, true, false, false, $this->member);

        $this->assertTrue($this->perm->can(self::ITEM, $this->outsider, 'read'));
        $this->assertFalse($this->perm->can(self::ITEM, $this->outsider, 'update'));
        $this->assertFalse($this->perm->can(self::ITEM, $this->member, 'read'));
    }

    public function testAUserAndAGroupWithTheSameIdAreDifferentHolders(): void
    {
        // The user grant is made for the user whose id equals the group's id.
        $sameId = new User(['id' => $this->groupId], true);
        $this->assertSame($this->group->holderId(), $sameId->holderId());

        $this->perm->grant(self::ITEM, $sameId, true, false, false, $this->member);

        $this->assertNotFalse($this->row(self::ITEM, $this->groupId, 0));
        $this->assertFalse($this->row(self::ITEM, 0, $this->groupId));
        $this->assertFalse($this->perm->can(self::ITEM, $this->member, 'read'));
        $this->assertTrue($this->perm->can(self::ITEM, $sameId, 'read'));
        $this->assertNull($this->perm->find(self::ITEM, $this->group));

        // And the other way round: the group's grant is not that user's.
        $this->perm->revoke(self::ITEM, $sameId);
        $this->perm->grant(self::ITEM, $this->group, true, false, false, $this->member);

        $this->assertFalse($this->row(self::ITEM, $this->groupId, 0));
        $this->assertNotFalse($this->row(self::ITEM, 0, $this->groupId));
        $this->assertFalse($this->perm->can(self::ITEM, $sameId, 'read'));
        $this->assertTrue($this->perm->can(self::ITEM, $this->member, 'read'));
    }

    public function testRevokingOneHolderLeavesTheOtherWithTheSameIdAlone(): void
    {
        $sameId = new User(['id' => $this->groupId], true);
        $this->perm->grant(self::ITEM, $sameId, true, false, false, $this->member);
        $this->perm->grant(self::ITEM, $this->group, true, false, false, $this->member);

        $this->perm->revoke(self::ITEM, $this->group);

        $this->assertNotFalse($this->row(self::ITEM, $this->groupId, 0));
        $this->assertFalse($this->row(self::ITEM, 0, $this->groupId));

        $this->perm->grant(self::ITEM, $this->group, true, false, false, $this->member);
        Permission::forgetHolder($sameId);

        $this->assertFalse($this->row(self::ITEM, $this->groupId, 0));
        $this->assertNotFalse($this->row(self::ITEM, 0, $this->groupId));
    }

    public function testAUserGrantIsARowInTheUserColumn(): void
    {
        $this->perm->grant(self::ITEM, $this->outsiderUser, true, true, false, $this->member);

        $row = $this->row(self::ITEM, $this->outsider, 0);
        $this->assertSame([1, 1, 0], [$row['can_read'], $row['can_update'], $row['can_delete']]);
        $this->assertSame($this->member, $row['granted_by']);
    }

    public function testRevokingAUserGrant(): void
    {
        $this->perm->grant(self::ITEM, $this->outsiderUser, true, true, true, $this->member);
        $this->perm->grant(self::ITEM, $this->memberUser, true, false, false, $this->member);

        $this->assertTrue($this->perm->revoke(self::ITEM, $this->outsiderUser));

        $this->assertFalse($this->perm->can(self::ITEM, $this->outsider, 'read'));
        $this->assertFalse($this->row(self::ITEM, $this->outsider, 0));
        $this->assertNotFalse($this->row(self::ITEM, $this->member, 0));
    }

    public function testGrantReadsOnlyByDefault(): void
    {
        $this->perm->grant(self::ITEM, $this->group);
        $this->perm->grant(self::ITEM, $this->outsiderUser);

        foreach ([$this->row(self::ITEM, 0, $this->groupId), $this->row(self::ITEM, $this->outsider, 0)] as $row) {
            $this->assertSame([1, 0, 0], [$row['can_read'], $row['can_update'], $row['can_delete']]);
            $this->assertNull($row['granted_by'], 'Nobody is logged in.');
        }
    }

    public function testAGrantWithNobodyNamedIsRecordedAsTheLoggedInUsers(): void
    {
        $this->loginUser(['user_id' => $this->member]);

        $this->perm->grant(self::ITEM, $this->group);

        $this->assertSame($this->member, $this->row(self::ITEM, 0, $this->groupId)['granted_by']);
    }

    public function testAHolderWithNoIdIsRefused(): void
    {
        foreach ([new User(['id' => 0], true), new Group(['group_name' => 'zerotest-perm-unsaved'])] as $holder) {
            try {
                $this->perm->grant(self::ITEM, $holder);
                $this->fail('A grant needs a holder with an id');
            } catch (\InvalidArgumentException $e) {
                $this->addToAssertionCount(1);
            }
            try {
                $this->perm->revoke(self::ITEM, $holder);
                $this->fail('A revoke needs a holder with an id');
            } catch (\InvalidArgumentException $e) {
                $this->addToAssertionCount(1);
            }
        }

        $stmt = Database::getConnection()->query("SELECT COUNT(*) FROM permission WHERE permission_key = 'zerotest.thing'");
        $this->assertSame(0, (int) $stmt->fetchColumn());
    }

    public function testChecksTakeAUserAsWellAsAnId(): void
    {
        $this->perm->grant(self::ITEM, $this->group, true, true, false, $this->member);
        $this->perm->grant(0, $this->outsiderUser, true, false, false, $this->member);

        $this->assertTrue($this->perm->can(self::ITEM, $this->memberUser, 'update'));
        $this->assertFalse($this->perm->can(self::ITEM, $this->memberUser, 'delete'));
        $this->assertSame([self::ITEM], $this->perm->ids($this->memberUser));
        $this->assertSame([], $this->perm->ids($this->outsiderUser));
        $this->assertTrue($this->perm->canAll($this->outsiderUser));
        $this->assertFalse($this->perm->canAll($this->memberUser));
        $this->assertSame([], $this->perm->availableGroups(self::ITEM, $this->memberUser));

        [$sql, $params] = $this->perm->sqlCan('t.id', $this->memberUser);
        $this->assertSame([$sql, $params], $this->perm->sqlCan('t.id', $this->member));
    }

    public function testAConnectionCanBeGivenInPlaceOfTheFrameworks(): void
    {
        $this->perm->grant(self::ITEM, $this->group, true, false, false, $this->member);

        $down = $this->createStub(\PDO::class);
        $down->method('prepare')->willThrowException(new \PDOException('DB down'));

        $this->assertTrue($this->perm->can(self::ITEM, $this->member));
        $this->assertFalse((new Permission('zerotest', 'thing', $down))->can(self::ITEM, $this->member),
            'The check runs on the connection it was given, and a failed check denies.');
    }

    public function testRightsAddUpAcrossAUsersOwnRowAndTheirGroups(): void
    {
        $this->perm->grant(self::ITEM, $this->group, true, false, false, $this->member);
        $this->perm->grant(self::ITEM, $this->memberUser, false, true, false, $this->member);

        $this->assertTrue($this->perm->can(self::ITEM, $this->member, 'read'));
        $this->assertTrue($this->perm->can(self::ITEM, $this->member, 'update'));
        $this->assertFalse($this->perm->can(self::ITEM, $this->member, 'delete'));
    }

    public function testRevokingAUsersOwnGrantLeavesGroupRightsAlone(): void
    {
        $this->perm->grant(self::ITEM, $this->group, true, false, false, $this->member);
        $this->perm->grant(self::ITEM, $this->memberUser, true, true, false, $this->member);

        $this->perm->revoke(self::ITEM, $this->memberUser);

        $this->assertTrue($this->perm->can(self::ITEM, $this->member, 'read'));
        $this->assertFalse($this->perm->can(self::ITEM, $this->member, 'update'));
    }

    /* ── every item (entity 0) ── */

    public function testAGrantOnEntityZeroCoversEveryItem(): void
    {
        $this->assertFalse($this->perm->canAll($this->member, 'read'));

        $this->perm->grant(0, $this->group, true, false, false, $this->member);

        $this->assertTrue($this->perm->canAll($this->member, 'read'));
        $this->assertFalse($this->perm->canAll($this->member, 'update'));
        $this->assertTrue($this->perm->can(self::ITEM, $this->member, 'read'));
        $this->assertTrue($this->perm->can(77, $this->member, 'read'));
        $this->assertFalse($this->perm->can(self::ITEM, $this->outsider, 'read'));
        // ids() names items granted one by one; it does not expand "every item".
        $this->assertSame([], $this->perm->ids($this->member));
    }

    /* ── keys keep modules apart ── */

    public function testAnotherKeysGrantsDoNotLeak(): void
    {
        $other = new Permission('zerotest', 'other');
        $other->grant(self::ITEM, $this->group, true, true, true, $this->member);

        $this->assertFalse($this->perm->can(self::ITEM, $this->member, 'read'));
        $this->assertSame([], $this->perm->groups(self::ITEM));

        $this->perm->forget(self::ITEM);
        $this->assertTrue($other->can(self::ITEM, $this->member, 'read'));
    }

    /* ── listings ── */

    public function testIdsListsTheItemsAUserCanActOn(): void
    {
        $this->perm->grant(10, $this->group, true, false, false, $this->member);
        $this->perm->grant(11, $this->group, true, true, false, $this->member);
        $this->perm->grant(12, $this->memberUser, true, false, false, $this->member);
        $this->perm->grant(13, $this->outsiderUser, true, false, false, $this->member);

        $this->assertSame([10, 11, 12], $this->perm->ids($this->member));
        $this->assertSame([11], $this->perm->ids($this->member, 'update'));
        $this->assertSame([13], $this->perm->ids($this->outsider));
    }

    public function testSqlCanFiltersAModulesOwnQuery(): void
    {
        $this->perm->grant(10, $this->group, true, false, false, $this->member);
        $this->perm->grant(12, $this->memberUser, true, true, false, $this->member);

        [$canRead, $params] = $this->perm->sqlCan('t.id', $this->member);
        $stmt = Database::getConnection()->prepare(
            "SELECT t.id FROM (SELECT 10 AS id UNION SELECT 11 UNION SELECT 12) t WHERE $canRead ORDER BY t.id"
        );
        $stmt->execute($params);
        $this->assertSame([10, 12], array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN)));

        [$canUpdate, $params] = $this->perm->sqlCan('t.id', $this->member, 'update');
        $stmt = Database::getConnection()->prepare(
            "SELECT t.id FROM (SELECT 10 AS id UNION SELECT 11 UNION SELECT 12) t WHERE $canUpdate"
        );
        $stmt->execute($params);
        $this->assertSame([12], array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN)));
    }

    public function testSqlCanRefusesAColumnThatIsNotAnIdentifier(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->perm->sqlCan('l.list_id) OR 1=1 --', $this->member);
    }

    /* ── the sharing form ── */

    public function testGroupsFindAndAvailableGroups(): void
    {
        $spareGroup = Group::create(['group_name' => 'zerotest-perm-spare', 'created_by' => $this->member]);
        $spareGroup->add($this->member, 'member', $this->member);
        $spare = $spareGroup->group_id;

        $this->assertEqualsCanonicalizing(
            [$this->groupId, $spare],
            array_column($this->perm->availableGroups(self::ITEM, $this->member), 'group_id')
        );

        $this->perm->grant(self::ITEM, $this->group, true, true, false, $this->member);

        $shared = $this->perm->groups(self::ITEM);
        $this->assertCount(1, $shared);
        $this->assertSame($this->groupId, $shared[0]['group_id']);
        $this->assertSame('zerotest-perm-group', $shared[0]['group_name']);
        $this->assertSame(1, $shared[0]['member_count']);
        $this->assertSame([1, 1, 0], [$shared[0]['can_read'], $shared[0]['can_update'], $shared[0]['can_delete']]);

        $this->assertSame('zerotest-perm-group', $this->perm->find(self::ITEM, $this->groupId)['group_name']);
        $this->assertSame('zerotest-perm-group', $this->perm->find(self::ITEM, $this->group)['group_name']);
        $this->assertNull($this->perm->find(self::ITEM, $spare));
        $this->assertNull($this->perm->find(self::ITEM, $spareGroup));

        $this->assertSame([$spare], array_column($this->perm->availableGroups(self::ITEM, $this->member), 'group_id'));
        $this->assertSame([], $this->perm->availableGroups(self::ITEM, $this->outsider));
    }

    public function testUsersListsGroupMembersAndDirectGrants(): void
    {
        $this->perm->grant(self::ITEM, $this->group, true, false, false, $this->member);
        $this->perm->grant(self::ITEM, $this->outsiderUser, true, true, false, $this->member);

        $users = $this->perm->users(self::ITEM);

        $this->assertSame([$this->member, $this->outsider], array_column($users, 'user_id'));
        $this->assertSame(['zerotest-perm-group', null], array_column($users, 'group_name'));
        $this->assertSame([0, 1], array_column($users, 'can_update'));
    }

    public function testBatchGrant(): void
    {
        $result = $this->perm->batchGrant(self::ITEM, [
            $this->groupId => ['can_read' => true, 'can_update' => true],
        ]);

        $this->assertSame([$this->groupId], $result['success']);
        $this->assertSame([], $result['failed']);
        $this->assertTrue($this->perm->can(self::ITEM, $this->member, 'update'));
        $this->assertFalse($this->perm->can(self::ITEM, $this->member, 'delete'));
    }

    public function testBatchGrantReportsAnUnknownGroupAsFailed(): void
    {
        // A group id that is certainly not in use: one that was just deleted.
        $gone = Group::create(['group_name' => 'zerotest-perm-gone', 'created_by' => $this->member]);
        $goneId = $gone->group_id;
        $gone->delete();

        $result = $this->perm->batchGrant(self::ITEM, [
            $goneId        => ['can_read' => true],
            $this->groupId => ['can_read' => true],
        ]);

        $this->assertSame([$this->groupId], $result['success']);
        $this->assertSame([['group_id' => $goneId, 'error' => 'Group not found']], $result['failed']);
        $this->assertFalse($this->row(self::ITEM, 0, $goneId), 'Nothing is granted to a group that does not exist.');
        $this->assertTrue($this->perm->can(self::ITEM, $this->member, 'read'));
    }

    public function testBatchGrantReportsAGroupTheLoggedInUserIsNotInAsFailed(): void
    {
        $mine = Group::create(['group_name' => 'zerotest-perm-mine', 'created_by' => $this->outsider]);
        $mine->add($this->outsider, 'member', $this->outsider);
        $this->loginUser(['user_id' => $this->outsider]);

        $result = $this->perm->batchGrant(self::ITEM, [
            $this->groupId   => ['can_read' => true],
            $mine->group_id  => ['can_read' => true],
        ]);

        $this->assertSame([$mine->group_id], $result['success']);
        $this->assertSame(
            [['group_id' => $this->groupId, 'error' => 'You can only share with a group you belong to']],
            $result['failed']
        );
        $this->assertFalse($this->row(self::ITEM, 0, $this->groupId));
        $this->assertNotFalse($this->row(self::ITEM, 0, $mine->group_id));
    }

    public function testBatchGrantReadsOnlyByDefault(): void
    {
        $this->perm->batchGrant(self::ITEM, [$this->groupId => []]);

        $row = $this->row(self::ITEM, 0, $this->groupId);
        $this->assertSame([1, 0, 0], [$row['can_read'], $row['can_update'], $row['can_delete']]);
    }

    public function testDeletingAGroupRemovesItsGrantsUnderEveryKey(): void
    {
        $other = new Permission('zerotest', 'other');
        $this->perm->grant(self::ITEM, $this->group, true, false, false, $this->member);
        $other->grant(7, $this->group, true, false, false, $this->member);
        $this->perm->grant(self::ITEM, $this->outsiderUser, true, false, false, $this->member);

        $this->group->delete();

        $this->assertFalse($this->row(self::ITEM, 0, $this->groupId));
        $this->assertNull($other->find(7, $this->groupId));
        $this->assertNotFalse($this->row(self::ITEM, $this->outsider, 0));
    }

    public function testForgetHolderRemovesOnlyThatUsersOwnGrants(): void
    {
        $this->perm->grant(self::ITEM, $this->group, true, false, false, $this->member);
        $this->perm->grant(self::ITEM, $this->memberUser, true, true, false, $this->member);
        $this->perm->grant(self::ITEM, $this->outsiderUser, true, false, false, $this->member);

        Permission::forgetHolder($this->memberUser);

        $this->assertFalse($this->row(self::ITEM, $this->member, 0));
        $this->assertNotFalse($this->row(self::ITEM, $this->outsider, 0));
        $this->assertNotFalse($this->row(self::ITEM, 0, $this->groupId));
    }

    public function testForgetHolderRemovesAGroupsGrantsUnderEveryKey(): void
    {
        $other = new Permission('zerotest', 'other');
        $spare = Group::create(['group_name' => 'zerotest-perm-spare', 'created_by' => $this->member]);
        $spare->add($this->member, 'member', $this->member);
        $this->perm->grant(self::ITEM, $this->group, true, false, false, $this->member);
        $other->grant(7, $this->group, true, true, false, $this->member);
        $this->group->grant('allow.zerotest', $this->member);
        $this->perm->grant(self::ITEM, $spare, true, false, false, $this->member);
        $this->perm->grant(self::ITEM, $this->memberUser, true, false, false, $this->member);

        Permission::forgetHolder($this->group);

        $this->assertFalse($this->row(self::ITEM, 0, $this->groupId));
        $this->assertNull($other->find(7, $this->group));
        $this->assertSame([], Group::find($this->groupId)->capabilities());
        $this->assertNotFalse($this->row(self::ITEM, 0, $spare->group_id));
        $this->assertNotFalse($this->row(self::ITEM, $this->member, 0));
        // Only the grants go: the group and its members stay.
        $this->assertTrue(Group::find($this->groupId)->has($this->member));
    }

    public function testForgetHolderRefusesAHolderWithIdZero(): void
    {
        $this->perm->grant(self::ITEM, $this->group, true, false, false, $this->member);
        $this->perm->grant(self::ITEM, $this->outsiderUser, true, false, false, $this->member);

        // user_id 0 would match every group row, and group_id 0 every user row.
        foreach ([new User(['id' => 0], true), new Group(['group_name' => 'zerotest-perm-unsaved'])] as $holder) {
            try {
                Permission::forgetHolder($holder);
                $this->fail('forgetHolder() must refuse a holder with id 0');
            } catch (\InvalidArgumentException $e) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertNotFalse($this->row(self::ITEM, 0, $this->groupId));
        $this->assertNotFalse($this->row(self::ITEM, $this->outsider, 0));
    }

    public function testForgetHolderRefusesANegativeId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Permission::forgetHolder(new User(['id' => -5], true));
    }

    public function testForgetRemovesEveryGrantOnTheItem(): void
    {
        $this->perm->grant(self::ITEM, $this->group, true, false, false, $this->member);
        $this->perm->grant(self::ITEM, $this->outsiderUser, true, false, false, $this->member);
        $this->perm->grant(self::ITEM + 1, $this->group, true, false, false, $this->member);

        $this->perm->forget(self::ITEM);

        $this->assertFalse($this->perm->can(self::ITEM, $this->member, 'read'));
        $this->assertFalse($this->perm->can(self::ITEM, $this->outsider, 'read'));
        $this->assertTrue($this->perm->can(self::ITEM + 1, $this->member, 'read'));
    }
}
