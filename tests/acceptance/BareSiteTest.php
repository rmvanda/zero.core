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
    public function testAdminDashboardNeedsTheCapability(string $sid): string
    {
        // Without allow.admin every /admin URL is the 404 of a URL that does not exist,
        // signed in or not: no redirect to the sign-in page, nothing that says an admin is there.
        $unknown = $this->page('/no-such-module/users', 'GET', [], $sid);
        $this->assertSame(404, $unknown['status']);
        foreach ([$sid, null] as $who) {
            foreach (['/admin', '/admin/users', '/admin/users/edit/1', '/admin/modules', '/admin/firewall', '/admin/push'] as $uri) {
                $denied = $this->page($uri, 'GET', [], $who);
                $this->assertSame(404, $denied['status'], "$uri without allow.admin");
            }
        }
        $this->assertSame(
            $this->errorText($unknown['body']),
            $this->errorText($this->page('/admin/users', 'GET', [], $sid)['body']),
            'the refusal reads exactly like an unknown URL'
        );
        $this->assertSame(404, $this->page('/admin/modules/set', 'POST', ['module' => 'Push', 'hash' => 'x'], $sid)['status']);

        $id = (int) $this->dbValue("SELECT id FROM users WHERE email='" . self::$email . "'");
        $this->request('/', 'GET', [], null, '(\\Zero\\Core\\Access\\User::find(' . $id . '))->grant("allow.admin");');
        $this->assertSame('1', $this->dbValue("SELECT COUNT(*) FROM permission WHERE permission_key='allow.admin'"));

        $ok = $this->page('/admin', 'GET', [], $sid);
        $this->assertSame(200, $ok['status']);
        // Admin's own screens, and the labels of the bundled modules that ship an Admin/ folder and are on
        foreach (['href="/admin/users"', 'href="/admin/groups"', 'href="/admin/modules"', 'href="/admin/firewall"', 'OAuth Providers', 'Entities', 'Push Notifications'] as $offering) {
            $this->assertStringContainsString($offering, $ok['body'], "the dashboard lists $offering");
        }
        $this->assertStringNotContainsString('admin-breadcrumbs"', $ok['body'], 'the dashboard has no breadcrumbs');

        return $sid;
    }

    /** The heading and detail of an error page. */
    private function errorText(string $body): string
    {
        preg_match_all('~<h1>[^<]*</h1>|<h4>[^<]*</h4>~', $body, $m);
        return implode('', array_slice($m[0], 0, 2));
    }

    #[Depends('testAdminDashboardNeedsTheCapability')]
    public function testAdminsOwnScreens(string $sid): void
    {
        $id = (int) $this->dbValue("SELECT id FROM users WHERE email='" . self::$email . "'");

        // every screen renders, with its breadcrumbs above it
        foreach ([
            '/admin/users' => 'Users',
            "/admin/users/edit/$id" => self::$email,
            '/admin/groups' => 'Groups',
            '/admin/groups/form' => 'New group',
            '/admin/modules' => 'Modules',
            '/admin/audit' => 'Audit Log',
            '/admin/permissions' => 'allow.admin',
            '/admin/permissions/holders/allow/admin' => self::$email,
            '/admin/firewall' => 'Firewall',
        ] as $uri => $text) {
            $page = $this->page($uri, 'GET', [], $sid);
            $this->assertSame(200, $page['status'], $uri);
            $this->assertStringContainsString($text, $page['body'], $uri);
            $this->assertSame(1, preg_match('~<nav class="admin-breadcrumbs".*?</nav>~s', $page['body'], $nav), "$uri has breadcrumbs");
            $this->assertStringContainsString('<a href="/admin">Admin</a>', $nav[0], $uri);
            $this->assertSame(1, substr_count($page['body'], 'admin-breadcrumbs"'), "$uri prints them once");
            $this->assertStringNotContainsString('Back to Admin Panel', $page['body'], $uri);
        }
        $this->assertSame(404, $this->page('/admin/users/edit/999999', 'GET', [], $sid)['status']);
        $this->assertSame(404, $this->page('/admin/users/edit/abc', 'GET', [], $sid)['status']);
        $this->assertSame(404, $this->page('/admin/manage/users', 'GET', [], $sid)['status'], 'the old URL is gone');

        // a group: saved, listed, a member added; a second one of the same name is refused with the reason
        $saved = $this->page('/admin/groups/save', 'POST', ['group_name' => 'Family', 'description' => 'd', 'is_active' => '1'], $sid);
        $this->assertSame(302, $saved['status']);
        $gid = (int) $this->dbValue("SELECT group_id FROM user_group WHERE group_name='Family'");
        $this->assertGreaterThan(0, $gid);
        $this->assertStringContainsString('Family', $this->page('/admin/groups', 'GET', [], $sid)['body']);

        $again = $this->page('/admin/groups/save', 'POST', ['group_name' => 'Family'], $sid);
        $this->assertSame(409, $again['status']);
        $this->assertStringContainsString('already exists', $again['body']);
        $this->assertSame(400, $this->page('/admin/groups/save', 'POST', ['group_name' => ''], $sid)['status']);
        $this->assertSame(405, $this->page('/admin/groups/save', 'GET', [], $sid)['status']);

        $added = $this->page('/admin/groups/add-member', 'POST', ['group_id' => $gid, 'user_id' => $id, 'role' => 'member'], $sid);
        $this->assertSame(302, $added['status']);
        $this->assertSame('1', $this->dbValue("SELECT COUNT(*) FROM user_group_member WHERE group_id=$gid AND user_id=$id"));
        $this->assertSame(409, $this->page('/admin/groups/add-member', 'POST', ['group_id' => $gid, 'user_id' => $id], $sid)['status']);
        // the users list shows the group and counts the capabilities: the user's own and the group's
        $this->request('/', 'GET', [], null, '(\\Zero\\Core\\Access\\Group::find(' . $gid . '))->grant("allow.zerotest.group");');
        $this->assertSame('2', $this->dbValue("SELECT COUNT(DISTINCT permission_key) FROM permission WHERE permission_key LIKE 'allow.%' AND (user_id=$id OR group_id=$gid)"), 'allow.admin and the group capability');
        $list = $this->page('/admin/users', 'GET', [], $sid);
        $this->assertStringContainsString('Family', $list['body']);
        $this->assertMatchesRegularExpression('~\b2 capabilities~', strip_tags($list['body']), 'the row counts the user\'s own capability and the group\'s');
        $members = $this->page("/admin/groups/members/$gid", 'GET', [], $sid);
        $this->assertSame(200, $members['status']);
        $this->assertStringContainsString(self::$email, $members['body']);
        $this->assertSame(302, $this->page('/admin/groups/set-member-role', 'POST', ['group_id' => $gid, 'user_id' => $id, 'role' => 'admin'], $sid)['status']);
        $this->assertSame(400, $this->page('/admin/groups/set-member-role', 'POST', ['group_id' => $gid, 'user_id' => $id, 'role' => 'king'], $sid)['status']);
        $this->assertSame(302, $this->page('/admin/groups/remove-member', 'POST', ['group_id' => $gid, 'user_id' => $id], $sid)['status']);
        $this->assertSame(404, $this->page('/admin/groups/members/999999', 'GET', [], $sid)['status']);
        $this->assertSame(302, $this->page("/admin/groups/delete/$gid", 'POST', ['hard_delete' => '1'], $sid)['status']);
        $this->assertSame('0', $this->dbValue("SELECT COUNT(*) FROM user_group WHERE group_id=$gid"));

        // a capability and a setting are different things with different endpoints
        $r = $this->page('/admin/users/set-capability', 'POST', ['user_id' => $id, 'key' => 'allow.zerotest', 'value' => '1'], $sid); $this->assertSame(302, $r['status'], $this->errorText($r['body']));
        $this->assertSame('1', $this->dbValue("SELECT COUNT(*) FROM permission WHERE permission_key='allow.zerotest'"));
        $this->assertSame(302, $this->page('/admin/users/set-capability', 'POST', ['user_id' => $id, 'key' => 'allow.zerotest', 'value' => '0'], $sid)['status']);
        $this->assertSame('0', $this->dbValue("SELECT COUNT(*) FROM permission WHERE permission_key='allow.zerotest'"));
        $this->assertSame(400, $this->page('/admin/users/set-setting', 'POST', ['user_id' => $id, 'key' => 'allow.zerotest', 'value' => '1'], $sid)['status'], 'a capability is not a setting');

        $this->assertSame(302, $this->page('/admin/users/set-setting', 'POST', ['user_id' => $id, 'key' => 'zerotest.colour', 'value' => 'blue'], $sid)['status']);
        $this->assertSame('blue', $this->dbValue("SELECT setting_value FROM user_settings WHERE user_id=$id AND setting_key='zerotest.colour'"));
        $this->assertSame(302, $this->page('/admin/users/remove-setting', 'POST', ['user_id' => $id, 'key' => 'zerotest.colour'], $sid)['status']);
        $this->assertSame('0', $this->dbValue("SELECT COUNT(*) FROM user_settings WHERE user_id=$id AND setting_key='zerotest.colour'"));
        $this->assertSame(404, $this->page('/admin/users/remove-setting', 'POST', ['user_id' => $id, 'key' => 'zerotest.colour'], $sid)['status']);
        $this->assertSame(404, $this->page('/admin/users/set-setting', 'POST', ['user_id' => 999999, 'key' => 'zerotest.colour', 'value' => 'x'], $sid)['status']);

        // who holds a capability, and granting and revoking it for several holders at once
        $this->assertSame(302, $this->page('/admin/groups/save', 'POST', ['group_name' => 'Crew', 'is_active' => '1'], $sid)['status']);
        $crew = (int) $this->dbValue("SELECT group_id FROM user_group WHERE group_name='Crew'");
        $this->request('/', 'GET', [], null, '\\Zero\\Core\\Access\\User::create(["name" => "Second", "email" => "second@example.test", "verified" => 1]);');
        $second = (int) $this->dbValue("SELECT id FROM users WHERE email='second@example.test'");
        $this->assertGreaterThan(0, $second);

        $empty = $this->page('/admin/permissions/holders/allow/zerotest/bulk', 'GET', [], $sid);
        $this->assertSame(200, $empty['status']);
        $this->assertStringContainsString('Nobody has been granted', $empty['body']);
        $this->assertStringContainsString('second@example.test', $empty['body'], 'a user who could be granted it is offered');

        $granted = $this->page('/admin/permissions/grant', 'POST', ['key' => 'allow.zerotest.bulk', 'user_ids' => [$id, $second], 'group_ids' => [$crew]], $sid);
        $this->assertSame(302, $granted['status']);
        $this->assertSame('3', $this->dbValue("SELECT COUNT(*) FROM permission WHERE permission_key='allow.zerotest.bulk'"));

        $held = $this->page('/admin/permissions/holders/allow/zerotest/bulk', 'GET', [], $sid);
        foreach (['Crew', 'second@example.test', self::$email] as $holder) {
            $this->assertStringContainsString($holder, $held['body']);
        }
        $this->assertMatchesRegularExpression('~allow\.zerotest\.bulk.*?<td>2</td>\s*<td>1</td>~s', $this->page('/admin/permissions', 'GET', [], $sid)['body'], 'the list counts two users and one group');

        // one bad id changes nothing
        $this->assertSame(404, $this->page('/admin/permissions/revoke', 'POST', ['key' => 'allow.zerotest.bulk', 'user_ids' => [$second, 999999]], $sid)['status']);
        $this->assertSame('3', $this->dbValue("SELECT COUNT(*) FROM permission WHERE permission_key='allow.zerotest.bulk'"));
        $this->assertSame(400, $this->page('/admin/permissions/revoke', 'POST', ['key' => 'allow.zerotest.bulk'], $sid)['status'], 'nobody chosen');
        $this->assertSame(400, $this->page('/admin/permissions/grant', 'POST', ['key' => 'not a key', 'user_ids' => [$id]], $sid)['status']);
        $this->assertSame(404, $this->page('/admin/permissions/holders/nonsense', 'GET', [], $sid)['status']);

        $this->assertSame(302, $this->page('/admin/permissions/revoke', 'POST', ['key' => 'allow.zerotest.bulk', 'user_ids' => [$id, $second], 'group_ids' => [$crew]], $sid)['status']);
        $this->assertSame('0', $this->dbValue("SELECT COUNT(*) FROM permission WHERE permission_key='allow.zerotest.bulk'"));

        // an admin cannot take allow.admin from the account they are signed in with
        $own = $this->page('/admin/permissions/revoke', 'POST', ['key' => 'allow.admin', 'user_ids' => [$id]], $sid);
        $this->assertSame(409, $own['status']);
        $this->assertSame(200, $this->page('/admin', 'GET', [], $sid)['status']);

        // what was just done is in the audit log, and the log filters by event and by user
        $audit = $this->page('/admin/audit', 'GET', [], $sid);
        foreach (['group_save', 'permission_bulk_grant', 'admin_permission_change', 'user_setting_change'] as $event) {
            $this->assertStringContainsString($event, $audit['body'], "$event was recorded");
        }
        $this->assertStringContainsString('href="/admin/users/edit/' . $id . '"', $audit['body'], 'an event links to its user');
        $one = $this->page('/admin/audit?event=group_delete&user=' . $id, 'GET', [], $sid);
        $this->assertStringContainsString('of 1 events', $one['body']);
        $this->assertStringContainsString('of 0 events', $this->page('/admin/audit?event=group_delete&user=999999', 'GET', [], $sid)['body']);
        $this->assertSame(404, $this->page('/admin/entity/events', 'GET', [], $sid)['status'], 'the log is not under Entities');

        // an admin cannot disable the account they are signed in with
        $self = $this->page('/admin/users/disable', 'POST', ['user_id' => $id], $sid);
        $this->assertSame(409, $self['status']);
        $this->assertStringContainsString('own account', $self['body']);

        // the modules screen saves through its own endpoint
        $modules = $this->page('/admin/modules', 'GET', [], $sid);
        $this->assertSame(1, preg_match('~name="hash" value="([^"]*)"~', $modules['body'], $hash));
        $this->assertStringContainsString('action="/admin/modules/set"', $modules['body']);
        $set = $this->page('/admin/modules/set', 'POST', ['module' => 'Push', 'hash' => html_entity_decode($hash[1]), 'order' => '5'], $sid);
        $this->assertSame(302, $set['status']);
        $this->assertStringContainsString('Push = 5', file_get_contents(self::$siteDir . '/app/modules.ini'));
        $this->assertSame(409, $this->page('/admin/modules/set', 'POST', ['module' => 'Push', 'hash' => 'stale', 'order' => '6'], $sid)['status']);
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
