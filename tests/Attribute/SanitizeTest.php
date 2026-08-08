<?php

namespace Zero\Tests\Core\Attribute;

use Zero\Tests\Core\ZeroTestCase;
use Zero\Core\Attribute\Sanitize;

class SanitizeTest extends ZeroTestCase
{
    /* ── Default sanitization ── */

    public function testDefaultSanitizationStripsControlChars(): void
    {
        $_POST = ['title' => "Hello\x00World"];

        $attr = new Sanitize(['title']);
        $attr->handler();

        $this->assertSame('HelloWorld', $_POST['title']);
    }

    public function testDefaultSanitizationPreservesNormalText(): void
    {
        $_POST = ['title' => 'Hello World! This is a test.'];

        $attr = new Sanitize(['title']);
        $attr->handler();

        $this->assertSame('Hello World! This is a test.', $_POST['title']);
    }

    public function testDefaultSanitizationNormalizesWhitespace(): void
    {
        $_POST = ['title' => "Hello   \t  World"];

        $attr = new Sanitize(['title']);
        $attr->handler();

        $this->assertSame('Hello World', $_POST['title']);
    }

    public function testDefaultSanitizationTrims(): void
    {
        $_POST = ['title' => '  trimmed  '];

        $attr = new Sanitize(['title']);
        $attr->handler();

        $this->assertSame('trimmed', $_POST['title']);
    }

    /* ── Auto-detect ID fields ── */

    public function testAutoDetectsIdFieldsAsInteger(): void
    {
        $_POST = ['user_id' => '42abc'];

        $attr = new Sanitize(['user_id']);
        $attr->handler();

        $this->assertSame(42, $_POST['user_id']);
    }

    public function testAutoDetectsCaseInsensitiveId(): void
    {
        $_POST = ['entityID' => '99'];

        $attr = new Sanitize(['entityID']);
        $attr->handler();

        $this->assertSame(99, $_POST['entityID']);
    }

    /* ── filter_var filters ── */

    public function testFilterVarSanitization(): void
    {
        $_POST = ['email' => 'user@@example..com'];

        $attr = new Sanitize(['email' => FILTER_SANITIZE_EMAIL]);
        $attr->handler();

        // FILTER_SANITIZE_EMAIL removes invalid chars
        $sanitized = $_POST['email'];
        $this->assertIsString($sanitized);
    }

    /* ── Preset patterns ── */

    public function testSlugPreset(): void
    {
        $_POST = ['slug' => 'My Cool Post!'];

        $attr = new Sanitize(['slug' => 'slug']);
        $attr->handler();

        // Slug preset strips everything except a-z0-9 and hyphens
        $this->assertMatchesRegularExpression('/^[a-z0-9\-]*$/', $_POST['slug']);
    }

    public function testFilenamePreset(): void
    {
        $_POST = ['file' => 'my-file (copy).txt'];

        $attr = new Sanitize(['file' => 'filename']);
        $attr->handler();

        // Filename preset removes parens
        $this->assertStringNotContainsString('(', $_POST['file']);
        $this->assertStringNotContainsString(')', $_POST['file']);
    }

    public function testNumericPreset(): void
    {
        $_POST = ['count' => '42abc'];

        $attr = new Sanitize(['count' => 'numeric']);
        $attr->handler();

        $this->assertSame('42', $_POST['count']);
    }

    /* ── Regex pattern ── */

    public function testRegexPatternSanitization(): void
    {
        $_POST = ['code' => 'ABC-123-!!'];

        $attr = new Sanitize(['code' => '/[^A-Z0-9\-]/']);
        $attr->handler();

        $this->assertSame('ABC-123-', $_POST['code']);
    }

    /* ── GET parameters ── */

    public function testSanitizesGetParams(): void
    {
        $_GET = ['search' => "test\x00query"];

        $attr = new Sanitize(['search']);
        $attr->handler();

        $this->assertSame('testquery', $_GET['search']);
    }

    /* ── Both POST and GET ── */

    public function testSanitizesBothPostAndGet(): void
    {
        $_POST = ['name' => "post\x00val"];
        $_GET = ['name' => "get\x00val"];

        $attr = new Sanitize(['name']);
        $attr->handler();

        $this->assertSame('postval', $_POST['name']);
        $this->assertSame('getval', $_GET['name']);
    }

    /* ── Skips missing params ── */

    public function testSkipsMissingParams(): void
    {
        $_POST = [];
        $_GET = [];

        $attr = new Sanitize(['nonexistent']);
        $result = $attr->handler();

        $this->assertTrue($result);
    }

    /* ── Array values ── */

    public function testHandlesArrayValues(): void
    {
        $_POST = ['tags' => ['tag1', "tag2\x00", 'tag3']];

        $attr = new Sanitize(['tags']);
        $attr->handler();

        $this->assertCount(3, $_POST['tags']);
        $this->assertSame('tag2', $_POST['tags'][1]);
    }

    /* ── Return value ── */

    public function testHandlerReturnsTrue(): void
    {
        $_POST = ['text' => 'clean'];

        $attr = new Sanitize(['text']);
        $this->assertTrue($attr->handler());
    }
}
