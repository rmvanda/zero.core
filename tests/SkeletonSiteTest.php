<?php

namespace Zero\Tests\Core;

/**
 * zero-init.sh builds a working site folder from core/skeleton/ alone, and
 * that site answers a request for / without a database and without anything
 * from outside core.
 */
final class SkeletonSiteTest extends ZeroTestCase
{
    private string $tmp;
    private string $site;
    private static string $core;

    protected function setUp(): void
    {
        parent::setUp();
        self::$core = realpath(__DIR__ . '/..');
        $this->tmp  = sys_get_temp_dir() . '/zerotest-skelsite-' . getmypid();
        $this->wipe($this->tmp);
        $this->site = $this->tmp . '/site';
        mkdir($this->site, 0777, true);
        // the site has core/ directly inside its root, as a submodule would
        symlink(self::$core, $this->site . '/core');
    }

    protected function tearDown(): void
    {
        $this->wipe($this->tmp);
        parent::tearDown();
    }

    private function wipe(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);
            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (array_diff(scandir($path), ['.', '..']) as $entry) {
            $this->wipe($path . '/' . $entry);
        }
        rmdir($path);
    }

    /** @return array{0:int,1:string} exit code and combined output */
    private function init(array $args = []): array
    {
        $cmd = escapeshellarg($this->site . '/core/skeleton/zero-init.sh');
        foreach ($args as $a) {
            $cmd .= ' ' . escapeshellarg($a);
        }
        exec($cmd . ' 2>&1', $out, $code);
        return [$code, implode("\n", $out)];
    }

    private function files(string $dir): array
    {
        $found = [];
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $f) {
            // core/ is the symlink to the framework itself; the result of init is everything else
            if ($f->isFile() && !str_starts_with($f->getPathname(), $this->site . '/core/')) {
                $found[] = $f->getPathname();
            }
        }
        return $found;
    }

    public function testInitBuildsTheSiteFolderBesideCore(): void
    {
        [$code, $out] = $this->init();
        $this->assertSame(0, $code, $out);
        foreach (['app/config', 'app/frontend/frame', 'app/frontend/www/assets/css', 'app/strings',
                  'modules/Index/view', 'entity', 'storage/cache'] as $dir) {
            $this->assertDirectoryExists($this->site . '/' . $dir);
        }
        foreach (['app/modules.ini', 'app/strings/site.ini', 'app/frontend/www/index.php',
                  'app/frontend/www/.htaccess', 'app/frontend/www/assets/css/main.css',
                  'app/config/config.ini', 'app/config/database.ini', 'modules/Index/Index.php'] as $file) {
            $this->assertFileExists($this->site . '/' . $file);
        }
        $this->assertStringContainsString('core.sql', $out);
        $this->assertStringContainsString('database.ini', $out);
    }

    public function testWithoutADomainThePlaceholderIsSaidSo(): void
    {
        [$code, $out] = $this->init();
        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('placeholder', $out);
        $this->assertStringContainsString('PRIMARY_DOMAIN', $out);
        $this->assertStringContainsString('app/config/config.ini', $out);

        $this->wipe($this->site . '/app');
        [$code, $out] = $this->init([$this->site, 'skeleton.test']);
        $this->assertSame(0, $code, $out);
        $this->assertStringNotContainsString('placeholder', $out, 'a given domain is no placeholder');
    }

    public function testAMissingEntitySubmoduleIsWarnedAboutNotFatal(): void
    {
        // a core copy without entity/Entity.php, as a submodule add without --recursive leaves it
        unlink($this->site . '/core');
        $copy = $this->tmp . '/corecopy';
        mkdir($copy);
        foreach (['Application.php', 'skeleton'] as $item) {
            exec('cp -r ' . escapeshellarg(self::$core . '/' . $item) . ' ' . escapeshellarg($copy . '/' . $item));
        }
        symlink($copy, $this->site . '/core');
        [$code, $out] = $this->init([$this->site, 'skeleton.test']);
        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('git submodule update --init --recursive', $out);

        $this->wipe($this->site . '/app');
        unlink($this->site . '/core');
        symlink(self::$core, $this->site . '/core');
        [, $out] = $this->init([$this->site, 'skeleton.test']);
        $this->assertStringNotContainsString('submodule update', $out, 'no warning when entity/ is there');
    }

    public function testStorageAndItsCacheAreGroupWritable(): void
    {
        $this->init();
        foreach (['storage', 'storage/cache'] as $dir) {
            $this->assertSame('775', substr(sprintf('%o', fileperms($this->site . '/' . $dir)), -3), $dir);
        }
    }

    public function testNothingInTheResultNeedsAnythingOutsideCore(): void
    {
        [$code, $out] = $this->init();
        $this->assertSame(0, $code, $out);
        foreach ($this->files($this->site) as $file) {
            $text = file_get_contents($file);
            $this->assertStringNotContainsString('shadow-component', $text, $file);
            $this->assertStringNotContainsString('shadowjs', $text, $file);
            $this->assertStringNotContainsStringIgnoringCase('unisolu', $text, $file);
            // a URL may only name the site's own host (a placeholder or a variable)
            preg_match_all('~https?://([^/\s"\'<>]*)~', $text, $m);
            foreach ($m[1] as $host) {
                $this->assertMatchesRegularExpression('~^(\$|%\{|\{\{)~', $host, "$file links to $host");
            }
        }
        $this->assertFileDoesNotExist($this->site . '/app/frontend/www/shadow-component');
    }

    public function testSecondRunKeepsWhatTheSiteEdited(): void
    {
        $this->init();
        file_put_contents($this->site . '/app/config/database.ini', "HOST=edited\n");
        file_put_contents($this->site . '/app/strings/site.ini', "SITE_NAME=\"Mine\"\n");
        file_put_contents($this->site . '/modules/Index/Index.php', "<?php // mine\n");
        [$code, $out] = $this->init();
        $this->assertSame(0, $code, $out);
        $this->assertSame("HOST=edited\n", file_get_contents($this->site . '/app/config/database.ini'));
        $this->assertSame("SITE_NAME=\"Mine\"\n", file_get_contents($this->site . '/app/strings/site.ini'));
        $this->assertSame("<?php // mine\n", file_get_contents($this->site . '/modules/Index/Index.php'));
        $this->assertStringContainsString('exists', $out);
    }

    public function testATargetOtherThanTheCoreParentIsBuilt(): void
    {
        $other = $this->tmp . '/other';
        [$code, $out] = $this->init([$other]);
        $this->assertSame(0, $code, $out);
        $this->assertFileExists($other . '/app/frontend/www/index.php');
        $this->assertFileExists($other . '/modules/Index/Index.php');
    }

    public function testTheFourFramePiecesAreValidPhp(): void
    {
        foreach (['head', 'header', 'sideNav', 'footer'] as $piece) {
            $file = self::$core . '/skeleton/frontend/frame/' . $piece . '.php';
            $this->assertFileExists($file);
            exec('php -l ' . escapeshellarg($file) . ' 2>&1', $out, $code);
            $this->assertSame(0, $code, implode("\n", $out));
        }
    }

    /** Boot the site's index.php for $uri in a separate process; returns its output. */
    private function request(string $uri, ?array $session = null, ?array $capabilities = null): string
    {
        $driver = $this->tmp . '/boot.php';
        $sess = var_export($session, true);
        $index = $this->site . '/app/frontend/www/index.php';
        if ($capabilities !== null) {
            // The capability check reads the database; give the session's user the
            // capabilities named here before the request runs, and nothing else changes.
            $caps = var_export($capabilities, true);
            $index = $this->site . '/app/frontend/www/index-capabilities.php';
            $stub = '$u = \\Zero\\Core\\Session::user(); '
                . '$p = new \\ReflectionProperty($u, "capabilities"); $p->setValue($u, ' . $caps . ');' . "\n\n" . '$app->run(';
            file_put_contents($index, str_replace('$app->run(', $stub, file_get_contents($this->site . '/app/frontend/www/index.php')));
        }
        file_put_contents($driver, <<<PHP
<?php
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', {$this->q($this->tmp . '/php-errors.log')});
ini_set('session.save_path', {$this->q($this->tmp)});
// the log file of a live server is not writable here: keep the request's log in the temp folder
require {$this->q(self::$core . '/Console.php')};
\Zero\Core\Console::\$defaultLogFile = {$this->q($this->tmp . '/zero.log')};
\$_SERVER['REQUEST_URI'] = {$this->q($uri)};
\$_SERVER['REQUEST_METHOD'] = 'GET';
\$_SERVER['HTTP_HOST'] = 'skeleton.test';
\$_SERVER['SERVER_NAME'] = 'skeleton.test';
\$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
\$_SERVER['HTTP_ACCEPT'] = 'text/html';
\$_SERVER['SCRIPT_NAME'] = '/index.php';
if ($sess !== NULL) {
    session_start();
    \$_SESSION = $sess;
}
include {$this->q($index)};
PHP);
        exec('php ' . escapeshellarg($driver) . ' 2>&1', $out);
        return implode("\n", $out);
    }

    /**
     * PHP errors the request raised in the skeleton's files or the site's own
     * (what this task is responsible for). Errors raised inside core's other
     * classes are logged too and are not asserted on here.
     */
    private function ownErrors(): array
    {
        $log = $this->tmp . '/php-errors.log';
        $lines = is_file($log) ? file($log, FILE_IGNORE_NEW_LINES) : [];
        $own = '~ in (\S*/skeleton/\S*|' . preg_quote($this->site, '~') . '/(app|modules)/\S*) on line~';
        return array_values(array_filter($lines, fn($l) => preg_match($own, $l) === 1));
    }

    private function q(string $s): string
    {
        return var_export($s, true);
    }

    public function testTheHomePageRendersTheFramesAndTheIndexView(): void
    {
        [$code, $out] = $this->init([$this->site, 'skeleton.test']);
        $this->assertSame(0, $code, $out);
        $html = $this->request('/');
        $this->assertDoesNotMatchRegularExpression('~(Warning|Notice|Deprecated|Fatal error|Parse error|Uncaught)~', $html);
        $this->assertSame([], $this->ownErrors());
        $this->assertStringContainsString('<title>', $html);
        $this->assertStringContainsString('<a class="site-brand" href="/">skeleton.test</a>', $html, 'SITE_NAME comes from app/strings/site.ini, which init names after the domain');
        $this->assertStringContainsString('Your Zero Framework project is up and running', $html);
        $this->assertStringContainsString('/assets/css/main.css', $html);
        $this->assertStringContainsString('href="/user/login"', $html, 'anonymous visitors are offered sign in');
        $this->assertStringContainsString('</html>', $html);
    }

    public function testASiteWithoutItsOwnStringsFileStillRenders(): void
    {
        $this->init();
        unlink($this->site . '/app/strings/site.ini');
        $html = $this->request('/');
        $this->assertDoesNotMatchRegularExpression('~(Warning|Notice|Deprecated|Fatal error|Uncaught)~', $html);
        $this->assertSame([], $this->ownErrors());
        $this->assertStringContainsString('Zero', $html);
        $this->assertStringContainsString('Sign in', $html);
    }

    public function testTheLoginPageRendersOnABareSiteWithoutWarnings(): void
    {
        [$code, $out] = $this->init([$this->site, 'skeleton.test']);
        $this->assertSame(0, $code, $out);
        // a site that has none of the partials of its own (init copies them; a site may delete them)
        foreach (glob($this->site . '/app/frontend/frame/_*.php') as $partial) {
            unlink($partial);
        }
        $html = $this->request('/user/login');
        $this->assertDoesNotMatchRegularExpression('~(Warning|Notice|Deprecated|Fatal error|Parse error|Uncaught)~', $html);
        $this->assertSame([], $this->ownErrors());
        $log = $this->tmp . '/php-errors.log';
        $partialErrors = array_filter(is_file($log) ? file($log, FILE_IGNORE_NEW_LINES) : [], fn($l) => preg_match('~PHP (Warning|Notice|Deprecated|Fatal error).*(_oauth|_google-signin)~', $l) === 1);
        $this->assertSame([], array_values($partialErrors), 'no warning for a partial of the login page');
        $this->assertStringContainsString('/auth/signin', $html, 'the email sign-in form is there');
        $this->assertStringContainsString('</html>', $html);
    }

    public function testThePartialsOfTheSiteWinOverCoresOwn(): void
    {
        [$code, $out] = $this->init([$this->site, 'skeleton.test']);
        $this->assertSame(0, $code, $out);
        file_put_contents($this->site . '/app/frontend/frame/_oauth-buttons.php', '<p>site-own-buttons</p>');
        $html = $this->request('/user/login');
        $this->assertStringContainsString('site-own-buttons', $html);
        $this->assertSame([], $this->ownErrors());
    }

    public function testAPartialNobodyHasIsNull(): void
    {
        $dirs = [$this->tmp . '/none/'];
        $this->assertNull(\Zero\Core\Response::framePieceFile('_no-such-partial', $dirs));
        $this->assertSame(
            self::$core . '/skeleton/frontend/frame/_oauth-brand.php',
            \Zero\Core\Response::framePieceFile('_oauth-brand', $dirs)
        );
        mkdir($dirs[0], 0777, true);
        file_put_contents($dirs[0] . '_oauth-brand.php', '<?php');
        $this->assertSame($dirs[0] . '_oauth-brand.php', \Zero\Core\Response::framePieceFile('_oauth-brand', $dirs));
    }

    public function testASiteStringOverridesCoresDefaultWording(): void
    {
        $this->init([$this->site, 'skeleton.test']);
        file_put_contents($this->site . '/app/strings/site.ini', "SITE_NAME=\"Mine\"\nSITE_SIGN_IN=\"Log in please\"\n");
        $html = $this->request('/');
        $this->assertStringContainsString('>Log in please<', $html);
        $this->assertStringContainsString('>Home<', $html, 'a key the site left out is core\'s default');
        $this->assertSame([], $this->ownErrors());
    }

    public function testARequestLogsNoNoticeWarningOrDeprecationFromCore(): void
    {
        $this->init([$this->site, 'skeleton.test']);
        foreach (['/', '/user/login'] as $uri) {
            @unlink($this->tmp . '/php-errors.log');
            $this->request($uri);
            $log = $this->tmp . '/php-errors.log';
            $lines = is_file($log) ? file($log, FILE_IGNORE_NEW_LINES) : [];
            $messages = array_values(array_filter($lines, fn($l) => preg_match('~\] PHP (Warning|Notice|Deprecated|Fatal error|Parse error):~', $l) === 1));
            $this->assertSame([], $messages, "$uri logged PHP messages");
        }
    }

    /** What establish() leaves behind for a signed-in user. */
    private function signedInSession(string $name): array
    {
        return [
            'user' => ['full_name' => $name, 'email' => 'eve@example.test', 'verified' => 1, 'pic' => null, 'id' => 7],
            'user_id' => 7, 'name' => $name, 'email' => 'eve@example.test',
            'verified' => 1, 'pic' => null, 'created_at' => time(),
        ];
    }

    public function testTheHeaderOfASignedInUser(): void
    {
        $this->init([$this->site, 'skeleton.test']);
        $html = $this->request('/', $this->signedInSession('Eve <b>Bold</b>'), []);
        $this->assertSame([], $this->ownErrors());
        $this->assertStringContainsString('href="/auth/logout"', $html, 'a sign-out link');
        $this->assertStringNotContainsString('href="/user/login"', $html, 'no sign-in link when signed in');
        $this->assertStringContainsString('Eve &lt;b&gt;Bold&lt;/b&gt;', $html, 'the name is escaped');
        $this->assertStringNotContainsString('<b>Bold</b>', $html);
        $this->assertStringNotContainsString('href="/admin"', $html, 'no admin link without allow.admin');
    }

    public function testTheAdminLinkIsOnlyForWhoHoldsAllowAdmin(): void
    {
        $this->init([$this->site, 'skeleton.test']);
        $html = $this->request('/', $this->signedInSession('Eve'), ['allow.admin']);
        $this->assertStringContainsString('href="/admin"', $html);
        $this->assertStringContainsString('href="/auth/logout"', $html);
    }
}
