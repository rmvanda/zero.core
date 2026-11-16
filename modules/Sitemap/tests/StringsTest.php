<?php
namespace Zero\Module\Sitemap\Tests;

use PHPUnit\Framework\TestCase;
use Zero\Core\Strings;

/** The sitemap page's intro comes from strings/sitemap.ini. */
class StringsTest extends TestCase
{
    public function testIntroTakesTheSiteName(): void
    {
        Strings::loadModule('Sitemap');
        $this->assertTrue(defined('SITEMAP_INTRO'));
        $this->assertSame('Complete overview of all pages and modules on Acme.', sprintf(SITEMAP_INTRO, 'Acme'));
        $this->assertDoesNotMatchRegularExpression('/Unisolu/', file_get_contents(dirname(__DIR__) . '/strings/sitemap.ini'));
    }
}
