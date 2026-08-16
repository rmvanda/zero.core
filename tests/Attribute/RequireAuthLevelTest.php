<?php

namespace Zero\Tests\Core\Attribute;

use Zero\Tests\Core\ZeroTestCase;
use Zero\Core\Attribute\RequireAuthLevel;
use Zero\Core\HTTPError;
use Zero\Core\User;
use Zero\Core\Database;

class RequireAuthLevelTest extends ZeroTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Ensure a session is "active" for these tests
        // RequireAuthLevel checks session_status(), but in CLI/PHPUnit
        // session_status() returns PHP_SESSION_NONE. We test the logic
        // that matters: the auth_level comparison.
    }

    /**
     * handler() now reads auth level through User::current()->authLevel(),
     * which memoizes into User::$current for the rest of the process. Each
     * test below builds a different mock/session for user_id 1 — without
     * clearing the memo, a later test would answer from an earlier test's
     * cached instance instead of its own mock. Same convention as
     * core/tests/UserTest.php.
     */
    protected function tearDown(): void
    {
        $prop = new \ReflectionProperty(User::class, 'current');
        $prop->setAccessible(true);
        $prop->setValue(null, null);

        parent::tearDown();
    }

    /** Logs a session in and mocks the DB to answer auth.level = $level. */
    private function loginWithAuthLevel(int $level): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
        $this->loginUser();

        $pdo = $this->createMockPdo(
            fetchAllReturn: [['setting_key' => 'auth.level', 'setting_value' => (string) $level]]
        );
        Database::init($pdo);
    }

    /* ── Sufficient auth level ── */

    public function testPassesWhenAuthLevelMeetsRequirement(): void
    {
        $this->loginWithAuthLevel(9);

        $attr = new RequireAuthLevel(5);
        $this->assertTrue($attr->handler());
    }

    public function testPassesWhenAuthLevelEqualsRequirement(): void
    {
        $this->loginWithAuthLevel(5);

        $attr = new RequireAuthLevel(5);
        $this->assertTrue($attr->handler());
    }

    /* ── Insufficient auth level ── */

    public function testThrows401WhenAuthLevelTooLow(): void
    {
        $this->loginWithAuthLevel(1);

        $attr = new RequireAuthLevel(5);

        $this->expectException(HTTPError::class);
        $this->expectExceptionCode(401);
        $attr->handler();
    }

    public function testThrows401WhenNoAuthLevel(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
        $this->logoutUser();

        $attr = new RequireAuthLevel(1);

        $this->expectException(HTTPError::class);
        $this->expectExceptionCode(401);
        $attr->handler();
    }

    /* ── Default level ── */

    public function testDefaultLevelIsNine(): void
    {
        $attr = new RequireAuthLevel();

        $ref = new \ReflectionClass($attr);
        $prop = $ref->getProperty('level');
        $this->assertSame(9, $prop->getValue($attr));
    }
}
