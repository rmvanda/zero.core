<?php

namespace Zero\Tests\Core\Attribute;

use Zero\Tests\Core\ZeroTestCase;
use Zero\Core\Attribute\RequireAuthLevel;
use Zero\Core\HTTPError;

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

    /* ── Sufficient auth level ── */

    public function testPassesWhenAuthLevelMeetsRequirement(): void
    {
        // Simulate active session by starting one
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
        $_SESSION['auth_level'] = 9;

        $attr = new RequireAuthLevel(5);
        $this->assertTrue($attr->handler());
    }

    public function testPassesWhenAuthLevelEqualsRequirement(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
        $_SESSION['auth_level'] = 5;

        $attr = new RequireAuthLevel(5);
        $this->assertTrue($attr->handler());
    }

    /* ── Insufficient auth level ── */

    public function testThrows401WhenAuthLevelTooLow(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
        $_SESSION['auth_level'] = 1;

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
        unset($_SESSION['auth_level']);

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
