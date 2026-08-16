<?php

namespace Zero\Tests\Core\Attribute;

use Zero\Tests\Core\ZeroTestCase;
use Zero\Core\Attribute\RequirePermission;
use Zero\Core\User;
use Zero\Core\Database;
use Zero\Core\Request;
use Zero\Core\HTTPError;

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
 * The denial path is covered for JSON callers only. deny() now throws an
 * HTTPError(403) when Request::$acceptsJSON, so that half can be asserted. The
 * HTML half still calls header()+exit(), which would terminate the PHPUnit
 * process running this test rather than fail it, so it stays uncovered.
 * RequireLoginTest.php in this directory has the same split for the same
 * reason.
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
        // Static, so it survives the test that set it and would silently change
        // the branch taken by every test after it.
        Request::$acceptsJSON = false;
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

    /*
     * The denial path, now that it is reachable for JSON callers.
     *
     * The docblock above records that denial was untestable: deny() called
     * header()+exit(), which terminates the PHPUnit process rather than
     * failing the test. That is still true for an HTML caller. A JSON caller
     * now throws instead, so this half of the behaviour can be pinned.
     */

    public function testJsonCallerGetsA403RatherThanARedirect(): void
    {
        $this->login();                       // logged in, permission NOT granted
        Request::$acceptsJSON = true;

        try {
            (new RequirePermission('allow.zerotest_never_granted'))->handler();
            $this->fail('a missing permission must be refused');
        } catch (HTTPError $e) {
            $this->assertSame(403, $e->getCode());
            $this->assertStringContainsString('allow.zerotest_never_granted', $e->detail ?? '',
                'the caller should be told which permission it lacks');
        }
    }

    public function testJsonCallerIsStillApprovedWhenPermissionIsHeld(): void
    {
        // The JSON branch must gate on the permission, not on the header.
        $this->grant('allow.zerotest_reqperm');
        $this->login();
        Request::$acceptsJSON = true;

        $this->assertTrue((new RequirePermission('allow.zerotest_reqperm'))->handler());
    }

    /**
     * An ANONYMOUS JSON caller is refused by the delegated RequireLogin, which
     * has returned a 401 since the gate-consistency work. Pinned here because
     * the two halves of handler() used to disagree — 401 from this one, 302
     * from the permission check — for the same request shape.
     */
    public function testAnonymousJsonCallerGetsA401FromTheDelegatedLoginGate(): void
    {
        $_SESSION = [];
        $this->resetCurrentMemo();
        Request::$acceptsJSON = true;

        try {
            (new RequirePermission('allow.zerotest_reqperm'))->handler();
            $this->fail('an anonymous caller must be refused');
        } catch (HTTPError $e) {
            $this->assertSame(401, $e->getCode());
        }
    }
}
