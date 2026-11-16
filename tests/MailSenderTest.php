<?php

namespace Zero\Tests\Core;

use PHPUnit\Framework\TestCase;
use Zero\Lib\Mail\Mail;

/**
 * The address mail is sent from: MAIL_FROM when the site sets it, otherwise
 * noreply@ the site's primary domain.
 */
final class MailSenderTest extends TestCase
{
    public function testMailFromWinsWhenSet(): void
    {
        $this->assertSame('hello@example.invalid', Mail::chooseSender('hello@example.invalid', 'example.invalid'));
    }

    public function testBlankMailFromFallsBackToNoreplyAtThePrimaryDomain(): void
    {
        $this->assertSame('noreply@example.invalid', Mail::chooseSender('', 'example.invalid'));
        $this->assertSame('noreply@example.invalid', Mail::chooseSender(null, 'example.invalid'));
    }

    public function testNoSettingsAtAllGivesNoreplyAtLocalhost(): void
    {
        $this->assertSame('noreply@localhost', Mail::chooseSender(null, null));
    }
}
