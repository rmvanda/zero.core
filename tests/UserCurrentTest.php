<?php
namespace Zero\Tests\Core;

use Zero\Core\User;
use Zero\Core\Database;

class UserCurrentTest extends ZeroTestCase
{
    private const E = 'zerotest-current@example.invalid';
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connectTestDatabase();
        $this->cleanup();
        $u = User::create(['name' => 'Current Probe', 'email' => self::E, 'verified' => 1]);
        $this->userId = (int) $u->id;
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    private function cleanup(): void
    {
        Database::getConnection()
            ->prepare("DELETE FROM users WHERE email = ?")->execute([self::E]);
        $_SESSION = [];
        $this->resetCurrentMemo();
    }

    /**
     * The fallback tests below set $_SESSION directly rather than going
     * through establish() (which clears the memo itself), so without this a
     * User::current() memoized by an earlier test could leak into one of
     * them depending on execution order. Reflection, not a public reset
     * method, because nothing outside tests should ever need to clear it.
     */
    private function resetCurrentMemo(): void
    {
        $prop = new \ReflectionProperty(User::class, 'current');
        $prop->setAccessible(true);
        $prop->setValue(null, null);
    }

    public function testCurrentIsNullWithNoSession(): void
    {
        $_SESSION = [];
        $this->assertNull(User::current());
        $this->assertNull(User::currentId());
    }

    public function testCurrentExposesEveryMappedColumn(): void
    {
        User::establish(User::find($this->userId));
        $u = User::current();

        $this->assertNotNull($u);
        $this->assertSame($this->userId, (int) $u->id);
        $this->assertSame('Current Probe', $u->name);
        $this->assertSame(self::E, $u->email);
        $this->assertSame(1, (int) $u->verified);
        $this->assertNull($u->pic);
    }

    public function testCurrentAgreesWithFind(): void
    {
        User::establish(User::find($this->userId));
        foreach (['id', 'name', 'email', 'verified', 'pic'] as $col) {
            $this->assertSame(
                User::find($this->userId)->{$col},
                User::current()->{$col},
                "current() and find() disagree on {$col}"
            );
        }
    }

    public function testCurrentIsMemoizedAndDoesNotQuery(): void
    {
        User::establish(User::find($this->userId));
        $this->assertSame(User::current(), User::current(),
            'current() must return the same memoized instance within a request.');
    }

    public function testSaveOnASessionHydratedUserWritesOnlyTheChangedColumn(): void
    {
        User::establish(User::find($this->userId));

        $u = User::current();
        $u->name = 'Renamed Probe';
        $u->save();

        $fresh = User::find($this->userId);
        $this->assertSame('Renamed Probe', $fresh->name);
        $this->assertSame(self::E, $fresh->email, 'email must not have been rewritten');
        $this->assertSame(1, (int) $fresh->verified, 'verified must not have been rewritten');
    }

    /*
     * Sessions built before establish() existed (or by any other hand-rolled
     * path) may carry only $_SESSION['user'], with none of the flat keys
     * establish() itself always writes alongside it. currentId() and
     * current() must still recognise those sessions as logged in — see the
     * rationale on currentId()'s docblock.
     */

    public function testCurrentIdFallsBackToUserArrayWhenFlatKeyIsAbsent(): void
    {
        $_SESSION = ['user' => ['id' => $this->userId]];
        $this->assertSame($this->userId, User::currentId());
    }

    public function testCurrentFallsBackToUserArrayWhenFlatKeysAreAbsent(): void
    {
        $_SESSION = ['user' => [
            'id'        => $this->userId,
            'full_name' => 'Array-Only Probe',
            'email'     => self::E,
            'pic'       => 'https://x/y.jpg',
        ]];

        $u = User::current();

        $this->assertNotNull($u, "a session carrying only \$_SESSION['user'] must still yield a current user");
        $this->assertSame($this->userId, (int) $u->id);
        $this->assertSame('Array-Only Probe', $u->name, "current() must read the array's full_name key, not name");
        $this->assertSame(self::E, $u->email);
        $this->assertSame('https://x/y.jpg', $u->pic);
    }
}
