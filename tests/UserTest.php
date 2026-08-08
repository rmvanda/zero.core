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

    /* ── getName() ── */

    public function testGetNameFromSession(): void
    {
        $this->loginUser(['name' => 'John Doe']);
        $this->assertSame('John Doe', User::getName());
    }

    public function testGetNameFallsBackToUserArray(): void
    {
        $_SESSION['user'] = ['full_name' => 'Jane Doe'];
        $this->assertSame('Jane Doe', User::getName());
    }

    public function testGetNameReturnsNullWhenNoSession(): void
    {
        $this->logoutUser();
        $this->assertNull(User::getName());
    }

    /* ── getEmail() ── */

    public function testGetEmail(): void
    {
        $this->loginUser(['email' => 'user@example.com']);
        $this->assertSame('user@example.com', User::getEmail());
    }

    public function testGetEmailReturnsNullWhenNoSession(): void
    {
        $this->logoutUser();
        $this->assertNull(User::getEmail());
    }

    /* ── getId() ── */

    public function testGetId(): void
    {
        $this->loginUser(['user_id' => 42]);
        $this->assertSame(42, User::getId());
    }

    public function testGetIdFallsBackToUserArray(): void
    {
        $_SESSION['user'] = ['id' => 99];
        $this->assertSame(99, User::getId());
    }

    public function testGetIdReturnsNullWhenNoSession(): void
    {
        $this->logoutUser();
        $this->assertNull(User::getId());
    }

    /* ── getAuthLevel() ── */

    public function testGetAuthLevel(): void
    {
        $this->loginUser(['auth_level' => 5]);
        $this->assertSame(5, User::getAuthLevel());
    }

    public function testGetAuthLevelDefaultsToZero(): void
    {
        $this->logoutUser();
        $this->assertSame(0, User::getAuthLevel());
    }

    /* ── isVerified() ── */

    public function testIsVerified(): void
    {
        $this->loginUser(['verified' => true]);
        $this->assertTrue(User::isVerified());
    }

    public function testIsNotVerified(): void
    {
        $this->loginUser(['verified' => false]);
        $this->assertFalse(User::isVerified());
    }

    /* ── getPicture() ── */

    public function testGetPicture(): void
    {
        $this->loginUser(['pic' => '/img/avatar.png']);
        $this->assertSame('/img/avatar.png', User::getPicture());
    }

    /* ── getAll() ── */

    public function testGetAll(): void
    {
        $this->loginUser();
        $all = User::getAll();

        $this->assertIsArray($all);
        $this->assertArrayHasKey('id', $all);
        $this->assertArrayHasKey('email', $all);
    }

    public function testGetAllReturnsEmptyWhenNoSession(): void
    {
        $this->logoutUser();
        $this->assertSame([], User::getAll());
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
