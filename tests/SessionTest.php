<?php

namespace Zero\Tests\Core;

use Zero\Core\Session;
use Zero\Core\Database;

/**
 * Zero\Core\Session read against a hand-built $_SESSION: isLoggedIn(),
 * userId() and can(). No database row is needed; where can() has to look
 * capabilities up, the connection is a mock.
 */
class SessionTest extends ZeroTestCase
{
    protected function tearDown(): void
    {
        // Session::can() delegates to Session::user()?->can(), and user()
        // memoizes into a private static for the rest of the process. The
        // can() tests below build different sessions/mocks per
        // test but reuse user_id 1 — without clearing the memo, a later
        // test would silently answer from an earlier test's cached instance
        // instead of its own mock. Same convention as
        // core/tests/SessionUserTest.php::resetCurrentMemo().
        $prop = new \ReflectionProperty(\Zero\Core\Session::class, 'user');
        $prop->setAccessible(true);
        $prop->setValue(null, null);

        parent::tearDown();
    }

    /* ── isLoggedIn() ── */

    public function testIsLoggedInWhenSessionPopulated(): void
    {
        $this->loginUser();
        $this->assertTrue(Session::isLoggedIn());
    }

    public function testNotLoggedInWithEmptySession(): void
    {
        $this->logoutUser();
        $this->assertFalse(Session::isLoggedIn());
    }

    public function testNotLoggedInWithoutEmail(): void
    {
        $this->loginUser();
        unset($_SESSION['email']);
        $this->assertFalse(Session::isLoggedIn());
    }

    public function testNotLoggedInWhenNotVerified(): void
    {
        $this->loginUser(['verified' => false]);
        $this->assertFalse(Session::isLoggedIn());
    }

    /*
     * The session user's name, email, picture and verified flag are read
     * from Session::user(), a Zero\Core\Access\User built from the session. There
     * are no per-field statics, which is what stops a call like
     * `getName($someoneElseId)` from silently discarding its argument and
     * answering for the session user instead. That session projection is
     * covered by core/tests/SessionUserTest.php.
     */

    /* ── userId() ── */

    public function testUserId(): void
    {
        $this->loginUser(['user_id' => 42]);
        $this->assertSame(42, Session::userId());
    }

    public function testUserIdIsNullWhenNoSession(): void
    {
        $this->logoutUser();
        $this->assertNull(Session::userId());
    }

    /* ── can() ── */

    public function testCanReturnsFalseWhenNotLoggedIn(): void
    {
        $this->logoutUser();
        $this->assertFalse(Session::can('admin'));
    }

    public function testCanQueriesDatabase(): void
    {
        $this->loginUser(['user_id' => 1]);

        // Session::can() delegates to Session::user()->can(), which reads
        // through capabilities() — one bulk fetchAll() of every capability
        // key the user holds, not a single-key fetch(). Mock shape follows suit.
        $pdo = $this->createMockPdo(
            fetchAllReturn: ['allow.admin']
        );
        Database::init($pdo);

        $this->assertTrue(Session::can('allow.admin'));
    }

    public function testCanReturnsFalseWhenNotSet(): void
    {
        $this->loginUser(['user_id' => 1]);

        $pdo = $this->createMockPdo(fetchAllReturn: []);
        Database::init($pdo);

        $this->assertFalse(Session::can('nonexistent'));
    }

    public function testCanReturnsFalseWhenOnlyAnotherKeyIsHeld(): void
    {
        $this->loginUser(['user_id' => 1]);

        $pdo = $this->createMockPdo(
            fetchAllReturn: ['other.perm']
        );
        Database::init($pdo);

        $this->assertFalse(Session::can('disabled.perm'));
    }

    /*
     * What a user holds, whoever is logged in, is Zero\Core\Access\User's:
     * can(), capabilities(), grant(), revoke(), authLevel() and setSetting().
     * Those, and Session::can() against real rows, are covered by
     * core/tests/UserPermissionTest.php.
     */
}
