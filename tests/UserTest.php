<?php

namespace Zero\Tests\Core;

use Zero\Core\User;
use Zero\Core\Database;

class UserTest extends ZeroTestCase
{
    protected function tearDown(): void
    {
        // hasPermission() now delegates to current()?->can(), and current()
        // memoizes into a private static for the rest of the process. The
        // hasPermission() tests below build different sessions/mocks per
        // test but reuse user_id 1 — without clearing the memo, a later
        // test would silently answer from an earlier test's cached instance
        // instead of its own mock. Same convention as
        // core/tests/UserCurrentTest.php::resetCurrentMemo().
        $prop = new \ReflectionProperty(User::class, 'current');
        $prop->setAccessible(true);
        $prop->setValue(null, null);

        parent::tearDown();
    }

    /* ── isLoggedIn() ── */

    public function testIsLoggedInWhenSessionPopulated(): void
    {
        $this->loginUser();
        $this->assertTrue(User::isLoggedIn());
    }

    public function testNotLoggedInWithEmptySession(): void
    {
        $this->logoutUser();
        $this->assertFalse(User::isLoggedIn());
    }

    public function testNotLoggedInWithoutEmail(): void
    {
        $this->loginUser();
        unset($_SESSION['email']);
        $this->assertFalse(User::isLoggedIn());
    }

    public function testNotLoggedInWhenNotVerified(): void
    {
        $this->loginUser(['verified' => false]);
        $this->assertFalse(User::isLoggedIn());
    }

    /*
     * getName(), getEmail(), getPicture(), isVerified(), getAll() and the
     * $_SESSION['user'] fallback on getId() were deleted in the User/Model
     * consolidation (Zero\Core\User now extends Zero\Core\Model): they were
     * the record-field statics that made `getName($someoneElseId)` silently
     * discard its argument and answer for the session user instead. Their
     * coverage is superseded by core/tests/UserCurrentTest.php, which
     * exercises the same session-projection data through current().
     */

    /* ── getId() ── */

    public function testGetId(): void
    {
        $this->loginUser(['user_id' => 42]);
        $this->assertSame(42, User::getId());
    }

    public function testGetIdReturnsNullWhenNoSession(): void
    {
        $this->logoutUser();
        $this->assertNull(User::getId());
    }

    /* ── hasPermission() ── */

    public function testHasPermissionReturnsFalseWhenNotLoggedIn(): void
    {
        $this->logoutUser();
        $this->assertFalse(User::hasPermission('admin'));
    }

    public function testHasPermissionQueriesDatabase(): void
    {
        $this->loginUser(['user_id' => 1]);

        // hasPermission() now delegates to current()->can(), which reads
        // through settings() — one bulk fetchAll() of every setting_key for
        // the user, not a single-key fetch(). Mock shape follows suit.
        $pdo = $this->createMockPdo(
            fetchAllReturn: [['setting_key' => 'allow.admin', 'setting_value' => '1']]
        );
        Database::init($pdo);

        $this->assertTrue(User::hasPermission('allow.admin'));
    }

    public function testHasPermissionReturnsFalseWhenNotSet(): void
    {
        $this->loginUser(['user_id' => 1]);

        $pdo = $this->createMockPdo(fetchAllReturn: []);
        Database::init($pdo);

        $this->assertFalse(User::hasPermission('nonexistent'));
    }

    public function testHasPermissionReturnsFalseWhenValueIsZero(): void
    {
        $this->loginUser(['user_id' => 1]);

        $pdo = $this->createMockPdo(
            fetchAllReturn: [['setting_key' => 'disabled.perm', 'setting_value' => '0']]
        );
        Database::init($pdo);

        $this->assertFalse(User::hasPermission('disabled.perm'));
    }

    /*
     * getPermission(), getAllPermissions() and setPermission() were deleted
     * in the permission consolidation (Zero\Core\User::can()/authLevel()/
     * setSetting() replace them): all three had zero production callers and
     * were kept alive only by the tests that stood here. Their coverage is
     * superseded by core/tests/UserPermissionTest.php.
     */
}
