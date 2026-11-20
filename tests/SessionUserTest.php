<?php
namespace Zero\Tests\Core;

use PHPUnit\Framework\Attributes\DataProvider;
use Zero\Core\Access\User;
use Zero\Core\Session;
use Zero\Core\Database;

/**
 * Zero\Core\Session::user() and Session::userId(): who is making this
 * request, read from $_SESSION and never from a query.
 */
class SessionUserTest extends ZeroTestCase
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
     * Session::user() memoized by an earlier test could leak into one of
     * them depending on execution order. Reflection, not a public reset
     * method, because nothing outside tests should ever need to clear it.
     */
    private function resetCurrentMemo(): void
    {
        $prop = new \ReflectionProperty(\Zero\Core\Session::class, 'user');
        $prop->setAccessible(true);
        $prop->setValue(null, null);
    }

    public function testUserIsNullWithNoSession(): void
    {
        $_SESSION = [];
        $this->assertNull(Session::user());
        $this->assertNull(Session::userId());
    }

    public function testUserExposesEveryMappedColumn(): void
    {
        Session::establish(User::find($this->userId));
        $u = Session::user();

        $this->assertNotNull($u);
        $this->assertSame($this->userId, (int) $u->id);
        $this->assertSame('Current Probe', $u->name);
        $this->assertSame(self::E, $u->email);
        $this->assertSame(1, (int) $u->verified);
        $this->assertNull($u->pic);
    }

    public function testUserAgreesWithFind(): void
    {
        Session::establish(User::find($this->userId));
        foreach (['id', 'name', 'email', 'verified', 'pic'] as $col) {
            $this->assertSame(
                User::find($this->userId)->{$col},
                Session::user()->{$col},
                "Session::user() and User::find() disagree on {$col}"
            );
        }
    }

    public function testUserReturnsTheSameInstanceWithinARequest(): void
    {
        Session::establish(User::find($this->userId));
        $this->assertSame(Session::user(), Session::user(),
            'Session::user() must return the same memoized instance within a request.');
    }

    public function testSaveOnASessionHydratedUserWritesOnlyTheChangedColumn(): void
    {
        Session::establish(User::find($this->userId));
        $u = Session::user();

        // Row moves on underneath the session — the real scenario this test
        // guards against. Without this, the session instance's email/verified
        // are already identical to the row's (establish() wrote them straight
        // off the same find() result Session::user() hydrates from), so a save()
        // that wrote every column back would write the same values and this
        // test would pass either way. Proven: see the fix report.
        Database::getConnection()
            ->prepare("UPDATE users SET verified = 0 WHERE id = ?")->execute([$this->userId]);

        $u->name = 'Renamed Probe';
        $u->save();

        $fresh = User::find($this->userId);
        $this->assertSame('Renamed Probe', $fresh->name);
        $this->assertSame(0, (int) $fresh->verified,
            'a stale session value must not have been written back');
    }

    /*
     * Session::userId() reads $_SESSION['user_id'] and nothing else.
     *
     * It does not fall back to $_SESSION['user']['id']. That key holds the
     * raw provider identity, whose 'id' is the PROVIDER's user id — a foreign
     * number in the same shape as a local users.id — so a fallback could
     * answer with an id that belongs to no local account. These tests pin
     * that there is none.
     */

    public function testUserIdIgnoresTheUserArrayWhenFlatKeyIsAbsent(): void
    {
        $_SESSION = ['user' => ['id' => $this->userId]];
        $this->assertNull(Session::userId(),
            "Session::userId() must not read \$_SESSION['user']['id'] — it holds the provider's id");
    }

    public function testUserIsNullWhenOnlyTheUserArrayIsPresent(): void
    {
        $_SESSION = ['user' => [
            'id'        => $this->userId,
            'full_name' => 'Array-Only Probe',
            'email'     => self::E,
            'pic'       => 'https://x/y.jpg',
        ]];

        $this->assertNull(Session::user(),
            'a session with no user_id is not a logged-in session');
    }

    public function testUserIdIgnoresAForeignProviderIdInTheUserArray(): void
    {
        // The concrete failure a fallback would allow: a sign-in that is
        // REFUSED after $_SESSION['user'] is written leaves this shape
        // behind. 12345678 is a plausible GitHub id and passes every
        // filter_var check Session::userId() applies.
        $_SESSION = ['user' => ['id' => 12345678, 'email' => 'attacker@example.invalid']];
        $this->assertNull(Session::userId());
    }

    /*
     * Hardening: Session::userId() must reject anything that is not a positive
     * integer rather than silently coercing it. A bare (int) cast turns
     * 'abc' into 0, and 0 is not "nobody" — the permission table uses
     * user_id = 0 to mean "this row is a group's". A User built on id 0 would
     * read every group's grants through can() as if they were a real
     * user's permissions. Rejecting outright degrades to "not logged in",
     * the safe direction.
     */

    public static function badFlatSessionValues(): array
    {
        return [
            "non-numeric string 'abc'" => ['abc'],
            "numeric-zero string '0'"  => ['0'],
            'int 0'                    => [0],
            'negative int -5'          => [-5],
            'array (attacker shape)'   => [['unexpected' => 'shape']],
        ];
    }

    #[DataProvider('badFlatSessionValues')]
    public function testUserIdRejectsBadFlatSessionValues($bad): void
    {
        $_SESSION = ['user_id' => $bad];
        $this->assertNull(Session::userId());
    }

    public function testUserIdRejectsBadUserArrayId(): void
    {
        // $_SESSION['user']['id'] is not read at all, so even a value
        // that could never be an id changes nothing.
        $_SESSION = ['user' => ['id' => 'xyz']];
        $this->assertNull(Session::userId());
    }

    public function testUserIdResolvesValidFlatKey(): void
    {
        $_SESSION = ['user_id' => $this->userId];
        $this->assertSame($this->userId, Session::userId());
    }

    public function testUserIdResolvesValidFlatKeyGivenAsNumericString(): void
    {
        // PHP sessions can round-trip ids as strings depending on how they
        // were written; filter_var must still accept a clean numeric string.
        $_SESSION = ['user_id' => (string) $this->userId];
        $this->assertSame($this->userId, Session::userId());
    }

    public function testUserReturnsNullWhenSessionIdIsRejected(): void
    {
        $_SESSION = ['user_id' => 'abc'];
        $this->assertNull(Session::user(),
            'Session::user() must not build an instance from an id Session::userId() rejected.');
    }
}
