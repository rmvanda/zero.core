<?php

namespace Zero\Tests\Core;

use Zero\Core\User;
use Zero\Core\Database;

class UserTest extends ZeroTestCase
{
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

        $pdo = $this->createMockPdo(
            fetchReturn: ['setting_value' => '1']
        );
        Database::init($pdo);

        $this->assertTrue(User::hasPermission('allow.admin'));
    }

    public function testHasPermissionReturnsFalseWhenNotSet(): void
    {
        $this->loginUser(['user_id' => 1]);

        $pdo = $this->createMockPdo(fetchReturn: false);
        Database::init($pdo);

        $this->assertFalse(User::hasPermission('nonexistent'));
    }

    public function testHasPermissionReturnsFalseWhenValueIsZero(): void
    {
        $this->loginUser(['user_id' => 1]);

        $pdo = $this->createMockPdo(
            fetchReturn: ['setting_value' => '0']
        );
        Database::init($pdo);

        $this->assertFalse(User::hasPermission('disabled.perm'));
    }

    /* ── getPermission() ── */

    public function testGetPermissionReturnsValue(): void
    {
        $this->loginUser(['user_id' => 1]);

        $pdo = $this->createMockPdo(
            fetchReturn: ['setting_value' => 'dark']
        );
        Database::init($pdo);

        $this->assertSame('dark', User::getPermission('theme'));
    }

    public function testGetPermissionReturnsNullWhenNotLoggedIn(): void
    {
        $this->logoutUser();
        $this->assertNull(User::getPermission('anything'));
    }

    /* ── getAllPermissions() ── */

    public function testGetAllPermissions(): void
    {
        $this->loginUser(['user_id' => 1]);

        $pdo = $this->createMockPdo(
            fetchAllReturn: [
                ['setting_key' => 'allow.admin', 'setting_value' => '1'],
                ['setting_key' => 'theme', 'setting_value' => 'dark'],
            ]
        );
        Database::init($pdo);

        $perms = User::getAllPermissions();
        $this->assertSame('1', $perms['allow.admin']);
        $this->assertSame('dark', $perms['theme']);
    }

    public function testGetAllPermissionsReturnsEmptyWhenNotLoggedIn(): void
    {
        $this->logoutUser();
        $this->assertSame([], User::getAllPermissions());
    }

    /* ── setPermission() ── */

    public function testSetPermissionReturnsTrueOnSuccess(): void
    {
        $this->loginUser(['user_id' => 1]);

        $pdo = $this->createMockPdo(executeReturn: true);
        Database::init($pdo);

        $this->assertTrue(User::setPermission('theme', 'dark'));
    }

    public function testSetPermissionReturnsFalseWhenNotLoggedIn(): void
    {
        $this->logoutUser();
        $this->assertFalse(User::setPermission('theme', 'dark'));
    }
}
