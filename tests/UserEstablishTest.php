<?php
namespace Zero\Tests\Core;

use Zero\Core\Database;
use Zero\Core\User as SessionUser;   // session reader
use Zero\Model\User as UserRow;      // the row

/**
 * Zero\Core\User::establish() is the single writer of the login session. All
 * three sign-in paths call it — OAuth, magic-link, and API-token auth — so a
 * session must be indistinguishable regardless of how it was created.
 */
class UserEstablishTest extends ZeroTestCase
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

    public function testSetsEveryKeyInTheSessionContract(): void
    {
        SessionUser::establish($this->row);

        foreach (['user_id','email','verified','name','pic','user','user_settings','auth_level','created_at'] as $key) {
            $this->assertArrayHasKey($key, $_SESSION, "missing \$_SESSION['$key']");
        }
        $this->assertSame($this->row->id, $_SESSION['user_id']);
        $this->assertSame(self::E, $_SESSION['email']);
        $this->assertSame(1, $_SESSION['verified']);
    }

    public function testResultingSessionIsLoggedIn(): void
    {
        SessionUser::establish($this->row);
        $this->assertTrue(SessionUser::isLoggedIn());
        $this->assertSame($this->row->id, SessionUser::getId());
    }

    public function testUnverifiedUserDoesNotProduceALoggedInSession(): void
    {
        $this->row->verified = 0;
        $this->row->save();

        SessionUser::establish(UserRow::find($this->row->id));

        // The contract keys are still written, but isLoggedIn() must be false —
        // this is what keeps a registered-but-unverified account inert.
        $this->assertFalse(SessionUser::isLoggedIn());
    }

    public function testIdentityOverrideIsUsedForTheUserKey(): void
    {
        // Auth::complete() has richer provider data than the row carries, so it
        // passes the normalized provider identity through.
        SessionUser::establish($this->row, [
            'full_name' => 'Provider Name', 'email' => self::E, 'verified' => true,
            'pic' => 'https://provider/pic', 'id' => 'provider-123',
        ]);

        $this->assertSame('Provider Name', $_SESSION['user']['full_name']);
        $this->assertSame('provider-123', $_SESSION['user']['id']);
    }

    public function testWithoutIdentityTheUserKeyIsBuiltFromTheRow(): void
    {
        SessionUser::establish($this->row);

        $this->assertSame('Establish Probe', $_SESSION['user']['full_name']);
        $this->assertSame(self::E, $_SESSION['user']['email']);
        $this->assertSame($this->row->id, $_SESSION['user']['id']);
    }

    public function testLoadsUserSettingsAndAuthLevel(): void
    {
        Database::getConnection()->prepare(
            "INSERT INTO user_settings (user_id, setting_key, setting_value, updated_by) VALUES (?, ?, ?, ?)"
        )->execute([$this->row->id, 'auth.level', '3', $this->row->id]);

        SessionUser::establish($this->row);

        $this->assertSame('3', $_SESSION['user_settings']['auth.level']);
        $this->assertSame(3, $_SESSION['auth_level']);
    }

    public function testAuthLevelDefaultsToZeroWithNoSettings(): void
    {
        SessionUser::establish($this->row);
        $this->assertSame(0, $_SESSION['auth_level']);
    }
}
