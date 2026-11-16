<?php

namespace Zero\Tests\Core;

use Zero\Lib\Mail\Mail;

/**
 * Mail written to a folder instead of sent (MAIL_CAPTURE_DIR): the files hold
 * live sign-in links, so they are private; an unusable folder fails closed.
 */
final class MailCaptureTest extends ZeroTestCase
{
    use SiteHarness;

    private string $site = '';
    private string $tmp;
    private int $umask;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir() . '/zerotest-mailcapture-' . getmypid();
        $this->wipe($this->tmp);
        mkdir($this->tmp, 0700);
        // the web server's usual umask: it must not widen what capture creates
        $this->umask = umask(022);
    }

    protected function tearDown(): void
    {
        umask($this->umask);
        $this->wipe($this->tmp);
        parent::tearDown();
    }

    public function testCapturedMailIsPrivateAndHoldsWhatWouldHaveBeenSent(): void
    {
        $dir = $this->tmp . '/storage/mail';
        $this->assertTrue(Mail::writeCaptured($dir, 'a@example.test', 'Hi', "From: x@example.test\r\n", 'BODY LINK', $file));
        $this->assertSame('0700', substr(sprintf('%o', fileperms($dir)), -4));
        $this->assertSame('0600', substr(sprintf('%o', fileperms($file)), -4));
        $text = file_get_contents($file);
        $this->assertStringContainsString("To: a@example.test\r\nSubject: Hi\r\n", $text);
        $this->assertStringContainsString('BODY LINK', $text);
    }

    public function testCapturingIsLoggedNotSilent(): void
    {
        \Zero\Core\Console::clearMessages();
        Mail::writeCaptured($this->tmp . '/m', 'a@example.test', 'Hi', '', 'b', $file);
        // the messages are kept whatever the log threshold is
        $this->assertStringContainsString('captured in ' . $file, $this->logged('WARN'));
    }

    public function testAnUnusableFolderFailsClosedWithoutAWarning(): void
    {
        file_put_contents($this->tmp . '/notafolder', 'x');
        \Zero\Core\Console::clearMessages();
        $this->assertFalse(Mail::writeCaptured($this->tmp . '/notafolder/mail', 'a@example.test', 'Hi', '', 'b', $file));
        $this->assertNull($file);
        $this->assertSame(['notafolder'], array_values(array_diff(scandir($this->tmp), ['.', '..'])));
        $this->assertStringContainsString('cannot be created', $this->logged('ERROR'));
    }

    private function logged(string $level): string
    {
        $text = '';
        foreach (\Zero\Core\Console::getMessages() as $m) {
            if ($m['level'] === $level) {
                $text .= $m['message'] . "\n";
            }
        }
        return $text;
    }
}
