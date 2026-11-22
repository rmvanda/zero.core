<?php

namespace Zero\Tests\Core\Acceptance;

use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\TestCase;

// The trait sits beside this file, in a folder the test autoloader does not map.
require_once __DIR__ . '/BareSite.php';

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
    use BareSite;

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

        // every page the sign-in screen links to is there: core's own Index has the privacy page.
        // A link under /auth/ starts a sign-in with a provider and is not a page.
        preg_match_all('~<a [^>]*href="(/[^"#?]*)~', $r['body'], $links);
        $this->assertContains('/privacy', $links[1]);
        foreach (array_unique($links[1]) as $href) {
            if (str_starts_with($href, '/auth/')) {
                continue;
            }
            $this->assertSame(200, $this->page($href)['status'], "$href, linked from the sign-in page");
        }
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
        $this->assertMatchesRegularExpression('~allow\.zerotest\.bulk.*?<td class="num">2</td>\s*<td class="num">1</td>~s', $this->page('/admin/permissions', 'GET', [], $sid)['body'], 'the list counts two users and one group');

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
        $this->assertStringContainsString('No event matches these filters', $this->page('/admin/audit?event=group_delete&user=999999', 'GET', [], $sid)['body']);
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
        $this->assertOnlyTheThrowawayDatabase();
    }
}
