<?php
namespace Zero\Module\User\Tests;

use PHPUnit\Framework\TestCase;
use Zero\Core\Strings;

/** User's sign-in wording comes from strings/user.ini. */
class StringsTest extends TestCase
{
    public function testSignInWordingIsDefinedWithTheSiteNameAsAPlaceholder(): void
    {
        Strings::loadModule('User');
        $this->assertTrue(defined('USER_LOGIN_HEADING'));
        $this->assertStringContainsString('%s', USER_LOGIN_HEADING);
        $this->assertStringNotContainsString('<', USER_LOGIN_BLURB . USER_LOGIN_PRIVACY . USER_LOGIN_PRIVACY_LINK);
        $this->assertDoesNotMatchRegularExpression('/Unisolu/', file_get_contents(dirname(__DIR__) . '/strings/user.ini'));
    }
}
