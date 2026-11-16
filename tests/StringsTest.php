<?php

namespace Zero\Tests\Core;

use Zero\Core\Application;
use Zero\Core\Console;
use Zero\Core\Modules;
use Zero\Core\Strings;

/**
 * Strings defines wording constants from .ini files: the site's
 * app/strings/*.ini first, a module's strings/*.ini as defaults after.
 * Constants are process-global, so every test uses key names of its own.
 */
class StringsTest extends ZeroTestCase
{
    private string $base;
    private string $site;
    private string $modules;

    protected function setUp(): void
    {
        parent::setUp();
        $this->base = sys_get_temp_dir() . '/zerotest-strings-' . bin2hex(random_bytes(4));
        $this->site = $this->base . '/site/';
        $this->modules = $this->base . '/modules/';
        mkdir($this->site, 0777, true);
        mkdir($this->modules . 'Zerotestmod/strings', 0777, true);
        Modules::configure([$this->modules], null);
    }

    protected function tearDown(): void
    {
        $this->remove($this->base);
        zeroTestConfigureModules();
        parent::tearDown();
    }

    private function remove(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);
            return;
        }
        if (is_dir($path)) {
            foreach (array_diff(scandir($path), ['.', '..']) as $entry) {
                $this->remove($path . '/' . $entry);
            }
            rmdir($path);
        }
    }

    private function moduleIni(string $file, string $body): void
    {
        file_put_contents($this->modules . 'Zerotestmod/strings/' . $file, $body);
    }

    public function testSiteValueWinsOverModuleDefault(): void
    {
        file_put_contents($this->site . 'site.ini', "ZEROTEST_STR_WINS=\"from the site\"\n");
        $this->moduleIni('zerotestmod.ini', "ZEROTEST_STR_WINS=\"from the module\"\n");

        Strings::loadSite($this->site);
        Strings::loadModule('Zerotestmod');

        $this->assertSame('from the site', ZEROTEST_STR_WINS);
    }

    public function testModuleDefaultIsDefinedWhenSiteHasNone(): void
    {
        $this->moduleIni('zerotestmod.ini', "ZEROTEST_STR_DEFAULT=\"the default\"\n");

        Strings::loadModule('Zerotestmod');

        $this->assertSame('the default', ZEROTEST_STR_DEFAULT);
    }

    public function testModuleNameAcceptsAnySpelling(): void
    {
        $this->moduleIni('zerotestmod.ini', "ZEROTEST_STR_SPELLING=\"found\"\n");

        Strings::loadModule('zerotestmod');

        $this->assertSame('found', ZEROTEST_STR_SPELLING);
    }

    public function testLoadModuleTwiceRaisesNothing(): void
    {
        $this->moduleIni('zerotestmod.ini', "ZEROTEST_STR_TWICE=\"once\"\n");
        $warnings = [];
        set_error_handler(static function (int $no, string $msg) use (&$warnings): bool {
            $warnings[] = $msg;
            return true;
        });
        try {
            Strings::loadModule('Zerotestmod');
            Strings::loadModule('Zerotestmod');
            Strings::loadSite($this->site);
            Strings::loadSite($this->site);
        } finally {
            restore_error_handler();
        }

        $this->assertSame([], $warnings);
        $this->assertSame('once', ZEROTEST_STR_TWICE);
    }

    public function testEveryIniInTheFolderIsRead(): void
    {
        $this->moduleIni('a.ini', "ZEROTEST_STR_FILE_A=\"a\"\n");
        $this->moduleIni('b.ini', "ZEROTEST_STR_FILE_B=\"b\"\n");
        $this->moduleIni('notes.txt', "ZEROTEST_STR_FILE_TXT=\"no\"\n");

        Strings::loadModule('Zerotestmod');

        $this->assertSame('a', ZEROTEST_STR_FILE_A);
        $this->assertSame('b', ZEROTEST_STR_FILE_B);
        $this->assertFalse(defined('ZEROTEST_STR_FILE_TXT'));
    }

    public function testMissingFoldersAreNoOps(): void
    {
        $before = count(get_defined_constants(true)['user'] ?? []);

        Strings::loadSite($this->base . '/no-such-folder/');
        Strings::loadModule('Zerotestnostrings');
        mkdir($this->modules . 'Zerotestnostrings');
        Strings::loadModule('Zerotestnostrings');

        $this->assertSame($before, count(get_defined_constants(true)['user'] ?? []));
    }

    public function testDisabledModuleLoadsNothing(): void
    {
        $ini = $this->base . '/modules.ini';
        file_put_contents($ini, "[enabled]\nZerotestmod = off\n");
        Modules::configure([$this->modules], $ini);
        $this->moduleIni('zerotestmod.ini', "ZEROTEST_STR_DISABLED=\"never\"\n");

        Strings::loadModule('Zerotestmod');

        $this->assertFalse(defined('ZEROTEST_STR_DISABLED'));
    }

    public function testPunctuationRoundTripsWhenTheValueIsWrappedInDoubleQuotes(): void
    {
        // The rule: wrap the whole value in double quotes. Inside them the
        // text is literal: a single quote, =, ; and double quotes included.
        $ini = <<<'INI'
ZEROTEST_STR_APOS="We'll email you a link."
ZEROTEST_STR_EQSEMI="a = b; c"
ZEROTEST_STR_DQUOTE="He said "hi"; then left"
ZEROTEST_STR_ENDQUOTE="ends with a quote""
ZEROTEST_STR_SPACES="  padded  "
INI;
        $this->moduleIni('zerotestmod.ini', $ini . "\n");

        Strings::loadModule('Zerotestmod');

        $this->assertSame("We'll email you a link.", ZEROTEST_STR_APOS);
        $this->assertSame('a = b; c', ZEROTEST_STR_EQSEMI);
        $this->assertSame('He said "hi"; then left', ZEROTEST_STR_DQUOTE);
        $this->assertSame('ends with a quote"', ZEROTEST_STR_ENDQUOTE);
        $this->assertSame('  padded  ', ZEROTEST_STR_SPACES);
    }

    public function testSiteNameFallbacksAreDefinedInAFreshProcess(): void
    {
        // SITE_NAME and SITE_SHORT_NAME are constants of the whole process,
        // so each case runs in a process of its own.
        $this->assertSame('Zero|Zero', $this->fallbacks(''));
        $this->assertSame('Acme|Acme', $this->fallbacks("SITE_NAME=\"Acme\"\n"));
        $this->assertSame('Acme Corp|Acme', $this->fallbacks("SITE_NAME=\"Acme Corp\"\nSITE_SHORT_NAME=\"Acme\"\n"));
        $this->assertSame('Short|Short', $this->fallbacks("SITE_SHORT_NAME=\"Short\"\n"));
    }

    private function warnings(): array
    {
        return array_column(array_filter(
            Console::getMessages(),
            static fn (array $m): bool => $m['level'] === 'WARN'
        ), 'message');
    }

    private function defineOnce(string $constant, mixed $value, string $file): void
    {
        $method = new \ReflectionMethod(Application::class, 'defineOnce');
        $method->setAccessible(true);
        $method->invoke(null, $constant, $value, $file);
    }

    public function testSiteFallbacksAreDefinedAfterTheConfigFiles(): void
    {
        // A site that has SITE_NAME only in app/config/*.ini keeps that value:
        // the fallbacks run after the config files are read.
        $src = file_get_contents(dirname(__DIR__) . '/Application.php');
        $config = strpos($src, 'self::configFiles(ZERO_ROOT');
        $fallback = strpos($src, 'Strings::defineSiteFallbacks()');
        $this->assertNotFalse($config);
        $this->assertNotFalse($fallback);
        $this->assertGreaterThan($config, $fallback);
    }

    public function testAKeyAStringsFileDefinedTwiceIsSkippedQuietly(): void
    {
        file_put_contents($this->site . 'site.ini', "ZEROTEST_STR_QUIET=\"site\"\n");
        $this->moduleIni('zerotestmod.ini', "ZEROTEST_STR_QUIET=\"module\"\n");
        Console::clearMessages();

        Strings::loadSite($this->site);
        Strings::loadModule('Zerotestmod');

        $this->assertSame('site', ZEROTEST_STR_QUIET);
        $this->assertSame([], $this->warnings());
    }

    public function testAKeyDefinedElsewhereIsSkippedWithAWarningNamingKeyAndFile(): void
    {
        define('ZEROTEST_STR_PREDEFINED', 'earlier');
        file_put_contents($this->site . 'site.ini', "ZEROTEST_STR_PREDEFINED=\"site\"\n");
        Console::clearMessages();

        Strings::loadSite($this->site);

        $this->assertSame('earlier', ZEROTEST_STR_PREDEFINED);
        $warnings = implode("\n", $this->warnings());
        $this->assertStringContainsString('ZEROTEST_STR_PREDEFINED', $warnings);
        $this->assertStringContainsString($this->site . 'site.ini', $warnings);
    }

    public function testAnInvalidKeyInAStringsFileIsSkippedWithAWarning(): void
    {
        file_put_contents($this->site . 'site.ini', "ZEROTEST_STR_BAD::KEY=\"x\"\n1ZEROTEST_STR_BAD=\"y\"\nZEROTEST_STR_GOOD_AFTER=\"z\"\n");
        Console::clearMessages();

        Strings::loadSite($this->site);

        $this->assertSame('z', ZEROTEST_STR_GOOD_AFTER);
        $this->assertFalse(defined('1ZEROTEST_STR_BAD'));
        $warnings = implode("\n", $this->warnings());
        $this->assertStringContainsString('ZEROTEST_STR_BAD::KEY', $warnings);
        $this->assertStringContainsString('1ZEROTEST_STR_BAD', $warnings);
    }

    public function testAMalformedStringsFileIsSkippedAndTheNextOneIsRead(): void
    {
        file_put_contents($this->site . 'a.ini', "[unclosed\nZEROTEST_STR_NEVER=\"x\n");
        file_put_contents($this->site . 'b.ini', "ZEROTEST_STR_AFTER_BAD=\"ok\"\n");
        Console::clearMessages();

        Strings::loadSite($this->site);

        $this->assertFalse(defined('ZEROTEST_STR_NEVER'));
        $this->assertSame('ok', ZEROTEST_STR_AFTER_BAD);
        $this->assertStringContainsString($this->site . 'a.ini', implode("\n", $this->warnings()));
    }

    public function testDefineOnceKeepsTheExistingValueAndLogsTheRepeat(): void
    {
        define('ZEROTEST_DO_KEPT', 'first');
        Console::clearMessages();

        $this->defineOnce('ZEROTEST_DO_KEPT', 'second', '/some/file.ini');

        $this->assertSame('first', ZEROTEST_DO_KEPT);
        $warnings = implode("\n", $this->warnings());
        $this->assertStringContainsString('ZEROTEST_DO_KEPT', $warnings);
        $this->assertStringContainsString('/some/file.ini', $warnings);
    }

    public function testDefineOnceDefinesAnUndefinedConstant(): void
    {
        $this->defineOnce('ZEROTEST_DO_NEW', 'value', '/some/file.ini');
        $this->assertSame('value', ZEROTEST_DO_NEW);
    }

    public function testDefineOnceSkipsAnInvalidKeyWithAWarning(): void
    {
        Console::clearMessages();

        $this->defineOnce('ZEROTEST_DO::BAD', 'x', '/some/file.ini');
        $this->defineOnce('ZEROTEST-DO-DASH', 'x', '/some/file.ini');

        $this->assertFalse(defined('ZEROTEST-DO-DASH'));
        $warnings = implode("\n", $this->warnings());
        $this->assertStringContainsString('ZEROTEST_DO::BAD', $warnings);
        $this->assertStringContainsString('ZEROTEST-DO-DASH', $warnings);
    }

    public function testAnInvalidKeyInAConfigFileIsSkippedAndTheRestIsRead(): void
    {
        $dir = $this->base . '/config/';
        mkdir($dir);
        file_put_contents($dir . 'x.ini', "ZEROTEST_CFG::BAD=1\nZEROTEST_CFG_OK=2\n");
        Console::clearMessages();

        foreach (Application::configFiles($dir, $this->base . '/no-skeleton/') as $file) {
            foreach (parse_ini_file($file, false, INI_SCANNER_RAW) as $k => $v) {
                $this->defineOnce($k, $v, $file);
            }
        }

        $this->assertSame('2', ZEROTEST_CFG_OK);
        $this->assertStringContainsString('ZEROTEST_CFG::BAD', implode("\n", $this->warnings()));
    }

    private function fallbacks(string $siteIni): string
    {
        $dir = $this->base . '/fallback-' . bin2hex(random_bytes(3)) . '/';
        mkdir($dir);
        if ($siteIni !== '') {
            file_put_contents($dir . 'site.ini', $siteIni);
        }
        $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
        $code = 'require ' . var_export($autoload, true) . ';'
            . '\Zero\Core\Strings::loadSite(' . var_export($dir, true) . ');'
            . '\Zero\Core\Strings::defineSiteFallbacks();'
            . 'echo SITE_NAME, "|", SITE_SHORT_NAME;';
        return trim((string) shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code) . ' 2>&1'));
    }
}
