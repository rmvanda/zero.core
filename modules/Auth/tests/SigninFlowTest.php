<?php
namespace Zero\Core\Module\Auth\Tests;

use Zero\Tests\Core\ZeroTestCase;
use Zero\Core\Module\Auth\Model\AuthToken;
use Zero\Core\Access\User;
use Zero\Core\Database;

/**
 * The sign-in invariants. The HTTP layer is verified by hand, but the rules
 * below must never regress silently.
 */
class SigninFlowTest extends ZeroTestCase
{
    private const E = 'zerotest-signin@example.invalid';

    protected function setUp(): void
    {
        parent::setUp();
        $this->connectTestDatabase();
        $this->cleanup();
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    private function cleanup(): void
    {
        $db = Database::getConnection();
        if ($u = User::findByEmail(self::E)) {
            $db->prepare("DELETE FROM auth_token WHERE user_id = ?")->execute([$u->id]);
        }
        $db->prepare("DELETE FROM users WHERE email = ?")->execute([self::E]);
    }

    private function user(int $verified = 1): User
    {
        return User::create(['name' => 'Signin Probe', 'email' => self::E, 'verified' => $verified]);
    }

    public function testAVerifyTokenIsNotAcceptedAsALoginToken(): void
    {
        // token() tries 'verify' first precisely so a verify token cannot fall
        // through to the login branch and sign someone in while still unverified.
        $u = $this->user(0);
        $token = AuthToken::issue($u->id, 'verify', 3600);

        $this->assertNull(AuthToken::consume($token, 'login'));
        $this->assertSame($u->id, AuthToken::consume($token, 'verify'));
    }

    public function testALoginTokenIsNotAcceptedAsAVerifyToken(): void
    {
        $u = $this->user(1);
        $token = AuthToken::issue($u->id, 'login', 3600);

        $this->assertNull(AuthToken::consume($token, 'verify'));
        $this->assertSame($u->id, AuthToken::consume($token, 'login'));
    }

    public function testAConsumedTokenCannotBeReused(): void
    {
        // Single-use is what limits the damage of a leaked or forwarded link.
        //
        // NOTE ON SCOPE: this does NOT verify that Auth::token()'s GET branch
        // leaves the token unburned — nothing here executes Auth::token(), which
        // needs an HTTP layer. That property (GET renders a confirm form, only
        // POST consumes) exists so a mail scanner pre-fetching the link cannot
        // destroy it, and it is verified by hand. See the comment on token().
        $u = $this->user(1);
        $token = AuthToken::issue($u->id, 'login', 3600);

        $this->assertSame($u->id, AuthToken::consume($token, 'login'));
        $this->assertNull(AuthToken::consume($token, 'login'));
        $this->assertSame(0, AuthToken::liveCountForUser($u->id, 'login'));
    }

    public function testConsumingAVerifyTokenIsWhatMakesTheAccountUsable(): void
    {
        $u = $this->user(0);

        $_SESSION = [];
        \Zero\Core\Session::establish(User::find($u->id));
        $this->assertFalse(\Zero\Core\Session::isLoggedIn(), 'unverified account must be inert');

        $token  = AuthToken::issue($u->id, 'verify', 3600);
        $userId = AuthToken::consume($token, 'verify');
        $this->assertSame($u->id, $userId);

        $confirmed = User::find($userId);
        $confirmed->verified = 1;
        $confirmed->save();

        $_SESSION = [];
        \Zero\Core\Session::establish(User::find($userId));
        $this->assertTrue(\Zero\Core\Session::isLoggedIn());
    }

    public function testThrottleCapIsThreeLiveTokens(): void
    {
        $u = $this->user(1);
        for ($i = 0; $i < 3; $i++) {
            AuthToken::issue($u->id, 'login', 3600);
        }
        $this->assertSame(3, AuthToken::liveCountForUser($u->id, 'login'));

        // A 4th issuance burns the oldest, so the cap holds.
        AuthToken::burnOldest($u->id, 'login');
        AuthToken::issue($u->id, 'login', 3600);
        $this->assertSame(3, AuthToken::liveCountForUser($u->id, 'login'));
    }
}
