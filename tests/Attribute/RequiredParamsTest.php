<?php

namespace Zero\Tests\Core\Attribute;

use Zero\Tests\Core\ZeroTestCase;
use Zero\Core\Attribute\RequiredParams;
use Zero\Core\HTTPError;

class RequiredParamsTest extends ZeroTestCase
{
    /* ── All params present ── */

    public function testPassesWhenPostParamsPresent(): void
    {
        $_POST = ['text' => 'hello', 'category' => 'news'];

        $attr = new RequiredParams(['text', 'category']);
        $this->assertTrue($attr->handler());
    }

    public function testPassesWhenGetParamsPresent(): void
    {
        $_GET = ['q' => 'search term'];

        $attr = new RequiredParams('q');
        $this->assertTrue($attr->handler());
    }

    public function testPassesWithMixOfPostAndGet(): void
    {
        $_POST = ['text' => 'hello'];
        $_GET = ['page' => '1'];

        $attr = new RequiredParams(['text', 'page']);
        $this->assertTrue($attr->handler());
    }

    /* ── Missing params throw 400 ── */

    public function testThrows400WhenParamMissing(): void
    {
        $_POST = [];
        $_GET = [];

        $attr = new RequiredParams(['text']);

        try {
            $attr->handler();
            $this->fail('Expected HTTPError was not thrown');
        } catch (HTTPError $e) {
            $this->assertSame(400, $e->getCode());
            $this->assertStringContainsString('text', $e->detail);
        }
    }

    public function testThrows400WithMultipleMissingParams(): void
    {
        $_POST = ['text' => 'hello'];
        $_GET = [];

        $attr = new RequiredParams(['text', 'category', 'author']);

        try {
            $attr->handler();
            $this->fail('Expected HTTPError was not thrown');
        } catch (HTTPError $e) {
            $this->assertSame(400, $e->getCode());
            $this->assertStringContainsString('category', $e->detail);
            $this->assertStringContainsString('author', $e->detail);
            // 'text' was present — should not be listed
            $this->assertStringNotContainsString('text', $e->detail);
        }
    }

    public function testAcceptsSingleStringParam(): void
    {
        $_POST = [];
        $_GET = [];

        $attr = new RequiredParams('name');

        $this->expectException(HTTPError::class);
        $this->expectExceptionCode(400);
        $attr->handler();
    }
}
