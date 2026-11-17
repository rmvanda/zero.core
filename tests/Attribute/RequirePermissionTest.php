<?php

namespace Zero\Tests\Core\Attribute;

use Zero\Tests\Core\ZeroTestCase;
use Zero\Core\Attribute\RequirePermission;
use Zero\Core\User;
use Zero\Core\Session;
use Zero\Core\Database;
use Zero\Core\Group;
use Zero\Core\Request;
use Zero\Core\Exception\HTTPError;
use Zero\Core\Application;

/**
 * Shaped exactly like modules/Receipts: a broad permission on the class, a
 * narrower one on an endpoint. Both must be held.
 */
#[RequirePermission('allow.zerotest.additive.classlevel')]
class AdditivePermissionFixture {
    #[RequirePermission('allow.zerotest.additive.methodlevel')]
    public function guarded() {}
}

/**
 * RequirePermission::handler() (C rewrote this) had no test at all before
 * this file. handler() does two things: delegate the anonymous case to
 * RequireLogin::handler(), then loop over every required permission calling
 * Session::can() — whose strict grant semantics are already covered
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
    private const GROUP = 'zerotest-reqperm-group';
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
        $this->removeTestRows(self::E, self::GROUP);
        Database::getConnection()
            ->prepare("DELETE FROM permission WHERE permission_key LIKE 'allow.zerotest%'")
            ->execute();
        $_SESSION = [];
        // Static, so it survives the test that set it and would silently change
        // the branch taken by every test after it.
        Request::$acceptsJSON = false;
        $this->resetCurrentMemo();
    }

    /**
     * Same convention as UserPermissionTest::resetCurrentMemo() — Session::$user
     * is a per-process memo, not per-test, and must not survive between tests.
     */
    private function resetCurrentMemo(): void
    {
        $prop = new \ReflectionProperty(\Zero\Core\Session::class, 'user');
        $prop->setAccessible(true);
        $prop->setValue(null, null);
    }

    private function grant(string $key): void
    {
        \Zero\Core\Permission::capability($key)
            ->grant(0, User::find($this->userId), true, false, false, $this->userId);
    }

    private function login(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
        $this->loginUser(['user_id' => $this->userId]);
        Session::establish(User::find($this->userId));
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

    public function testAPermissionHeldThroughAnActiveGroupIsApproved(): void
    {
        $group = Group::create(['group_name' => self::GROUP, 'created_by' => $this->userId]);
        $group->add($this->userId, 'member', $this->userId);
        $group->grant('allow.zerotest_reqperm', $this->userId);
        $this->login();
        Request::$acceptsJSON = true;

        $this->assertTrue((new RequirePermission('allow.zerotest_reqperm'))->handler());

        // A deactivated group grants nothing. A new session models the next request.
        $group->deactivate();
        $this->resetCurrentMemo();
        $this->login();

        try {
            (new RequirePermission('allow.zerotest_reqperm'))->handler();
            $this->fail('a deactivated group must not satisfy the requirement');
        } catch (HTTPError $e) {
            $this->assertSame(403, $e->getCode());
        }
    }

    /*
     * Class-level and method-level RequirePermission UNION.
     *
     * These go through Application::checkAttributes() rather than calling
     * handler() directly, because the union is the resolver's behaviour, not
     * the attribute's — handler() only ever sees its own list.
     *
     * Under the replace semantics this replaced, a method declaring any
     * RequirePermission dropped the class-level one entirely. Measured before
     * the change on a fixture shaped exactly like modules/Receipts: a user
     * holding ONLY the method-level key passed guarded(), and the same user
     * was denied on a sibling that declared no attribute of its own. So a
     * grant that reads narrower — edit but not view — was in fact wider.
     */

    public function testMethodLevelPermissionDoesNotDropTheClassLevelOne(): void
    {
        $this->grant('allow.zerotest.additive.methodlevel');   // NOT the class-level key
        $this->login();
        Request::$acceptsJSON = true;

        try {
            Application::checkAttributes(AdditivePermissionFixture::class, 'guarded');
            $this->fail('the class-level permission must still be required');
        } catch (HTTPError $e) {
            $this->assertSame(403, $e->getCode());
            $this->assertStringContainsString('allow.zerotest.additive.classlevel', $e->detail ?? '',
                'the class-level key is what should be missing');
        }
    }

    public function testBothPermissionsTogetherAreApproved(): void
    {
        $this->grant('allow.zerotest.additive.classlevel');
        $this->grant('allow.zerotest.additive.methodlevel');
        $this->login();
        Request::$acceptsJSON = true;

        Application::checkAttributes(AdditivePermissionFixture::class, 'guarded');
        $this->addToAssertionCount(1);
    }

    public function testClassLevelAloneStillDeniesTheMethodLevelKey(): void
    {
        // The mirror of the first case: holding only the class-level key must
        // not satisfy the method-level requirement either.
        $this->grant('allow.zerotest.additive.classlevel');
        $this->login();
        Request::$acceptsJSON = true;

        try {
            Application::checkAttributes(AdditivePermissionFixture::class, 'guarded');
            $this->fail('the method-level permission must still be required');
        } catch (HTTPError $e) {
            $this->assertStringContainsString('allow.zerotest.additive.methodlevel', $e->detail ?? '');
        }
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
