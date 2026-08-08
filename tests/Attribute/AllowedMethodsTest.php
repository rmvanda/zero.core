<?php

namespace Zero\Tests\Core\Attribute;

use Zero\Tests\Core\ZeroTestCase;
use Zero\Core\Attribute\AllowedMethods;
use Zero\Core\Request;
use Zero\Core\HTTPError;

class AllowedMethodsTest extends ZeroTestCase
{
    private function setMethod(string $method): void
    {
        // AllowedMethods reads Request::$method
        Request::$method = $method;
    }

    /* ── Allowed methods pass ── */

    public function testAllowsMatchingMethod(): void
    {
        $this->setMethod('POST');

        $attr = new AllowedMethods(['POST']);
        $this->assertTrue($attr->handler());
    }

    public function testAllowsOneOfMultipleMethods(): void
    {
        $this->setMethod('GET');

        $attr = new AllowedMethods(['GET', 'POST']);
        $this->assertTrue($attr->handler());
    }

    public function testNormalizesMethodCase(): void
    {
        $this->setMethod('post');

        $attr = new AllowedMethods(['POST']);
        $this->assertTrue($attr->handler());
    }

    public function testAcceptsStringArgument(): void
    {
        $this->setMethod('DELETE');

        $attr = new AllowedMethods('DELETE');
        $this->assertTrue($attr->handler());
    }

    /* ── Disallowed methods throw 405 ── */

    public function testThrows405ForDisallowedMethod(): void
    {
        $this->setMethod('DELETE');

        $attr = new AllowedMethods(['GET', 'POST']);

        try {
            $attr->handler();
            $this->fail('Expected HTTPError was not thrown');
        } catch (HTTPError $e) {
            $this->assertSame(405, $e->getCode());
            $this->assertStringContainsString('GET, POST', $e->detail);
        }
    }

    public function testThrows405ForGetWhenOnlyPostAllowed(): void
    {
        $this->setMethod('GET');

        $attr = new AllowedMethods('POST');

        $this->expectException(HTTPError::class);
        $this->expectExceptionCode(405);
        $attr->handler();
    }
}
