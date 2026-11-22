<?php

namespace Zero\Tests\Core\Acceptance;

use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\TestCase;
use Zero\Tests\Core\MysqlCli;
use Zero\Tests\Core\SiteHarness;

/**
 * A bare site works: a folder built by core/skeleton/zero-init.sh beside core/,
 * an empty database loaded with core/sql/core.sql and a database.ini that names
 * it give a home page and an email-link sign-in with no other code.
 *
 * Every request is the site's own index.php run in a child PHP process
 * (request-driver.php), so what is tested is what a web server would run. The
 * database is the throwaway zerotest_bare_site and nothing else: the temp site's
 * database.ini names it, and the last test asks each request's own connection
 * which database it was on. No mail is sent: the temp site's config.ini turns on
 * MAIL_CAPTURE_DIR and the test reads the link a user would click from the
 * captured message.
 */
final class BareSiteTest extends TestCase
{
    use SiteHarness;

    private const DATABASE = 'zerotest_bare_site';
    private const DOMAIN   = 'bare.test';

    private static string $core;
    private static string $tmp;
    private static string $siteDir;
    private static ?string $skip = null;

    /** @var array<int,?string> the database each request's own connection reported */
    private static array $databases = [];
    private static int $requests = 0;

    /** the signed-in user, set by testEmailLinkSignIn */
    private static ?string $sid = null;
    private static string $email = 'ada@bare.test';

    // The harness trait reads $this->site.
    private string $site = '';

    /** Prefix of the temp folders this test makes; a folder with it is this test's own. */
    private const TMP_PREFIX = 'zerotest-baresite-';

    /** The lock that says a run is using the throwaway database; held until the class ends. */
    private static $lock = null;
    /** Whether this run created the database and the temp folder, so only it may remove them. */
    private static bool $ownsDatabase = false;
    private static bool $ownsTmp = false;

    public static function setUpBeforeClass(): void
    {
        self::$core = dirname(__DIR__, 2);
        self::$tmp  = sys_get_temp_dir() . '/' . self::TMP_PREFIX . getmypid();
        self::$siteDir = self::$tmp . '/site';

        // Before anything is dropped or created: one run at a time may use the database.
        $lock = @fopen(sys_get_temp_dir() . '/' . self::DATABASE . '.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            if ($lock !== false) {
                fclose($lock);
            }
            self::$skip = 'another run is using ' . self::DATABASE;
            return;
        }
        self::$lock = $lock;
        self::removeStaleSites();

        [$code, , $err] = MysqlCli::run('DROP DATABASE IF EXISTS ' . self::DATABASE);
        if ($code === 0) {
            [$code, , $err] = MysqlCli::run('CREATE DATABASE ' . self::DATABASE . ' CHARACTER SET utf8mb4');
        }
        if ($code !== 0) {
            // "Access denied ... to database" is a missing privilege; anything else is the client or its option file
            self::$skip = str_contains($err, 'to database')
                ? 'The mysql client\'s account may not create ' . self::DATABASE . ' (' . trim(strtok($err, "\n")) . ').'
                : 'The mysql client, or the root configuration it reads (~/.my.cnf), is missing or unusable, so ' . self::DATABASE . ' cannot be created (' . trim(strtok($err, "\n")) . ').';
            return;
        }
        self::$ownsDatabase = true;

