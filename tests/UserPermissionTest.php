<?php
namespace Zero\Tests\Core;

use PHPUnit\Framework\Attributes\DataProvider;
use Zero\Core\Access\User;
use Zero\Core\Session;
use Zero\Core\Database;
use Zero\Core\Access\Group;
use Zero\Core\Access\Permission;

class UserPermissionTest extends ZeroTestCase
{
    private const E = 'zerotest-perm@example.invalid';
    private const GROUP = 'zerotest-capability-group';
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connectTestDatabase();
        $this->cleanup();
        $this->userId = (int) User::create(
            ['name' => 'Perm Probe', 'email' => self::E, 'verified' => 1]
        )->id;
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    private function cleanup(): void
    {
        // Removing the user and the group also removes what was granted to them.
        $this->removeTestRows(self::E, self::GROUP);
        Database::getConnection()
            ->prepare("DELETE FROM permission WHERE permission_key LIKE 'allow.zerotest%'")
            ->execute();
        $_SESSION = [];
        $this->resetCurrentMemo();
    }

    /**
     * testSessionCanDelegatesToTheSessionUser() populates Session::$user
     * via establish(). Without clearing it here, that memo survives into
     * testSessionCanDeniesWithNoSession() (declaration order, same
     * process) and Session::can() answers for the stale cached instance
     * instead of the empty session. Same convention as
     * core/tests/SessionUserTest.php::resetCurrentMemo() — reflection, not a
     * public reset method, because nothing outside tests should ever need to
     * clear it.
     */
    private function resetCurrentMemo(): void
    {
        $prop = new \ReflectionProperty(\Zero\Core\Session::class, 'user');
        $prop->setAccessible(true);
        $prop->setValue(null, null);
    }

    private function set(string $key, $value): void
    {
        Database::getConnection()->prepare(
            "INSERT INTO user_settings (user_id, setting_key, setting_value)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
        )->execute([$this->userId, $key, $value]);
    }

    private function grant(string $key): void
    {
        $this->assertTrue(User::find($this->userId)->grant($key, $this->userId));
    }

    public function testAGrantedCapabilityIsHeld(): void
    {
        $this->grant('allow.zerotest');
        $this->assertTrue(User::find($this->userId)->can('allow.zerotest'));
        $this->assertSame(['allow.zerotest'], User::find($this->userId)->capabilities());
    }

    public function testASettingIsNotACapability(): void
    {
        $this->set('allow.zerotest', '1');
        $this->assertFalse(User::find($this->userId)->can('allow.zerotest'),
            'Only a row in permission grants; a user_settings value does not.');
    }

    public function testUnsetPermissionDenies(): void
    {
        $this->assertFalse(User::find($this->userId)->can('never.set'));
    }

    public function testCanAnswersForAUserWhoIsNotCurrent(): void
    {
        $this->grant('allow.zerotest');
        $_SESSION = [];
        $this->assertTrue(User::find($this->userId)->can('allow.zerotest'),
            'can() must not depend on there being a session.');
    }

    public function testRevocationTakesEffectWithoutReLogin(): void
    {
        $this->grant('allow.zerotest');
        $u = User::find($this->userId);
        $this->assertTrue($u->can('allow.zerotest'));

        $this->assertTrue(User::find($this->userId)->revoke('allow.zerotest'));

        // A NEW instance models the next request. The memo is per-instance and
        // per-request; it must not survive into one.
        $this->assertFalse(User::find($this->userId)->can('allow.zerotest'),
            'A revoked permission must deny on the next request, with no re-login.');
    }

    public function testGrantRecordsTheActor(): void
    {
        $actor = 424242;
        $this->assertTrue(User::find($this->userId)->grant('allow.zerotest', $actor));

        $stmt = Database::getConnection()->prepare(
            "SELECT entity_id, can_read, can_update, can_delete, granted_by FROM permission
             WHERE permission_key = ? AND user_id = ?"
        );
        $stmt->execute(['allow.zerotest', $this->userId]);

        $this->assertSame(
            [['entity_id' => 0, 'can_read' => 1, 'can_update' => 0, 'can_delete' => 0, 'granted_by' => $actor]],
            $stmt->fetchAll(\PDO::FETCH_ASSOC)
        );
    }

