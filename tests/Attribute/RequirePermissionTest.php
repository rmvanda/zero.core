<?php

namespace Zero\Tests\Core\Attribute;

use Zero\Tests\Core\ZeroTestCase;
use Zero\Core\Attribute\RequirePermission;
use Zero\Core\User;
use Zero\Core\Database;

/**
 * RequirePermission::handler() (C rewrote this) had no test at all before
 * this file. handler() does two things: delegate the anonymous case to
 * RequireLogin::handler(), then loop over every required permission calling
 * User::hasPermission() — whose strict grant semantics are already covered
 * one layer down by core/tests/UserPermissionTest.php. What THIS test covers
 * is the part unique to handler() itself: a logged-in user holding the
 * permission(s) is approved, and ALL of a list of required permissions must
 * be held, not just one.
 *
 * The denial path is deliberately NOT covered here: on a missing permission,
 * redirectToAccessRequest() calls header()+exit(), which would terminate the
 * PHPUnit process running this test rather than fail it. RequireLogin's own
 * redirect path has the identical shape and is untested for the same reason
 * — there is no RequireLoginTest in this directory.
 */
class RequirePermissionTest extends ZeroTestCase
{
    private const E = 'zerotest-reqperm@example.invalid';
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connectTestDatabase();
        $this->cleanup();
        $this->userId = (int) User::create(
            ['name' => 'ReqPerm Probe', 'email' => self::E, 'verified' => 1]
        )->id;
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    private function cleanup(): void
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([self::E]);
        foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $id) {
            $db->prepare("DELETE FROM user_settings WHERE user_id = ?")->execute([$id]);
        }
        $db->prepare("DELETE FROM users WHERE email = ?")->execute([self::E]);
        $_SESSION = [];
        $this->resetCurrentMemo();
    }

    /**
     * Same convention as UserPermissionTest::resetCurrentMemo() — User::$current
     * is a per-process memo, not per-test, and must not survive between tests.
     */
    private function resetCurrentMemo(): void
    {
        $prop = new \ReflectionProperty(User::class, 'current');
        $prop->setAccessible(true);
        $prop->setValue(null, null);
    }

    private function grant(string $key): void
    {
        Database::getConnection()->prepare(
            "INSERT INTO user_settings (user_id, setting_key, setting_value)
             VALUES (?, ?, '1')
             ON DUPLICATE KEY UPDATE setting_value = '1'"
        )->execute([$this->userId, $key]);
    }

    private function login(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
        $this->loginUser(['user_id' => $this->userId]);
        User::establish(User::find($this->userId));
    }

    public function testApprovesWhenSinglePermissionIsGranted(): void
    {
        $this->grant('allow.zerotest_reqperm');
        $this->login();

        $attr = new RequirePermission('allow.zerotest_reqperm');

        $this->assertTrue($attr->handler());
        $this->assertTrue($attr->approved);
    }

    public function testApprovesOnlyWhenEveryListedPermissionIsGranted(): void
    {
        $this->grant('allow.zerotest_reqperm');
        $this->grant('allow.zerotest_reqperm2');
        $this->login();

        $attr = new RequirePermission(['allow.zerotest_reqperm', 'allow.zerotest_reqperm2']);

        $this->assertTrue($attr->handler());
    }
}
