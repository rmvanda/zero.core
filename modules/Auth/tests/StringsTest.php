<?php
namespace Zero\Module\Auth\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Zero\Core\Strings;

/**
 * The six modules that ship in core carry no site's name in their source: the
 * site's name comes from SITE_SHORT_NAME and the wording from each module's
 * strings/*.ini. With SITE_SHORT_NAME = "Unisolu" the output is what it was
 * when the text was hard-coded.
 */
class StringsTest extends TestCase
{
    /** module folder => constant prefix */
    private const MODULES = [
        'Auth'          => 'AUTH_',
        'User'          => 'USER_',
        'Admin'         => 'ADMIN_',
        'AccessRequest' => 'ACCESS_REQUEST_',
        'Sitemap'       => 'SITEMAP_',
        'Push'          => 'PUSH_',
    ];

    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2) . '/';
        if (!defined('SITE_SHORT_NAME')) {
            define('SITE_SHORT_NAME', 'Unisolu');
        }
        if (SITE_SHORT_NAME !== 'Unisolu') {
            $this->markTestSkipped('Needs SITE_SHORT_NAME = Unisolu');
        }
        foreach (array_keys(self::MODULES) as $module) {
            Strings::loadModule($module);
        }
    }

    /** @return string[] source files of the module, not tests, docs or SQL */
    private function sourceFiles(string $module): array
    {
        $files = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(
            $this->root . $module, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            $path = $file->getPathname();
            if (str_contains($path, '/tests/') || str_contains($path, '/config/')
                || str_contains($path, '/node_modules/')
                || !in_array($file->getExtension(), ['php', 'ini', 'js', 'css'], true)) {
                continue;
            }
            $files[] = $path;
        }
        return $files;
    }

    /** The file's text without PHP comments. */
    private function code(string $path): string
    {
        $source = file_get_contents($path);
        if (!str_ends_with($path, '.php')) {
            return $source;
        }
        $out = '';
        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    continue;
                }
                $out .= $token[1];
            } else {
                $out .= $token;
            }
        }
        return $out;
    }

    public function testNoSourceNamesTheSite(): void
    {
        foreach (array_keys(self::MODULES) as $module) {
            foreach ($this->sourceFiles($module) as $path) {
                $this->assertDoesNotMatchRegularExpression(
                    '/Unisolu|Universal Solutions|unisolu\.com/',
                    $this->code($path),
                    "$path names the site"
                );
            }
        }
    }

    public function testEveryConstantAViewUsesIsDefinedByTheModulesStrings(): void
    {
        foreach (self::MODULES as $module => $prefix) {
            $defined = [];
            foreach (glob($this->root . $module . '/strings/*.ini') ?: [] as $ini) {
                $defined = array_merge($defined, array_keys(parse_ini_file($ini, false, INI_SCANNER_RAW)));
            }
            foreach ($this->sourceFiles($module) as $path) {
                if (!str_contains($path, '/view/')) {
                    continue;
                }
                preg_match_all('/\b' . $prefix . '[A-Z0-9_]+\b/', $this->code($path), $m);
                foreach (array_unique($m[0]) as $name) {
                    $this->assertContains($name, $defined, "$name in $path has no strings/*.ini entry");
                    $this->assertTrue(defined($name), "$name is not defined");
                }
            }
        }
    }

    public function testEveryDefinedKeyCarriesItsModulesPrefix(): void
    {
        foreach (self::MODULES as $module => $prefix) {
            foreach (glob($this->root . $module . '/strings/*.ini') ?: [] as $ini) {
                foreach (array_keys(parse_ini_file($ini, false, INI_SCANNER_RAW)) as $key) {
                    $this->assertStringStartsWith($prefix, $key);
                }
            }
        }
    }

    #[DataProvider('emailViews')]
    public function testEmailsRenderByteIdenticalToTheHardCodedOnes(string $view): void
    {
        $before = ($_SERVER['HOME'] ?? getenv('HOME')) . '/zero-bundle/before/modules/Auth/view/email/' . $view;
        if (!is_file($before)) {
            $this->markTestSkipped('No pre-change copy at ' . $before);
        }
        $render = function (string $file): string {
            $link = 'https://example.invalid/auth/token?t=a&b';
            ob_start();
            include $file;
            return ob_get_clean();
        };
        $this->assertSame($render($before), $render($this->root . 'Auth/view/email/' . $view));
    }

    public static function emailViews(): array
    {
        return [['login.php'], ['verify.php']];
    }

    public function testDefaultsReproduceTheHardCodedText(): void
    {
        $this->assertSame('Your Unisolu sign-in link', sprintf(AUTH_EMAIL_LOGIN_SUBJECT, SITE_SHORT_NAME));
        $this->assertSame('Confirm your email address', AUTH_EMAIL_VERIFY_SUBJECT);
        $this->assertSame('Click below to finish signing in to Unisolu.', sprintf(AUTH_TOKEN_CONFIRM_LEAD, SITE_SHORT_NAME));
        $this->assertSame('Sign in to Unisolu', sprintf(USER_LOGIN_HEADING, SITE_SHORT_NAME));
        $this->assertSame(
            'Signing in gives you access to more neat things. <br>Read the privacy policy <a href="/privacy">here</a>',
            USER_LOGIN_BLURB . ' <br>' . USER_LOGIN_PRIVACY . ' <a href="/privacy">' . USER_LOGIN_PRIVACY_LINK . '</a>'
        );
        $this->assertSame('Complete overview of all pages and modules on Unisolu.', sprintf(SITEMAP_INTRO, SITE_SHORT_NAME));
    }
}
