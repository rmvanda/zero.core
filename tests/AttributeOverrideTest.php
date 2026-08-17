<?php

namespace Zero\Tests\Core;

use Zero\Core\Application;
use Zero\Core\Attribute\AllowedMethods;
use Zero\Core\Request;
use Zero\Core\HTTPError;

/* ── Fixture classes under test ── */

#[AllowedMethods('GET')]
class OverrideFixtureClassGet {
    public function inherited() {}

    #[AllowedMethods('POST')]
    public function overridden() {}

    #[\Zero\Core\Attribute\RequireAuthLevel(5)]
    public function differentType() {}
}

class OverrideFixtureNoClassAttr {
    #[AllowedMethods('POST')]
    public function methodOnly() {}
}

/**
 * A class whose __call() serves virtual endpoints — the URL segment is data
 * (a slug, a short link, an image path), not a method name. Attributes on
 * __call() are what gate those, since no ReflectionMethod exists for them.
 */
class OverrideFixtureCallOnly {
    #[AllowedMethods('POST')]
    public function __call($name, $args) {}
}

/** Class-level and method-level permissions, which must UNION rather than replace. */
#[\Zero\Core\Attribute\RequirePermission('zerotest.additive.classlevel')]
class AdditiveFixture {
    #[\Zero\Core\Attribute\RequirePermission('zerotest.additive.methodlevel')]
    public function guarded() {}
}

/** Class-level gate plus a __call() that declares a different type. */
#[AllowedMethods('GET')]
class OverrideFixtureClassPlusCall {
    #[\Zero\Core\Attribute\RequireAuthLevel(5)]
    public function __call($name, $args) {}
}

/**
 * Tests for the method-level attribute override behavior in
 * core/Application.php::checkAttributes(). Method-level attributes of a
 * given type replace class-level attributes of the same type; other types
 * still run from the class.
 *
 * Also covers the __call() fallback: a virtual endpoint has no method of its
 * own, so attributes declared on __call() stand in for method-level ones.
 * That fallback is what lets Module::__call() and
 * AdminResponse::routeToModuleAdmin() gate what they dispatch.
 */
class AttributeOverrideTest extends ZeroTestCase
{
    private function invokeCheck(string $module, string $endpoint): void
    {
        Application::checkAttributes($module, $endpoint);
    }

    private int $obLevelBefore = 0;

    protected function setUp(): void
    {
        parent::setUp();
        // Reset the attribute log so assertions are isolated
        Application::$attributeLog = [];
        // Capture baseline output buffer depth so we can clean up the buffer
        // that Application::__construct() opens via ob_start().
        $this->obLevelBefore = ob_get_level();
    }

    protected function tearDown(): void
    {
        while (ob_get_level() > $this->obLevelBefore) {
            ob_end_clean();
        }
        // Static, so it would otherwise survive into every test after the one
        // that set it and silently change which branch the gates take.
        Request::$acceptsJSON = false;
        parent::tearDown();
    }

    /* ── Override semantics ── */

    public function testMethodLevelAttributeOverridesClassLevel(): void
    {
        // Class says GET only; method says POST only. Sending POST should pass.
        Request::$method = 'POST';

        $this->invokeCheck(OverrideFixtureClassGet::class, 'overridden');

        // No exception thrown == pass
        $this->assertTrue(true);
    }

    public function testClassLevelStillAppliesWhenMethodHasNoSameTypeAttr(): void
    {
        // Class says GET only; method declares nothing. POST should be blocked.
        Request::$method = 'POST';

        $this->expectException(HTTPError::class);
        $this->expectExceptionCode(405);
        $this->invokeCheck(OverrideFixtureClassGet::class, 'inherited');
    }

    public function testClassLevelInheritedWhenMethodDeclaresDifferentType(): void
    {
        // Method declares RequireAuthLevel(5) — different type, so class-level
        // AllowedMethods('GET') should still apply. POST → 405.
        Request::$method = 'POST';

        $this->expectException(HTTPError::class);
        $this->expectExceptionCode(405);
        $this->invokeCheck(OverrideFixtureClassGet::class, 'differentType');
    }

    public function testMethodOnlyAttributeAppliesWhenNoClassAttribute(): void
    {
        // No class-level attribute; method says POST only. GET → 405.
        Request::$method = 'GET';

        $this->expectException(HTTPError::class);
        $this->expectExceptionCode(405);
        $this->invokeCheck(OverrideFixtureNoClassAttr::class, 'methodOnly');
    }

    /* ── Virtual endpoints: the __call() fallback ── */

    public function testCallAttributesGateAVirtualEndpoint(): void
    {
        // 'anything' is not a method. Without the __call() fallback no
        // method-level attribute would be found and the GET would pass.
        Request::$method = 'GET';

        $this->expectException(HTTPError::class);
        $this->expectExceptionCode(405);
        $this->invokeCheck(OverrideFixtureCallOnly::class, 'anything');
    }

