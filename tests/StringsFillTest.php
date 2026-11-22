<?php
namespace Zero\Tests\Core;

use PHPUnit\Framework\TestCase;
use Zero\Core\Strings;

/**
 * Strings::fill() puts values into %s places and leaves every other percent
 * sign alone, so a site's wording cannot make a page fail.
 */
class StringsFillTest extends TestCase
{
    public function testFillsEachPlaceInOrder(): void
    {
        $this->assertSame('Sign in to Acme', Strings::fill('Sign in to %s', 'Acme'));
        $this->assertSame('a then b', Strings::fill('%s then %s', 'a', 'b'));
    }

    public function testAStrayPercentSignStaysAsWritten(): void
    {
        $this->assertSame('Save 50% at Acme', Strings::fill('Save 50% at %s', 'Acme'));
        $this->assertSame('100%d sure, 5%', Strings::fill('100%d sure, 5%'));
    }

    public function testAPlaceWithNoValueIsEmptyAndAValueIsNotReadAgain(): void
    {
        $this->assertSame('Hello ', Strings::fill('Hello %s'));
        $this->assertSame('%s', Strings::fill('%s', '%s'));
    }
}
