<?php
namespace Zero\Tests\Core;

use PHPUnit\Framework\Attributes\DataProvider;
use Zero\Core\User;
use Zero\Core\Database;

class UserPermissionTest extends ZeroTestCase
{
    private const E = 'zerotest-perm@example.invalid';
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
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([self::E]);
        foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $id) {
            $db->prepare("DELETE FROM user_settings WHERE user_id = ?")->execute([$id]);
        }
        $db->prepare("DELETE FROM users WHERE email = ?")->execute([self::E]);
        $_SESSION = [];
        $this->resetCurrentMemo();
    }

    /**
     * testHasPermissionDelegatesToTheCurrentUser() populates User::$current
     * via establish(). Without clearing it here, that memo survives into
     * testHasPermissionDeniesWithNoSession() (declaration order, same
     * process) and hasPermission() answers for the stale cached instance
     * instead of the empty session. Same convention as
     * core/tests/UserCurrentTest.php::resetCurrentMemo() — reflection, not a
     * public reset method, because nothing outside tests should ever need to
     * clear it.
     */
    private function resetCurrentMemo(): void
    {
        $prop = new \ReflectionProperty(User::class, 'current');
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

    public static function grantValues(): array
    {
        return [
            'string one'  => ['1',     true],
            'int one'     => [1,       true],
            'string zero' => ['0',     false],
            'empty'       => ['',      false],
            'false word'  => ['false', false],
            'off'         => ['off',   false],
            'no'          => ['no',    false],
            'arbitrary'   => ['yes',   false],
        ];
    }

    #[DataProvider('grantValues')]
    public function testGrantSemanticsAreStrict($stored, bool $expected): void
    {
        $this->set('allow.zerotest', $stored);
        $this->assertSame($expected, User::find($this->userId)->can('allow.zerotest'),
            'Only 1/true grants; everything else denies.');
    }

    public function testUnsetPermissionDenies(): void
    {
        $this->assertFalse(User::find($this->userId)->can('never.set'));
    }

    public function testCanAnswersForAUserWhoIsNotCurrent(): void
    {
        $this->set('allow.zerotest', '1');
        $_SESSION = [];
        $this->assertTrue(User::find($this->userId)->can('allow.zerotest'),
            'can() must not depend on there being a session.');
    }

    public function testRevocationTakesEffectWithoutReLogin(): void
    {
        $this->set('allow.zerotest', '1');
        $u = User::find($this->userId);
        $this->assertTrue($u->can('allow.zerotest'));

        $this->set('allow.zerotest', '0');

        // A NEW instance models the next request. The memo is per-instance and
        // per-request; it must not survive into one.
        $this->assertFalse(User::find($this->userId)->can('allow.zerotest'),
            'A revoked permission must deny on the next request, with no re-login.');
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
        $this->assertTrue(User::find($this->userId)->setSetting('allow.zerotest', '1', $actor));

        $stmt = Database::getConnection()->prepare(
            "SELECT setting_value, updated_by FROM user_settings WHERE user_id = ? AND setting_key = ?"
        );
        $stmt->execute([$this->userId, 'allow.zerotest']);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        $this->assertSame('1', $row['setting_value']);
        $this->assertSame($actor, (int) $row['updated_by']);
    }

    public function testHasPermissionDelegatesToTheCurrentUser(): void
    {
        $this->set('allow.zerotest', '1');
        User::establish(User::find($this->userId));
        $this->assertTrue(User::hasPermission('allow.zerotest'));
    }

    public function testHasPermissionDeniesWithNoSession(): void
    {
        $_SESSION = [];
        $this->assertFalse(User::hasPermission('allow.zerotest'),
            'No session means no grant — never a fatal.');
    }

    public function testEstablishNoLongerCachesSettingsInTheSession(): void
    {
        $this->set('allow.zerotest', '1');
        User::establish(User::find($this->userId));

        $this->assertArrayNotHasKey('user_settings', $_SESSION,
            'The session cache is the staleness source and must be gone.');
        $this->assertTrue(User::hasPermission('allow.zerotest'),
            'and permissions must still work without it.');
    }

    /**
     * The \Throwable catch in settings() (core/User.php) is the single most
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
     * setSetting()'s docblock (core/User.php:299) promises it drops the memo
     * so a read later in the same request sees the write. Nothing checked
     * that promise before this test — testRevocationTakesEffectWithoutReLogin()
     * above covers the DB actually changing, but goes through a fresh
     * User::find() instance each time, which would pass even if the memo
     * were never dropped. This test reuses the SAME instance across the
     * write, so it would fail if setSetting() stopped clearing $this->settings.
     */
    public function testSetSettingDropsTheMemo(): void
    {
        $this->set('allow.zerotest', '1');
        $user = User::find($this->userId);
        $this->assertTrue($user->can('allow.zerotest'), 'sanity check: memoizes settings()');

        $user->setSetting('allow.zerotest', '0');

        $this->assertFalse($user->can('allow.zerotest'),
            'setSetting() must drop the memo — a stale memo would still read the pre-write value here.');
    }
}
