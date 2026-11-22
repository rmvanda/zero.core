<?php
namespace Zero\Tests\Core;

use Zero\Core\Access\User;
use Zero\Core\Database;
use Zero\Core\Session;

/**
 * An account is closed by disabling it. disable() and enable() set the flag,
 * the flag is read from the database, and Session::endIfDisabled() ends the
 * session of a disabled account and of no other.
 */
class UserDisableTest extends ZeroTestCase
{
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connectTestDatabase();
        $this->removeTestRows('zerotest-disable-%@example.invalid', 'zerotest-disable-%');
        $this->user = User::create(['name' => 'Disable Probe', 'email' => 'zerotest-disable-probe@example.invalid', 'verified' => 1]);
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        $this->removeTestRows('zerotest-disable-%@example.invalid', 'zerotest-disable-%');
        parent::tearDown();
    }

    public function testANewAccountIsNotDisabled(): void
    {
        $this->assertFalse(User::find($this->user->id)->isDisabled());
        $this->assertFalse(User::isDisabledId((int) $this->user->id));
    }

    public function testDisableAndEnableSetTheFlagAndKeepTheRow(): void
    {
        $this->user->disable();
        $this->assertTrue(User::find($this->user->id)->isDisabled());
        $this->assertTrue(User::isDisabledId((int) $this->user->id));
        $this->assertSame('zerotest-disable-probe@example.invalid', User::find($this->user->id)->email);

        $this->user->enable();
        $this->assertFalse(User::find($this->user->id)->isDisabled());
    }

    public function testAnIdWithNoAccountIsNotDisabled(): void
    {
        $this->assertFalse(User::isDisabledId(2147483600));
    }

    public function testTheSessionOfADisabledAccountEnds(): void
    {
        $_SESSION = ['user_id' => (int) $this->user->id, 'email' => $this->user->email, 'verified' => 1];
        Session::endIfDisabled();
        $this->assertSame((int) $this->user->id, Session::userId(), 'an enabled account keeps its session');

        $this->user->disable();
        Session::endIfDisabled();
        $this->assertNull(Session::userId());
        $this->assertFalse(Session::isLoggedIn());
    }

    public function testNoLoginMeansNoQueryAndNoChange(): void
    {
        $_SESSION = ['return_url' => '/x'];
        Session::endIfDisabled();
        $this->assertSame(['return_url' => '/x'], $_SESSION);
    }
}
