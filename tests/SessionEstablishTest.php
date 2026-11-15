<?php
namespace Zero\Tests\Core;

use Zero\Core\Database;
use Zero\Core\Session;
use Zero\Core\User as UserRow;       // the row

/**
 * Zero\Core\Session::establish() is the single writer of the login session. All
 * three sign-in paths call it — OAuth, magic-link, and API-token auth — so a
 * session must be indistinguishable regardless of how it was created.
 */
class SessionEstablishTest extends ZeroTestCase
{
    private const E = 'zerotest-establish@example.invalid';
    private ?UserRow $row = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connectTestDatabase();
        $this->cleanup();
        $this->row = UserRow::create([
            'name' => 'Establish Probe', 'email' => self::E, 'verified' => 1, 'pic' => 'https://x/y.jpg',
        ]);
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    private function cleanup(): void
    {
        $db = Database::getConnection();
        $db->prepare("DELETE FROM user_settings WHERE user_id IN (SELECT id FROM users WHERE email = ?)")
           ->execute([self::E]);
        $db->prepare("DELETE FROM users WHERE email = ?")->execute([self::E]);
    }

    /*
     * end() is establish()'s counterpart: the one place a session is torn
     * down, so it is pinned here beside the writer it undoes.
     *
     * The cookie half is not asserted: setcookie() needs headers that PHPUnit
     * has already sent, so it cannot run under test. Verify by hand that
     * /auth/logout returns a Set-Cookie expiring the session cookie.
     */

    public function testEndClearsTheSessionContract(): void
    {
        Session::establish($this->row);
        $this->assertTrue(Session::isLoggedIn(), 'precondition: established');

        Session::end();

        $this->assertSame([], $_SESSION);
        $this->assertFalse(Session::isLoggedIn());
        $this->assertNull(Session::userId());
    }

    public function testEndDropsTheUserMemo(): void
    {
        Session::establish($this->row);
        $this->assertNotNull(Session::user(), 'precondition: memoized');

        Session::end();

        // Left set, anything calling Session::user() later in the same
        // request would still see the user who had just left.
        $this->assertNull(Session::user());
    }

    public function testEndIsSafeWithNoActiveSession(): void
    {
        // CLI and test contexts have no session. end() must clear what it
        // can and return before the calls that would warn.
        $_SESSION = ['user_id' => 1];

        Session::end();

        $this->assertSame([], $_SESSION);
    }

    public function testSetsEveryKeyInTheSessionContract(): void
    {
        Session::establish($this->row);

        foreach (['user_id','email','verified','name','pic','user','created_at'] as $key) {
            $this->assertArrayHasKey($key, $_SESSION, "missing \$_SESSION['$key']");
        }
        $this->assertSame($this->row->id, $_SESSION['user_id']);
        $this->assertSame(self::E, $_SESSION['email']);
        $this->assertSame(1, $_SESSION['verified']);
    }

    public function testResultingSessionIsLoggedIn(): void
    {
        Session::establish($this->row);
        $this->assertTrue(Session::isLoggedIn());
        $this->assertSame($this->row->id, Session::userId());
    }

    public function testUnverifiedUserDoesNotProduceALoggedInSession(): void
    {
        $this->row->verified = 0;
        $this->row->save();

        Session::establish(UserRow::find($this->row->id));

        // The contract keys are still written, but isLoggedIn() must be false —
        // this is what keeps a registered-but-unverified account inert.
        $this->assertFalse(Session::isLoggedIn());
    }

    public function testIdentityOverrideIsUsedForTheUserKey(): void
    {
        // Auth::complete() has richer provider data than the row carries, so it
        // passes the normalized provider identity through.
        Session::establish($this->row, [
            'full_name' => 'Provider Name', 'email' => self::E, 'verified' => true,
            'pic' => 'https://provider/pic', 'id' => 'provider-123',
        ]);

        $this->assertSame('Provider Name', $_SESSION['user']['full_name']);

        // ...but the id is OURS, not the provider's. Every adapter's format()
        // puts the provider's user id under 'id'; leaving it there made the key
        // mean a local users.id on one branch of establish() and a foreign one
        // on the other.
        $this->assertSame($this->row->id, $_SESSION['user']['id'],
            "establish() must overwrite the provider's id with the local one");
    }

    public function testWithoutIdentityTheUserKeyIsBuiltFromTheRow(): void
    {
        Session::establish($this->row);

        $this->assertSame('Establish Probe', $_SESSION['user']['full_name']);
        $this->assertSame(self::E, $_SESSION['user']['email']);
        $this->assertSame($this->row->id, $_SESSION['user']['id']);
    }

    /*
     * establish() does not cache user_settings or auth.level into the
     * session: such a cache is the staleness source read-through permissions
     * exist to close. core/tests/UserPermissionTest.php covers the read-through
     * side — testAuthLevelIsAnIntegerNotABoolean(),
     * testAuthLevelDefaultsToZeroWithNoRow() — and
     * testEstablishNoLongerCachesSettingsInTheSession() there asserts the
     * session carries no such key.
     */
}
