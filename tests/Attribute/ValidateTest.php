<?php

namespace Zero\Tests\Core\Attribute;

use Zero\Tests\Core\ZeroTestCase;
use Zero\Core\Attribute\Validate;
use Zero\Core\HTTPError;

class ValidateTest extends ZeroTestCase
{
    /* ── Built-in validators ── */

    public function testValidEmail(): void
    {
        $_POST = ['email' => 'user@example.com'];

        $attr = new Validate(['email' => 'email']);
        $this->assertTrue($attr->handler());
    }

    public function testInvalidEmail(): void
    {
        $_POST = ['email' => 'not-an-email'];

        $attr = new Validate(['email' => 'email']);

        $this->expectException(HTTPError::class);
        $this->expectExceptionCode(400);
        $attr->handler();
    }

    public function testValidUrl(): void
    {
        $_POST = ['website' => 'https://example.com'];

        $attr = new Validate(['website' => 'url']);
        $this->assertTrue($attr->handler());
    }

    public function testInvalidUrl(): void
    {
        $_POST = ['website' => 'not a url'];

        $attr = new Validate(['website' => 'url']);

        $this->expectException(HTTPError::class);
        $attr->handler();
    }

    public function testValidInt(): void
    {
        $_POST = ['age' => '25'];

        $attr = new Validate(['age' => 'int']);
        $this->assertTrue($attr->handler());
    }

    public function testInvalidInt(): void
    {
        $_POST = ['age' => 'twenty'];

        $attr = new Validate(['age' => 'int']);

        $this->expectException(HTTPError::class);
        $attr->handler();
    }

    /* ── Required rule ── */

    public function testRequiredPassesWhenPresent(): void
    {
        $_POST = ['name' => 'John'];

        $attr = new Validate(['name' => 'required']);
        $this->assertTrue($attr->handler());
    }

    public function testRequiredFailsWhenMissing(): void
    {
        $_POST = [];

        $attr = new Validate(['name' => 'required']);

        $this->expectException(HTTPError::class);
        $this->expectExceptionCode(400);
        $attr->handler();
    }

    public function testRequiredFailsWhenEmpty(): void
    {
        $_POST = ['name' => ''];

        $attr = new Validate(['name' => 'required']);

        $this->expectException(HTTPError::class);
        $attr->handler();
    }

    /* ── Parameterized rules ── */

    public function testInRulePasses(): void
    {
        $_POST = ['status' => 'active'];

        $attr = new Validate(['status' => 'in:active,pending,closed']);
        $this->assertTrue($attr->handler());
    }

    public function testInRuleFails(): void
    {
        $_POST = ['status' => 'deleted'];

        $attr = new Validate(['status' => 'in:active,pending,closed']);

        $this->expectException(HTTPError::class);
        $attr->handler();
    }

    public function testLengthRulePasses(): void
    {
        $_POST = ['title' => 'Hello World'];

        $attr = new Validate(['title' => 'length:1,255']);
        $this->assertTrue($attr->handler());
    }

    public function testLengthRuleFailsTooShort(): void
    {
        $_POST = ['title' => ''];

        // Required must be checked separately; length with empty + not required = skip
        // So test with a non-empty but short value
        $_POST = ['password' => 'ab'];

        $attr = new Validate(['password' => 'length:8,']);

        $this->expectException(HTTPError::class);
        $attr->handler();
    }

    public function testLengthRuleFailsTooLong(): void
    {
        $_POST = ['code' => 'ABCDEFGH'];

        $attr = new Validate(['code' => 'length:1,4']);

        $this->expectException(HTTPError::class);
        $attr->handler();
    }

    public function testRangeRulePasses(): void
    {
        $_POST = ['age' => '25'];

        $attr = new Validate(['age' => 'range:18,120']);
        $this->assertTrue($attr->handler());
    }

    public function testRangeRuleFailsTooLow(): void
    {
        $_POST = ['age' => '10'];

        $attr = new Validate(['age' => 'range:18,120']);

        $this->expectException(HTTPError::class);
        $attr->handler();
    }

    /* ── Regex rule ── */

    public function testRegexRulePasses(): void
    {
        $_POST = ['slug' => 'my-cool-post'];

        $attr = new Validate(['slug' => '/^[a-z0-9\-]+$/']);
        $this->assertTrue($attr->handler());
    }

    public function testRegexRuleFails(): void
    {
        $_POST = ['slug' => 'INVALID SLUG!'];

        $attr = new Validate(['slug' => '/^[a-z0-9\-]+$/']);

        $this->expectException(HTTPError::class);
        $attr->handler();
    }

    /* ── Multiple rules ── */

    public function testMultipleRulesAllPass(): void
    {
        $_POST = ['password' => 'securepassword123'];

        $attr = new Validate(['password' => ['required', 'length:8,']]);
        $this->assertTrue($attr->handler());
    }

    public function testMultipleRulesFirstFails(): void
    {
        $_POST = [];

        $attr = new Validate(['password' => ['required', 'length:8,']]);

        $this->expectException(HTTPError::class);
        $attr->handler();
    }

    /* ── Optional fields skip validation when empty ── */

    public function testOptionalFieldSkippedWhenEmpty(): void
    {
        $_POST = [];

        // 'email' rule without 'required' — empty value should pass
        $attr = new Validate(['email' => 'email']);
        $this->assertTrue($attr->handler());
    }

    /* ── getErrors() ── */

    public function testGetErrorsReturnsCollectedErrors(): void
    {
        $_POST = ['email' => 'bad'];

        $attr = new Validate(['email' => 'email']);

        try {
            $attr->handler();
        } catch (HTTPError $e) {
            // Expected
        }

        $errors = $attr->getErrors();
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('email', $errors[0]);
    }
}