    public function testCallAttributesAllowTheDeclaredMethod(): void
    {
        Request::$method = 'POST';

        $this->invokeCheck(OverrideFixtureCallOnly::class, 'anything');

        $this->assertTrue(true);
    }

    public function testRealMethodWinsOverCallAttributes(): void
    {
        // The fallback must apply ONLY when the endpoint has no method of its
        // own. methodOnly() exists, so __call() must not be consulted.
        Request::$method = 'POST';

        $this->invokeCheck(OverrideFixtureNoClassAttr::class, 'methodOnly');

        $this->assertTrue(true);
    }

    public function testClassLevelStillAppliesAlongsideCallAttributes(): void
    {
        // __call() declares RequireAuthLevel — a different type — so the
        // class-level AllowedMethods('GET') still gates the virtual endpoint.
        Request::$method = 'POST';

        $this->expectException(HTTPError::class);
        $this->expectExceptionCode(405);
        $this->invokeCheck(OverrideFixtureClassPlusCall::class, 'whatever');
    }

    public function testCallScopeIsTaggedInTheLog(): void
    {
        Request::$method = 'POST';
        $this->invokeCheck(OverrideFixtureCallOnly::class, 'anything');

        $scopes = array_column(Application::$attributeLog, 'scope');

        $this->assertContains('call', $scopes, 'Expected a "call"-scoped log entry');
        $this->assertNotContains('method', $scopes);
    }

    /* ── Additive types: RequirePermission unions instead of replacing ── */

    public function testRequirePermissionIsMarkedAdditive(): void
    {
        $m = new \ReflectionMethod(Application::class, 'isAdditive');
        $m->setAccessible(true);

        $this->assertTrue($m->invoke(null, \Zero\Core\Attribute\RequirePermission::class),
            'RequirePermission must union class-level and method-level declarations');
        $this->assertFalse($m->invoke(null, AllowedMethods::class),
            'AllowedMethods names a constraint — two of them contradict, so replace stands');
        $this->assertFalse($m->invoke(null, \Zero\Core\Attribute\RequireLogin::class),
            'RequireLogin names a destination — replace is what makes the redirect override work');
    }

    public function testAdditiveClassAttrIsNotMarkedOverriddenInTheLog(): void
    {
        // The behavioural half — a class-level permission surviving a
        // method-level one — needs a real user holding one key and not the
        // other, so it lives in RequirePermissionTest with the fixtures for
        // that. Here we pin the resolver's own bookkeeping: a shadowing that
        // did not happen must not be reported to DevToolbar as one.
        //
        // The log is written before handleAttributes() runs, so the denial
        // this provokes does not stop us reading it. acceptsJSON makes that
        // denial a throw rather than the header()+exit() that would take the
        // PHPUnit process with it.
        Request::$method      = 'GET';
        Request::$acceptsJSON = true;

        try {
            $this->invokeCheck(AdditiveFixture::class, 'guarded');
        } catch (HTTPError $e) {
            // Anonymous, so RequireLogin denies first. Expected; not the point.
        }

        $classEntry = null;
        foreach (Application::$attributeLog as $entry) {
            if ($entry['scope'] === 'class' && str_ends_with($entry['name'], 'RequirePermission')) {
                $classEntry = $entry;
                break;
            }
        }

        $this->assertNotNull($classEntry, 'expected a class-level RequirePermission log entry');
        $this->assertFalse($classEntry['overridden'],
            'an additive class-level attr is not shadowed and must not be flagged as such');
    }

    /* ── DevToolbar log metadata ── */

    public function testAttributeLogMarksOverriddenClassAttr(): void
    {
        Request::$method = 'POST';
        $this->invokeCheck(OverrideFixtureClassGet::class, 'overridden');

        $log = Application::$attributeLog;

        // Find the class-level AllowedMethods entry
        $classEntry = null;
        foreach ($log as $entry) {
            if ($entry['scope'] === 'class' && str_ends_with($entry['name'], 'AllowedMethods')) {
                $classEntry = $entry;
                break;
            }
        }

        $this->assertNotNull($classEntry, 'Expected class-level AllowedMethods log entry');
        $this->assertTrue($classEntry['overridden'], 'Class-level attr should be marked overridden');
    }

    public function testAttributeLogNotOverriddenWhenNoConflict(): void
    {
        Request::$method = 'GET';
        $this->invokeCheck(OverrideFixtureClassGet::class, 'inherited');

        $log = Application::$attributeLog;

        $classEntry = null;
        foreach ($log as $entry) {
            if ($entry['scope'] === 'class' && str_ends_with($entry['name'], 'AllowedMethods')) {
                $classEntry = $entry;
                break;
            }
        }

        $this->assertNotNull($classEntry);
        $this->assertFalse($classEntry['overridden']);
    }
}
