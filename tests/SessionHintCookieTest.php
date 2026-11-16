<?php
namespace Zero\Tests\Core;

use PHPUnit\Framework\TestCase;

/**
 * The "returning user" hint cookie is named by SESSION_HINT_COOKIE when a site
 * defines it, and zero_user_id otherwise. A constant cannot be undefined, so
 * each case runs in a PHP process of its own.
 */
class SessionHintCookieTest extends TestCase
{
    /** The name Session::hintCookieName() answers in a fresh process; $define is PHP that runs first. */
    private function nameWith(string $define): string
    {
        $code = $define . ' require ' . var_export(dirname(__DIR__) . '/Session.php', true)
            . '; echo \Zero\Core\Session::hintCookieName();';
        exec('php -r ' . escapeshellarg($code) . ' 2>&1', $out);
        return implode("\n", $out);
    }

    public function testDefaultNameIsGeneric(): void
    {
        $this->assertSame('zero_user_id', $this->nameWith(''));
    }

    public function testTheSitesConstantNamesTheCookie(): void
    {
        $this->assertSame('mysite_user_id', $this->nameWith("define('SESSION_HINT_COOKIE', 'mysite_user_id');"));
    }

    public function testABlankOrUnusableValueFallsBackToTheDefault(): void
    {
        foreach (['', 'has space', 'semi;colon', 'new\nline', 'a=b'] as $bad) {
            $this->assertSame(
                'zero_user_id',
                $this->nameWith("define('SESSION_HINT_COOKIE', " . var_export($bad, true) . ");"),
                var_export($bad, true)
            );
        }
    }

    public function testNoCoreCodeStringNamesTheSite(): void
    {
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(__DIR__), \FilesystemIterator::SKIP_DOTS)) as $f) {
            $path = $f->getPathname();
            if ($f->getExtension() !== 'php' || strpos($path, '/tests/') !== false || strpos($path, '/vendor/') !== false) {
                continue;
            }
            foreach (token_get_all(file_get_contents($path)) as $tok) {
                if (is_array($tok) && $tok[0] === T_CONSTANT_ENCAPSED_STRING) {
                    $this->assertDoesNotMatchRegularExpression('/unisolu/i', $tok[1], $path);
                }
            }
        }
    }
}