    public function testGrantAndRevokeDropTheMemo(): void
    {
        $user = User::find($this->userId);
        $this->assertFalse($user->can('allow.zerotest'), 'sanity check: memoizes capabilities()');

        $user->grant('allow.zerotest');
        $this->assertTrue($user->can('allow.zerotest'));

        $user->revoke('allow.zerotest');
        $this->assertFalse($user->can('allow.zerotest'));
    }

    public function testGrantRefusesAKeyThatIsNotACapabilityKey(): void
    {
        $this->assertFalse(User::find($this->userId)->grant('Not A Key'));
        $this->assertFalse(User::find($this->userId)->grant('nodot'));
        $this->assertFalse(User::find($this->userId)->grant('lists.list'),
            'A capability key starts with allow.');
    }

    public function testAnActiveGroupGrantsItsCapabilityToItsMembers(): void
    {
        $group = Group::create(['group_name' => self::GROUP, 'created_by' => $this->userId]);
        $group->add($this->userId, 'member', $this->userId);
        Permission::capability('allow.zerotest')->grant(0, $group, true, false, false, $this->userId);

        $this->assertTrue(User::find($this->userId)->can('allow.zerotest'));
        $this->assertSame(['allow.zerotest'], Permission::capabilities(User::find($this->userId)));
        $this->assertSame([], Permission::capabilities(User::find($this->userId), true),
            'The user holds it through the group, not directly.');
        $this->assertSame(['allow.zerotest'], Permission::capabilities($group));

        // The group's row has user_id 0. A User built on id 0 must not read it.
        $this->assertFalse((new User(['id' => 0], true))->can('allow.zerotest'));

        $group->deactivate();
        $this->assertFalse(User::find($this->userId)->can('allow.zerotest'),
            'A deactivated group grants nothing.');
    }

    public function testRevokingAUsersOwnCapabilityLeavesTheGroupsAlone(): void
    {
        $group = Group::create(['group_name' => self::GROUP, 'created_by' => $this->userId]);
        $group->add($this->userId, 'member', $this->userId);
        $group->grant('allow.zerotest', $this->userId);
        $this->grant('allow.zerotest');

        $this->assertTrue(User::find($this->userId)->revoke('allow.zerotest'));

        $this->assertTrue(User::find($this->userId)->can('allow.zerotest'),
            'A capability held through a group stays until the group loses it.');
        $this->assertSame([], Permission::capabilities(User::find($this->userId), true));

        $this->assertTrue($group->revoke('allow.zerotest'));
        $this->assertFalse(User::find($this->userId)->can('allow.zerotest'));
    }

    public function testACapabilityObjectGrantsRevokesAndChecksOnEntityZero(): void
    {
        $user = User::find($this->userId);
        $cap  = Permission::capability('allow.zerotest');
        $this->assertSame('allow.zerotest', $cap->permissionKey());
        $this->assertFalse($cap->canAll($user));

        $cap->grant(0, $user, true, false, false, $this->userId);

        $this->assertTrue($cap->canAll($this->userId));
        $this->assertTrue(User::find($this->userId)->can('allow.zerotest'));

        $cap->revoke(0, $user);

        $this->assertFalse($cap->canAll($user));
        $this->assertFalse(User::find($this->userId)->can('allow.zerotest'));
    }