        try {
            self::build();
        } catch (\Throwable $e) {
            self::cleanUp();
            throw $e;
        }
    }

    public static function tearDownAfterClass(): void
    {
        self::cleanUp();
        if (self::$lock !== null) {
            flock(self::$lock, LOCK_UN);
            fclose(self::$lock);
            self::$lock = null;
        }
    }

    /** Removes only what this run made; a run that did not get the lock removes nothing. */
    private static function cleanUp(): void
    {
        if (self::$ownsDatabase) {
            MysqlCli::run('DROP DATABASE IF EXISTS ' . self::DATABASE);
            self::$ownsDatabase = false;
        }
        if (self::$ownsTmp) {
            self::removeTree(self::$tmp);
            self::$ownsTmp = false;
        }
    }

    /**
     * Folders of killed runs: this test's own prefix, older than an hour. Called
     * only while the lock is held, so none of them belongs to a live run.
     */
    private static function removeStaleSites(): void
    {
        foreach (glob(sys_get_temp_dir() . '/' . self::TMP_PREFIX . '*') ?: [] as $old) {
            if ($old !== self::$tmp && is_dir($old) && !is_link($old) && filemtime($old) < time() - 3600) {
                self::removeTree($old);
            }
        }
    }

    private static function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);
            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (array_diff(scandir($path), ['.', '..']) as $entry) {
            self::removeTree($path . '/' . $entry);
        }
        rmdir($path);
    }

    protected function setUp(): void
    {
        if (self::$skip !== null) {
            $this->markTestSkipped(self::$skip);
        }
        $this->site = self::$siteDir;
    }

    /** Builds the site, loads core.sql and points the site at the throwaway database. */
    private static function build(): void
    {
        $self = new self('build');
        // a folder left by an earlier run with this pid (pids repeat) is not ours to reuse
        self::removeTree(self::$tmp);
        mkdir(self::$tmp, 0700, true);
        self::$ownsTmp = true;
        chmod(self::$tmp, 0700);
        mkdir(self::$siteDir, 0700);
        mkdir(self::$tmp . '/sessions', 0700);
        $self->site = self::$siteDir;
        symlink(self::$core, self::$siteDir . '/core');

        [$code, $out] = $self->init([self::$siteDir, self::DOMAIN]);
        if ($code !== 0) {
            throw new \RuntimeException("zero-init.sh failed:\n$out");
        }

        [$code, , $err] = MysqlCli::run('', self::DATABASE, self::$core . '/sql/core.sql');
        if ($code !== 0) {
            throw new \RuntimeException("core.sql did not load into an empty database:\n$err");
        }

        // The account this site (the one running the tests) connects with, and the throwaway
        // database's name. Read here, written only into the temp site's config.
        $real = parse_ini_file(dirname(self::$core) . '/app/config/database.ini', false, INI_SCANNER_RAW);
        $ini = '';
        foreach (['HOST' => $real['HOST'] ?? 'localhost', 'USER' => $real['USER'] ?? '', 'PASS' => $real['PASS'] ?? '', 'NAME' => self::DATABASE] as $key => $value) {
            // the framework reads its config raw: no quoting
            $ini .= $key . '=' . $value . "\n";
        }
        $db = self::$siteDir . '/app/config/database.ini';
        file_put_contents($db, $ini);
        chmod($db, 0600);
        unset($real, $ini);

        try {
            new \PDO('mysql:host=' . self::iniValue($db, 'HOST') . ';dbname=' . self::DATABASE,
                self::iniValue($db, 'USER'), self::iniValue($db, 'PASS'));
        } catch (\PDOException) {
            self::cleanUp();
            self::$skip = 'The site\'s own database account (database.ini) has no rights on ' . self::DATABASE . ', so a bare site cannot be tried. The missing privilege is ALL on zerotest_bare_site.*; grant it to run this test.';
            return;
        }

        // No mail leaves the test: the site writes it to storage/mail.
        $config = self::$siteDir . '/app/config/config.ini';
        file_put_contents($config, preg_replace('/^MAIL_CAPTURE_DIR=.*$/m', 'MAIL_CAPTURE_DIR=storage/mail', file_get_contents($config), -1, $n));
        if ($n !== 1) {
            throw new \RuntimeException('config.ini has no MAIL_CAPTURE_DIR line to turn on');
        }

        // Pre-flight, before any real request: ask the temp site which database it is on.
        $self->request('/', 'GET', [], null, '\\Zero\\Core\\Database::getConnection();');
        $on = self::$databases[self::$requests] ?? null;
        if ($on !== self::DATABASE) {
            throw new \RuntimeException('The temp site is connected to ' . var_export($on, true) . ', not ' . self::DATABASE . ': refusing to run any request');
        }
    }

    private static function iniValue(string $file, string $key): string
    {
        return (string) (parse_ini_file($file, false, INI_SCANNER_RAW)[$key] ?? '');
    }

    /**
     * One request. Returns what a browser would see: body, status, headers, and
     * the PHP messages the request logged. Sends the test's session cookie and
     * keeps the one the request ended with.
     *
     * @return array{body:string,status:int,headers:string[],errors:string[]}
     */
    private function request(string $uri, string $method = 'GET', array $post = [], ?string $sid = null, ?string $script = null): array
    {
        $n = ++self::$requests;
        $dir = self::$tmp . '/req';
        is_dir($dir) || mkdir($dir, 0700);
        $errorLog = $dir . '/php-errors.log';
        @unlink($errorLog);
        $meta = $dir . "/meta-$n.json";
        $specFile = $dir . "/spec-$n.json";
        file_put_contents($specFile, json_encode([
            'site' => self::$siteDir, 'uri' => $uri, 'method' => $method, 'post' => $post,
            'host' => self::DOMAIN, 'ip' => '203.0.113.9', 'accept' => 'text/html',
            'sid' => $sid, 'sessions' => self::$tmp . '/sessions',
            'errorLog' => $errorLog, 'consoleLog' => $dir . '/zero.log', 'meta' => $meta,
            'script' => $script,
        ]));
        $driver = __DIR__ . '/request-driver.php';
        $cmd = 'php ' . escapeshellarg($driver) . ' ' . escapeshellarg($specFile) . ' 2>&1';
        $body = (string) shell_exec($cmd);
        $info = is_file($meta) ? json_decode(file_get_contents($meta), true) : ['status' => 0, 'headers' => [], 'sid' => $sid, 'database' => null];
        self::$databases[$n] = $info['database'];
        $log = is_file($errorLog) ? file($errorLog, FILE_IGNORE_NEW_LINES) : [];
        $errors = array_values(array_filter($log, fn($l) => preg_match('~PHP (Warning|Notice|Deprecated|Fatal error|Parse error):~', $l) === 1));
        if (preg_match('~(Warning|Notice|Deprecated|Fatal error|Parse error|Uncaught)[:\s]~', $body)) {
            $errors[] = 'in the page: ' . substr(strip_tags($body), 0, 400);
        }
        return ['body' => $body, 'status' => $info['status'], 'headers' => $info['headers'], 'sid' => $info['sid'], 'errors' => $errors];
    }

    /** A request that must not have raised a PHP message. */
    private function page(string $uri, string $method = 'GET', array $post = [], ?string $sid = null): array
    {
        $r = $this->request($uri, $method, $post, $sid);
        $this->assertSame([], $r['errors'], "$method $uri raised PHP messages");
        return $r;
    }

    /** The messages captured since the last call, oldest first: [file, text]. */
    private function mail(): array
    {
        $found = [];
        foreach (glob(self::$siteDir . '/storage/mail/*.eml') ?: [] as $file) {
            $found[] = [$file, file_get_contents($file)];
            unlink($file);
        }
        return $found;
    }

    /** The path and query of the one link in a captured message that points at /auth/token. */
    private function tokenLink(string $message): string
    {
        $this->assertSame(1, preg_match('~href="https://' . preg_quote(self::DOMAIN, '~') . '(/auth/token\?t=[^"]+)"~', $message, $m), "no sign-in link in the mail:\n$message");
        return html_entity_decode($m[1]);
    }

    /** Clicks a captured link the way a browser does: GET the confirm page, POST its form. */
    private function followLink(string $link, ?string $sid = null): array
    {
        $confirm = $this->page($link, 'GET', [], $sid);
        $this->assertSame(200, $confirm['status']);
        $this->assertSame(1, preg_match('~name="t" value="([^"]*)"~', $confirm['body'], $t), 'the confirm page carries the token');
        $this->assertSame(1, preg_match('~name="n" value="([^"]*)"~', $confirm['body'], $n), 'the confirm page carries a nonce');
        return $this->page('/auth/token', 'POST', ['t' => html_entity_decode($t[1]), 'n' => html_entity_decode($n[1])], $confirm['sid']);
    }

    private function dbValue(string $sql): string
    {
        [, $out] = MysqlCli::run($sql, self::DATABASE, null, true);
        return trim($out);
    }

    public function testHomePageRenders(): void
    {
        $r = $this->page('/');
        $this->assertSame(200, $r['status']);
        $this->assertStringContainsString('Your Zero Framework project is up and running', $r['body']);
        $this->assertStringContainsString('<a class="site-brand" href="/">' . self::DOMAIN . '</a>', $r['body'], 'SITE_NAME is the domain init was given');
        $this->assertStringContainsString('</html>', $r['body']);
    }

    public function testSignInPageRenders(): void
    {
        $r = $this->page('/user/login');
        $this->assertSame(200, $r['status']);
        $this->assertStringContainsString('<form action="/auth/signin" method="post"', $r['body']);
        $this->assertStringContainsString('name="email"', $r['body']);
        $this->assertStringContainsString('Email me a sign-in link', $r['body']);
    }

    /**
     * Unknown address, then registration, then the verification link, then a
     * second sign-in with the login link. The login form posts to /auth/signin,
     * which never creates an account (it answers the same for any address);
     * /auth/register, linked from the same page, does.
     */
    public function testEmailLinkSignIn(): string
    {
        // an address nobody registered: the same page, no account, no mail
        $r = $this->page('/auth/signin', 'POST', ['email' => self::$email]);
        $this->assertSame(200, $r['status']);
        $this->assertSame('0', $this->dbValue("SELECT COUNT(*) FROM users WHERE email='" . self::$email . "'"));
        $this->assertSame([], $this->mail());

        // register: the account exists, unverified, and a verification link is mailed
        $r = $this->page('/auth/register', 'POST', ['email' => self::$email, 'name' => 'Ada']);
        $this->assertSame(200, $r['status']);
        $this->assertSame('0', $this->dbValue("SELECT verified FROM users WHERE email='" . self::$email . "'"));
        $mail = $this->mail();
        $this->assertCount(1, $mail, 'one verification mail');
        $this->assertStringContainsString('To: ' . self::$email, $mail[0][1]);

        $verified = $this->followLink($this->tokenLink($mail[0][1]));
        $this->assertSame('1', $this->dbValue("SELECT verified FROM users WHERE email='" . self::$email . "'"));
        $this->assertSame(302, $verified['status'], 'sign-in redirects (the CLI keeps no header list, so the status is what shows it)');

        $profile = $this->page('/user/profile', 'GET', [], $verified['sid']);
        $this->assertSame(200, $profile['status']);
        $this->assertStringContainsString(self::$email, $profile['body']);

        // a verified account asks for a sign-in link on the login form's endpoint
        $r = $this->page('/auth/signin', 'POST', ['email' => self::$email]);
        $this->assertSame(200, $r['status']);
        $mail = $this->mail();
        $this->assertCount(1, $mail, 'one sign-in mail');
        $login = $this->followLink($this->tokenLink($mail[0][1]));
        $profile = $this->page('/user/profile', 'GET', [], $login['sid']);
        $this->assertSame(200, $profile['status']);
        $this->assertStringContainsString(self::$email, $profile['body']);

        // the same link is spent: its confirm page still renders, but the POST signs nobody in
        $spent = $this->followLink($this->tokenLink($mail[0][1]));
        $this->assertNotSame(302, $spent['status'], 'a spent link signs nobody in');

        self::$sid = $login['sid'];
        return $login['sid'];
    }

    #[Depends('testEmailLinkSignIn')]
    public function testAdminDashboardNeedsTheCapability(string $sid): void
    {
        $denied = $this->request('/admin', 'GET', [], $sid);
        $this->assertSame([], $denied['errors']);
        $this->assertTrue(
            $denied['status'] === 403 || $denied['status'] === 302,
            'a user without allow.admin is turned away from /admin (status ' . $denied['status'] . ')'
        );

        $id = (int) $this->dbValue("SELECT id FROM users WHERE email='" . self::$email . "'");
        $this->request('/', 'GET', [], null, '(\\Zero\\Core\\Access\\User::find(' . $id . '))->grant("allow.admin");');
        $this->assertSame('1', $this->dbValue("SELECT COUNT(*) FROM permission WHERE permission_key='allow.admin'"));

        $ok = $this->page('/admin', 'GET', [], $sid);
        $this->assertSame(200, $ok['status']);
        // the screens core's own cards open, and the labels of the bundled modules that ship an Admin/ folder and are on
        foreach (['User Management', 'Group Management', 'Module Management', 'OAuth Providers', 'Entities', 'Push Notifications'] as $offering) {
            $this->assertStringContainsString($offering, $ok['body'], "the dashboard lists $offering");
        }
    }

    #[Depends('testEmailLinkSignIn')]
    public function testADisabledModuleIs404(string $sid): void
    {
        $ini = self::$siteDir . '/app/modules.ini';
        $before = file_get_contents($ini);
        try {
            // HelloWorld is an example module: off by default, and its page is public
            $this->assertSame(404, $this->page('/hello-world', 'GET', [], $sid)['status'], 'HelloWorld is off by default');
            file_put_contents($ini, $before . "\n[enabled]\nHelloWorld = on\n");
            $this->assertSame(200, $this->page('/hello-world', 'GET', [], $sid)['status']);
            file_put_contents($ini, $before . "\n[enabled]\nHelloWorld = off\n");
            $this->assertSame(404, $this->page('/hello-world', 'GET', [], $sid)['status']);
            $this->assertSame(200, $this->page('/')['status']);
        } finally {
            file_put_contents($ini, $before);
        }
    }

    #[Depends('testADisabledModuleIs404')]
    public function testNoRequestTouchedAnotherDatabase(): void
    {
        $seen = array_filter(self::$databases, fn($d) => $d !== null);
        $this->assertNotEmpty($seen, 'some request used the database');
        $this->assertSame([self::DATABASE], array_values(array_unique($seen)), 'every request ran on the throwaway database');
    }
}