    public function testCapabilityRefusesAKeyThatIsNotACapabilityKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Permission::capability('lists.list');
    }

    public function testCapabilityKeysInUseListsEveryKeyAnyHolderHas(): void
    {
        $this->assertNotContains('allow.zerotest', Permission::capabilityKeysInUse());
        $this->assertNotContains('allow.zerotest.group', Permission::capabilityKeysInUse());

        $group = Group::create(['group_name' => self::GROUP, 'created_by' => $this->userId]);
        $group->add($this->userId, 'member', $this->userId);
        $this->assertTrue($group->grant('allow.zerotest.group', $this->userId));
        $this->grant('allow.zerotest');

        $keys = Permission::capabilityKeysInUse();
        $this->assertContains('allow.zerotest', $keys);
        $this->assertContains('allow.zerotest.group', $keys);
    }

    public function testAuthLevelIsAnIntegerNotABoolean(): void
    {
        $this->set('auth.level', '2');
        $this->assertSame(2, User::find($this->userId)->authLevel(),
            'auth.level is a level, not a grant — a strict can() check would deny it.');
    }

    public function testAuthLevelDefaultsToZeroWithNoRow(): void
    {
        $this->assertSame(0, User::find($this->userId)->authLevel(),
            'No auth.level row must read as level 0, not null or a fatal.');
    }

    public function testSetSettingRecordsTheActor(): void
    {
        $actor = 424242;
        $this->assertTrue(User::find($this->userId)->setSetting('zerotest.setting', '1', $actor));

        $stmt = Database::getConnection()->prepare(
            "SELECT setting_value, updated_by FROM user_settings WHERE user_id = ? AND setting_key = ?"
        );
        $stmt->execute([$this->userId, 'zerotest.setting']);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        $this->assertSame('1', $row['setting_value']);
        $this->assertSame($actor, (int) $row['updated_by']);
    }

    public function testSessionCanDelegatesToTheSessionUser(): void
    {
        $this->grant('allow.zerotest');
        Session::establish(User::find($this->userId));
        $this->assertTrue(Session::can('allow.zerotest'));
    }

    public function testSessionCanDeniesWithNoSession(): void
    {
        $_SESSION = [];
        $this->assertFalse(Session::can('allow.zerotest'),
            'No session means no grant — never a fatal.');
    }

    public function testSessionCanDeniesAKeyTheSessionUserDoesNotHold(): void
    {
        $this->grant('allow.zerotest');
        Session::establish(User::find($this->userId));

        $this->assertFalse(Session::can('allow.zerotest.other'));
    }

    public function testSessionCanCountsWhatAnActiveGroupGrants(): void
    {
        $group = Group::create(['group_name' => self::GROUP, 'created_by' => $this->userId]);
        $group->add($this->userId, 'member', $this->userId);
        $group->grant('allow.zerotest', $this->userId);

        Session::establish(User::find($this->userId));

        $this->assertTrue(Session::can('allow.zerotest'));
    }

    public function testSessionCanAnswersForTheNewUserAfterEstablish(): void
    {
        $this->grant('allow.zerotest');
        Session::establish(User::find($this->userId));
        $this->assertTrue(Session::can('allow.zerotest'));

        // A second sign-in in the same request: an account that holds nothing.
        Session::establish(new User(['id' => 999000078, 'name' => 'Nobody', 'email' => 'nobody@example.invalid', 'verified' => 1], true));

        $this->assertSame(999000078, Session::userId());
        $this->assertFalse(Session::can('allow.zerotest'));
    }

    public function testSessionCanDeniesOnceTheSessionEnds(): void
    {
        $this->grant('allow.zerotest');
        Session::establish(User::find($this->userId));
        $this->assertTrue(Session::can('allow.zerotest'));

        Session::end();

        $this->assertFalse(Session::can('allow.zerotest'));
    }

    public function testEstablishNoLongerCachesSettingsInTheSession(): void
    {
        $this->grant('allow.zerotest');
        Session::establish(User::find($this->userId));

        $this->assertArrayNotHasKey('user_settings', $_SESSION,
            'The session cache is the staleness source and must be gone.');
        $this->assertTrue(Session::can('allow.zerotest'),
            'and permissions must still work without it.');
    }

    /**
     * The \Throwable catch in capabilities() (core/access/HoldsCapabilities.php) and settings() (core/access/User.php) is the single most
     * security-relevant line in the permission system: it is what makes a
     * database failure DENY rather than grant or fatal. Swap in a PDO whose
     * prepare() throws, bypassing Database's singleton guard directly via the
     * public static property, and restore the real connection afterward so
     * tearDown()'s cleanup() (which needs a working DB) still runs.
     */
    public function testDatabaseFailureDeniesRatherThanGrantsOrFatals(): void
    {
        $real = Database::$connection;

        $throwingPdo = $this->createStub(\PDO::class);
        $throwingPdo->method('prepare')->willThrowException(new \PDOException('DB down'));
        Database::$connection = $throwingPdo;

        try {
            $user = new User(['id' => $this->userId], true);
            $this->assertFalse($user->can('allow.zerotest'),
                'A lookup failure must deny, never grant.');

            $user2 = new User(['id' => $this->userId], true);
            $this->assertSame(0, $user2->authLevel(),
                'A lookup failure must read as level 0, not fatal or leak an old value.');
        } finally {
            Database::$connection = $real;
        }
    }

    /**
     * setSetting()'s docblock (core/access/User.php) promises it drops the memo
     * so a read later in the same request sees the write. Nothing checked
     * that promise before this test — testRevocationTakesEffectWithoutReLogin()
     * above covers the DB actually changing, but goes through a fresh
     * User::find() instance each time, which would pass even if the memo
     * were never dropped. This test reuses the SAME instance across the
     * write, so it would fail if setSetting() stopped clearing $this->settings.
     */
    public function testSetSettingDropsTheMemo(): void
    {
        $this->set('zerotest.setting', '1');
        $user = User::find($this->userId);
        $this->assertSame('1', $user->settings()['zerotest.setting'], 'sanity check: memoizes settings()');

        $user->setSetting('zerotest.setting', '0');

        $this->assertSame('0', $user->settings()['zerotest.setting'],
            'setSetting() must drop the memo — a stale memo would still read the pre-write value here.');
    }

    /*
     * Hardening guards: settings()/setSetting() and capabilities()/grant()/
     * revoke() touch user_settings and permission by id, and tests legitimately construct a
     * User directly (bypassing Session::userId()'s validation), so each guards
     * itself against a non-positive id rather than issuing the query.
     */

    /**
     * Proves the guard is a short-circuit, not merely "the query happened to
     * fail". A PDO that must never be called: if the guard were missing (or
     * were an `if ($id == 0)` that missed -5), prepare() would be invoked and
     * the mock's expectation would fail the test.
     */
    public function testSettingsGuardsNonPositiveIdWithoutQuerying(): void
    {
        $real = Database::$connection;
        $mockPdo = $this->createMock(\PDO::class);
        $mockPdo->expects($this->never())->method('prepare');
        Database::$connection = $mockPdo;

        try {
            $this->assertSame([], (new User(['id' => 0], true))->settings(),
                'id 0 must read no settings — it is not a user.');
            $this->assertSame([], (new User(['id' => 0], true))->capabilities(),
                'id 0 marks a group row in permission and must read no capabilities.');
            $this->assertSame([], (new User(['id' => -5], true))->capabilities());
            $this->assertSame([], (new User(['id' => -5], true))->settings(),
                'negative ids are meaningless and must also read no settings.');
        } finally {
            Database::$connection = $real;
        }
    }

    /** Same proof as above, for the writer. */
    public function testSetSettingGuardsNonPositiveIdWithoutQuerying(): void
    {
        $real = Database::$connection;
        $mockPdo = $this->createMock(\PDO::class);
        $mockPdo->expects($this->never())->method('prepare');
        Database::$connection = $mockPdo;

        try {
            $this->assertFalse((new User(['id' => 0], true))->setSetting('zerotest.guard', '1'),
                'a write against id 0 must be refused, not silently accepted.');
            $this->assertFalse((new User(['id' => -5], true))->setSetting('zerotest.guard', '1'),
                'negative ids must also be refused.');
            $this->assertFalse((new User(['id' => 0], true))->grant('allow.zerotest'),
                'a grant to id 0 would be a row with no holder.');
            $this->assertFalse((new User(['id' => -5], true))->revoke('allow.zerotest'));
        } finally {
            Database::$connection = $real;
        }
    }
}
