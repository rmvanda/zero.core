<?php

namespace Zero\Tests\Core\Acceptance;

use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\TestCase;

// The trait sits beside this file, in a folder the test autoloader does not map.
require_once __DIR__ . '/BareSite.php';

/**
 * Every endpoint of Admin's own screens, on a bare site: what it does when it
 * works, what it answers when it refuses, and what it leaves in the audit log.
 *
 * The audit log records that an admin called an endpoint, with what it was
 * sent: the event is written before the endpoint decides, so a call it then
 * refuses is in the log too. The tests count what must be there (one event for
 * a call that worked, none for a caller who is not an admin) and no more.
 *
 * A form post that works answers 302; the same post from a script (Accept:
 * application/json) answers {"data": {"success": true, "message": ...}}. A
 * refusal is an HTTP error status either way, and a script gets
 * {"status": "error", "message": ..., "detail": ..., "code": ...}.
 *
 * The first test signs one account in and proves every endpoint is a 404 for
 * it; the second gives it allow.admin, and the rest run as that admin.
 */
final class AdminEndpointsTest extends TestCase
{
    use BareSite;

    /** Every POST endpoint of Admin's own screens, with a body that would work for an admin. */
    private const POSTS = [
        '/admin/users/set-capability' => ['user_id' => 1, 'key' => 'allow.zerotest', 'value' => '1'],
        '/admin/users/set-setting' => ['user_id' => 1, 'key' => 'zerotest.colour', 'value' => 'blue'],
        '/admin/users/remove-setting' => ['user_id' => 1, 'key' => 'zerotest.colour'],
        '/admin/users/disable' => ['user_id' => 1],
        '/admin/users/enable' => ['user_id' => 1],
        '/admin/groups/save' => ['group_name' => 'Refused'],
        '/admin/groups/delete/1' => [],
        '/admin/groups/add-member' => ['group_id' => 1, 'user_id' => 1],
        '/admin/groups/remove-member' => ['group_id' => 1, 'user_id' => 1],
        '/admin/groups/set-member-role' => ['group_id' => 1, 'user_id' => 1, 'role' => 'admin'],
        '/admin/permissions/grant' => ['key' => 'allow.zerotest', 'user_ids' => [1]],
        '/admin/permissions/revoke' => ['key' => 'allow.zerotest', 'user_ids' => [1]],
        '/admin/modules/set' => ['module' => 'Push', 'hash' => 'x', 'enabled' => 'off'],
        '/admin/firewall/unblock' => ['ip' => ''],
    ];

    /** The admin's id, and the id of a second account that is never an admin. */
    private static int $admin = 0;
    private static int $other = 0;

    /** How many events of that name the audit log holds. */
    private function events(string $event, ?string $sid = null): int
    {
        $body = $this->page('/admin/audit?event=' . $event, 'GET', [], $sid ?? self::$sid)['body'];
        return preg_match('~of ([0-9,]+) events~', $body, $m) === 1 ? (int) str_replace(',', '', $m[1]) : 0;
    }

    /** One call that works leaves exactly one event of that name. */
    private function assertRecordsOne(string $event, string $uri, array $post): void
    {
        $before = $this->events($event);
        $this->accepted($uri, $post);
        $this->assertSame($before + 1, $this->events($event), "$uri records one $event");
    }

    /** The refusal a script is given: its status, and the detail it would show. */
    private function refusal(string $uri, array $post): array
    {
        $r = $this->json($uri, $post, self::$sid);
        $this->assertSame('error', $r['json']['status'] ?? null, "$uri answers an error: " . $r['body']);
        $this->assertSame($r['status'], $r['json']['code'] ?? null, "$uri carries its status as code");
        return [$r['status'], (string) ($r['json']['detail'] ?? '')];
    }

    /** A post that works, made as a script: 200 and the success envelope. Returns its data. */
    private function accepted(string $uri, array $post): array
    {
        $r = $this->json($uri, $post, self::$sid);
        $this->assertSame(200, $r['status'], "$uri: " . $r['body']);
        $this->assertTrue($r['json']['data']['success'] ?? null, "$uri answers success: " . $r['body']);
        $this->assertNotSame('', (string) ($r['json']['data']['message'] ?? ''), "$uri says what it did");
        return $r['json']['data'];
    }

    public function testEveryEndpointIs404WithoutTheCapability(): string
    {
        $sid = $this->signUp(self::$email, 'Ada');
        self::$admin = (int) $this->dbValue("SELECT id FROM users WHERE email='" . self::$email . "'");
        $this->assertGreaterThan(0, self::$admin);

        $before = [
            $this->dbValue('SELECT COUNT(*) FROM permission'),
            $this->dbValue('SELECT COUNT(*) FROM user_group'),
            $this->dbValue('SELECT COUNT(*) FROM user_settings'),
            $this->dbValue('SELECT disabled FROM users WHERE id=' . self::$admin),
            file_get_contents(self::$siteDir . '/app/modules.ini'),
        ];

        // signed in without allow.admin, and not signed in at all
        foreach ([$sid, null] as $who) {
            foreach (self::POSTS as $uri => $post) {
                $post = array_map(fn($v) => $v === 1 ? self::$admin : ($v === [1] ? [self::$admin] : $v), $post);
                $form = $this->page($uri, 'POST', $post, $who);
                $this->assertSame(404, $form['status'], "$uri as a form, " . ($who ? 'without the capability' : 'signed out'));
                $script = $this->json($uri, $post, $who);
                $this->assertSame(404, $script['status'], "$uri as a script");
                $this->assertSame('error', $script['json']['status'] ?? null, "$uri as a script");
            }
            foreach (['/admin/audit', '/admin/permissions', '/admin/permissions/holders/allow/admin', '/admin/groups', '/admin/groups/form'] as $uri) {
                $this->assertSame(404, $this->page($uri, 'GET', [], $who)['status'], $uri);
            }
        }

        $this->assertSame($before, [
            $this->dbValue('SELECT COUNT(*) FROM permission'),
            $this->dbValue('SELECT COUNT(*) FROM user_group'),
            $this->dbValue('SELECT COUNT(*) FROM user_settings'),
            $this->dbValue('SELECT disabled FROM users WHERE id=' . self::$admin),
            file_get_contents(self::$siteDir . '/app/modules.ini'),
        ], 'a refused request changed nothing');

        return $sid;
    }

    #[Depends('testEveryEndpointIs404WithoutTheCapability')]
    public function testAnAdminIsLetIn(string $sid): void
    {
        $this->inSite('(\\Zero\\Core\\Access\\User::find(' . self::$admin . '))->grant("allow.admin");');
        $this->inSite('\\Zero\\Core\\Access\\User::create(["name" => "Grace", "email" => "grace@example.test", "verified" => 1]);');
        self::$other = (int) $this->dbValue("SELECT id FROM users WHERE email='grace@example.test'");
        $this->assertGreaterThan(0, self::$other);
        self::$sid = $sid;

        $this->assertSame(200, $this->page('/admin', 'GET', [], $sid)['status']);
        // the refusals above left nothing in the log: an event is recorded for an admin's action only
        $this->assertSame(0, $this->events('admin_permission_change'));
        $this->assertSame(0, $this->events('group_save'));
    }

    #[Depends('testAnAdminIsLetIn')]
    public function testEveryPostEndpointRefusesGet(): void
    {
        foreach (array_keys(self::POSTS) as $uri) {
            $this->assertSame(405, $this->page($uri, 'GET', [], self::$sid)['status'], "GET $uri");
        }
    }

    #[Depends('testAnAdminIsLetIn')]
    public function testCapabilitiesOfAUser(): void
    {
        $other = self::$other;
        $uri = '/admin/users/set-capability';
        $held = fn() => $this->dbValue("SELECT COUNT(*) FROM permission WHERE user_id=$other AND permission_key='allow.zerotest.read'");

        // granted and revoked by a script
        $data = $this->accepted($uri, ['user_id' => $other, 'key' => 'allow.zerotest.read', 'value' => '1']);
        $this->assertSame('Capability granted', $data['message']);
        $this->assertSame('1', $held());
        $this->assertSame('1', $this->dbValue("SELECT granted_by FROM permission WHERE user_id=$other AND permission_key='allow.zerotest.read'") === (string) self::$admin ? '1' : '0', 'the grant names who made it');
        $this->accepted($uri, ['user_id' => $other, 'key' => 'allow.zerotest.read', 'value' => '1']);
        $this->assertSame('1', $held(), 'granting twice holds it once');
        $this->assertSame('Capability revoked', $this->accepted($uri, ['user_id' => $other, 'key' => 'allow.zerotest.read', 'value' => '0'])['message']);
        $this->assertSame('0', $held());

        $this->assertRecordsOne('admin_permission_change', $uri, ['user_id' => $other, 'key' => 'allow.zerotest.once', 'value' => '1']);
        $this->accepted($uri, ['user_id' => $other, 'key' => 'allow.zerotest.once', 'value' => '0']);

        // by a form: a redirect
        $this->assertSame(302, $this->page($uri, 'POST', ['user_id' => $other, 'key' => 'allow.zerotest.read', 'value' => '1'], self::$sid)['status']);
        $this->assertSame('1', $held());
        $this->assertStringContainsString('allow.zerotest.read', $this->page("/admin/users/edit/$other", 'GET', [], self::$sid)['body']);

        // refusals, each with its reason, and none of them saved
        $count = $this->dbValue('SELECT COUNT(*) FROM permission');
        foreach ([
            [['user_id' => $other, 'key' => 'zerotest.read', 'value' => '1'], 400, 'allow.'],
            [['user_id' => $other, 'key' => 'allow.Upper', 'value' => '1'], 400, 'allow.'],
            [['user_id' => $other, 'key' => 'allow.', 'value' => '1'], 400, 'allow.'],
            [['user_id' => $other, 'key' => ['allow.x'], 'value' => '1'], 400, 'string'],
            [['user_id' => 999999, 'key' => 'allow.zerotest.read', 'value' => '1'], 404, 'User not found'],
            [['user_id' => 'abc', 'key' => 'allow.zerotest.read', 'value' => '1'], 400, 'whole number'],
            [['user_id' => $other], 400, 'key'],
        ] as [$post, $status, $says]) {
            [$got, $detail] = $this->refusal($uri, $post);
            $this->assertSame($status, $got, json_encode($post) . ": $detail");
            $this->assertStringContainsString($says, $detail, json_encode($post));
        }
        $this->assertSame($count, $this->dbValue('SELECT COUNT(*) FROM permission'));

        // the log has the four changes that were made
        $this->assertGreaterThanOrEqual(4, $this->events('admin_permission_change'));
    }

    #[Depends('testAnAdminIsLetIn')]
    public function testSettingsOfAUser(): void
    {
        $other = self::$other;
        $value = fn(string $key) => $this->dbValue("SELECT setting_value FROM user_settings WHERE user_id=$other AND setting_key='$key'");

        $this->assertSame('Setting saved', $this->accepted('/admin/users/set-setting', ['user_id' => $other, 'key' => 'zerotest.colour', 'value' => 'blue'])['message']);
        $this->assertSame('blue', $value('zerotest.colour'));
        $this->accepted('/admin/users/set-setting', ['user_id' => $other, 'key' => 'zerotest.colour', 'value' => 'green']);
        $this->assertSame('green', $value('zerotest.colour'), 'a second save replaces the value');
        $this->assertSame('1', $this->dbValue("SELECT COUNT(*) FROM user_settings WHERE user_id=$other AND setting_key='zerotest.colour'"));

        // limit.* and auth.* are whole numbers
        $this->accepted('/admin/users/set-setting', ['user_id' => $other, 'key' => 'limit.zerotest', 'value' => '25']);
        $this->assertSame('25', $value('limit.zerotest'));
        $edit = $this->page("/admin/users/edit/$other", 'GET', [], self::$sid)['body'];
        $this->assertStringContainsString('zerotest.colour', $edit);
        $this->assertStringContainsString('limit.zerotest', $edit);

        foreach ([
            [['user_id' => $other, 'key' => 'limit.zerotest', 'value' => 'many'], 400, 'whole number'],
            [['user_id' => $other, 'key' => 'limit.zerotest', 'value' => '1.5'], 400, 'whole number'],
            [['user_id' => $other, 'key' => 'allow.zerotest', 'value' => '1'], 400, 'capability, not a setting'],
            [['user_id' => $other, 'key' => 'bad key', 'value' => '1'], 400, 'letters, digits'],
            [['user_id' => $other, 'key' => 'zerotest.colour'], 400, 'value'],
            [['user_id' => $other, 'key' => 'zerotest.colour', 'value' => ['a']], 400, 'string'],
            [['user_id' => 999999, 'key' => 'zerotest.colour', 'value' => 'x'], 404, 'User not found'],
        ] as [$post, $status, $says]) {
            [$got, $detail] = $this->refusal('/admin/users/set-setting', $post);
            $this->assertSame($status, $got, json_encode($post) . ": $detail");
            $this->assertStringContainsString($says, $detail, json_encode($post));
        }
        $this->assertSame('25', $value('limit.zerotest'), 'a refused value left the saved one');

        // a setting is not a capability: no row in permission came of any of this
        $this->assertSame('0', $this->dbValue("SELECT COUNT(*) FROM permission WHERE user_id=$other AND permission_key LIKE '%zerotest.colour%'"));

        $this->assertSame('Setting removed', $this->accepted('/admin/users/remove-setting', ['user_id' => $other, 'key' => 'zerotest.colour'])['message']);
        $this->assertSame('', $value('zerotest.colour'));
        [$got, $detail] = $this->refusal('/admin/users/remove-setting', ['user_id' => $other, 'key' => 'zerotest.colour']);
        $this->assertSame(404, $got);
        $this->assertStringContainsString('no setting zerotest.colour', $detail);
        $this->assertSame(302, $this->page('/admin/users/remove-setting', 'POST', ['user_id' => $other, 'key' => 'limit.zerotest'], self::$sid)['status']);
        $this->assertSame('0', $this->dbValue("SELECT COUNT(*) FROM user_settings WHERE user_id=$other"));

        // the three saves and the two removals are in the log
        $this->assertGreaterThanOrEqual(5, $this->events('user_setting_change'));
    }

    #[Depends('testAnAdminIsLetIn')]
    public function testDisablingAndEnablingAUser(): void
    {
        $other = self::$other;
        $disabled = fn(int $id) => $this->dbValue("SELECT disabled FROM users WHERE id=$id");

        $this->assertSame('User disabled', $this->accepted('/admin/users/disable', ['user_id' => $other])['message']);
        $this->assertSame('1', $disabled($other));
        $this->assertStringContainsString('user-disabled', $this->page('/admin/users', 'GET', [], self::$sid)['body'], 'the list marks the account');
        $this->assertStringContainsString('Disabled', $this->page("/admin/users/edit/$other", 'GET', [], self::$sid)['body']);

        $this->assertSame('User enabled', $this->accepted('/admin/users/enable', ['user_id' => $other])['message']);
        $this->assertSame('0', $disabled($other));
        $this->assertSame(302, $this->page('/admin/users/disable', 'POST', ['user_id' => $other], self::$sid)['status']);
        $this->assertSame('1', $disabled($other));
        $this->assertSame(302, $this->page('/admin/users/enable', 'POST', ['user_id' => $other], self::$sid)['status']);

        // an admin cannot lock themselves out
        [$got, $detail] = $this->refusal('/admin/users/disable', ['user_id' => self::$admin]);
        $this->assertSame(409, $got);
        $this->assertStringContainsString('own account', $detail);
        $this->assertSame('0', $disabled(self::$admin));
        $this->assertSame(200, $this->page('/admin', 'GET', [], self::$sid)['status'], 'and is still signed in');

        foreach (['/admin/users/disable', '/admin/users/enable'] as $uri) {
            $this->assertSame(404, $this->refusal($uri, ['user_id' => 999999])[0], $uri);
            $this->assertSame(400, $this->refusal($uri, ['user_id' => 'abc'])[0], $uri);
            $this->assertSame(400, $this->refusal($uri, [])[0], $uri);
        }

        $this->assertGreaterThanOrEqual(2, $this->events('user_disable'));
        $this->assertGreaterThanOrEqual(2, $this->events('user_enable'));
    }

    #[Depends('testAnAdminIsLetIn')]
    public function testTheUsersListPages(): void
    {
        // 30 more accounts: more than one page of 25
        $this->inSite('for ($i = 1; $i <= 30; $i++) { \\Zero\\Core\\Access\\User::create(["name" => "Listed $i", "email" => "listed$i@example.test", "verified" => 1]); }');
        $total = (int) $this->dbValue('SELECT COUNT(*) FROM users');
        $this->assertSame(32, $total);

        $first = $this->page('/admin/users', 'GET', [], self::$sid)['body'];
        $this->assertSame(25, substr_count($first, 'class="main-row'), 'a page holds 25 users');
        $this->assertStringContainsString('32 users', $first);
        $this->assertStringContainsString('Page 1 of 2', $first);
        $this->assertStringContainsString('href="/admin/users?page=2"', $first);

        $second = $this->page('/admin/users?page=2', 'GET', [], self::$sid)['body'];
        $this->assertSame(7, substr_count($second, 'class="main-row'));
        $this->assertStringContainsString('href="/admin/users?page=1"', $second);

        // a page past the end, or not a page at all, is the nearest real one
        foreach (['99', '0', '-3', 'abc'] as $page) {
            $r = $this->page('/admin/users?page=' . $page, 'GET', [], self::$sid);
            $this->assertSame(200, $r['status'], "page=$page");
            $this->assertGreaterThan(0, substr_count($r['body'], 'class="main-row'), "page=$page lists users");
        }

        $this->dbValue("DELETE FROM users WHERE email LIKE 'listed%@example.test'");
    }

    #[Depends('testAnAdminIsLetIn')]
    public function testGroups(): int
    {
        $id = fn(string $name) => (int) $this->dbValue("SELECT group_id FROM user_group WHERE group_name='$name'");

        // created by a script: the answer carries the new id
        $data = $this->accepted('/admin/groups/save', ['group_name' => 'Family', 'description' => 'The household', 'is_active' => '1']);
        $family = $id('Family');
        $this->assertGreaterThan(0, $family);
        $this->assertSame($family, $data['group_id']);
        $this->assertSame((string) self::$admin, $this->dbValue("SELECT created_by FROM user_group WHERE group_id=$family"));

        // edited: same row, new name and description
        $this->assertSame(302, $this->page("/admin/groups/save/$family", 'POST', ['group_name' => 'Household', 'description' => 'Everyone at home', 'is_active' => '1'], self::$sid)['status']);
        $this->assertSame($family, $id('Household'));
        $this->assertSame('Everyone at home', $this->dbValue("SELECT description FROM user_group WHERE group_id=$family"));
        $this->assertSame('1', $this->dbValue('SELECT COUNT(*) FROM user_group'));
        $form = $this->page("/admin/groups/form/$family", 'GET', [], self::$sid)['body'];
        $this->assertStringContainsString('value="Household"', $form);
        $this->assertStringContainsString("action=\"/admin/groups/save/$family\"", $form);

        $this->assertRecordsOne('group_save', '/admin/groups/save', ['group_name' => 'Crew', 'is_active' => '1']);
        $crew = $id('Crew');

        foreach ([
            ['/admin/groups/save', ['group_name' => 'Household'], 409, 'already exists'],
            ["/admin/groups/save/$crew", ['group_name' => 'Household'], 409, 'already exists'],
            ['/admin/groups/save', ['group_name' => ''], 400, 'required'],
            ['/admin/groups/save', ['group_name' => '   '], 400, 'required'],
            ['/admin/groups/save', ['group_name' => str_repeat('g', 101)], 400, '100 characters'],
            ['/admin/groups/save', ['group_name' => ['a']], 400, 'text'],
            ['/admin/groups/save/999999', ['group_name' => 'Nobody'], 404, 'Group not found'],
        ] as [$uri, $post, $status, $says]) {
            [$got, $detail] = $this->refusal($uri, $post);
            $this->assertSame($status, $got, "$uri " . json_encode($post) . ": $detail");
            $this->assertStringContainsString($says, $detail, $uri);
        }
        $this->assertSame('2', $this->dbValue('SELECT COUNT(*) FROM user_group'));
        $this->assertSame(404, $this->page('/admin/groups/form/999999', 'GET', [], self::$sid)['status']);

        // deactivated: still there, off; then deleted for good
        $this->assertSame('Group deactivated', $this->accepted("/admin/groups/delete/$crew", [])['message']);
        $this->assertSame('0', $this->dbValue("SELECT is_active FROM user_group WHERE group_id=$crew"));
        $this->assertStringContainsString('Inactive', $this->page('/admin/groups', 'GET', [], self::$sid)['body']);
        $this->assertSame('Group permanently deleted', $this->accepted("/admin/groups/delete/$crew", ['hard_delete' => '1'])['message']);
        $this->assertSame('0', $this->dbValue("SELECT COUNT(*) FROM user_group WHERE group_id=$crew"));
        $this->assertSame(404, $this->refusal("/admin/groups/delete/$crew", ['hard_delete' => '1'])[0]);

        // the three saves that worked and the two deletes are in the log
        $this->assertGreaterThanOrEqual(3, $this->events('group_save'));
        $this->assertGreaterThanOrEqual(2, $this->events('group_delete'));

        return $family;
    }

    #[Depends('testGroups')]
    public function testMembersOfAGroup(int $group): int
    {
        $other = self::$other;
        $role = fn(int $user) => $this->dbValue("SELECT role FROM user_group_member WHERE group_id=$group AND user_id=$user");

        $data = $this->accepted('/admin/groups/add-member', ['group_id' => $group, 'user_id' => $other, 'role' => 'admin']);
        $this->assertSame('admin', $data['role']);
        $this->assertSame('admin', $role($other));
        // no role given: a member
        $this->assertSame(302, $this->page('/admin/groups/add-member', 'POST', ['group_id' => $group, 'user_id' => self::$admin], self::$sid)['status']);
        $this->assertSame('member', $role(self::$admin));

        $members = $this->page("/admin/groups/members/$group", 'GET', [], self::$sid)['body'];
        $this->assertStringContainsString('grace@example.test', $members);
        $this->assertStringContainsString(self::$email, $members);
        $this->assertStringContainsString('All users are already members', $members, 'nobody is left to add');
        $this->assertStringContainsString('Household', $this->page('/admin/users', 'GET', [], self::$sid)['body'], 'the users list names the group');

        foreach (['member', 'admin', 'owner'] as $to) {
            $this->accepted('/admin/groups/set-member-role', ['group_id' => $group, 'user_id' => $other, 'role' => $to]);
            $this->assertSame($to, $role($other));
        }

        foreach ([
            ['/admin/groups/add-member', ['group_id' => $group, 'user_id' => $other], 409, ''],
            ['/admin/groups/add-member', ['group_id' => $group, 'user_id' => 999999], 404, 'User not found'],
            ['/admin/groups/add-member', ['group_id' => 999999, 'user_id' => $other], 404, 'Group not found'],
            ['/admin/groups/add-member', ['group_id' => $group, 'user_id' => 'abc'], 400, 'number'],
            ['/admin/groups/add-member', ['group_id' => $group, 'user_id' => $other, 'role' => 'king'], 400, 'member, admin or owner'],
            ['/admin/groups/add-member', ['group_id' => $group], 400, 'user_id'],
            ['/admin/groups/set-member-role', ['group_id' => $group, 'user_id' => $other, 'role' => 'king'], 400, 'member, admin or owner'],
            ['/admin/groups/set-member-role', ['group_id' => $group, 'user_id' => $other], 400, 'role'],
            ['/admin/groups/set-member-role', ['group_id' => 999999, 'user_id' => $other, 'role' => 'admin'], 404, 'Group not found'],
            ['/admin/groups/remove-member', ['group_id' => 999999, 'user_id' => $other], 404, 'Group not found'],
            ['/admin/groups/remove-member', ['group_id' => $group, 'user_id' => 'abc'], 400, 'number'],
        ] as [$uri, $post, $status, $says]) {
            [$got, $detail] = $this->refusal($uri, $post);
            $this->assertSame($status, $got, "$uri " . json_encode($post) . ": $detail");
            $this->assertStringContainsString($says, $detail, $uri);
        }
        $this->assertSame('owner', $role($other), 'a refused role left the saved one');
        $this->assertSame('2', $this->dbValue("SELECT COUNT(*) FROM user_group_member WHERE group_id=$group"));

        $this->assertSame('User removed from group successfully', $this->accepted('/admin/groups/remove-member', ['group_id' => $group, 'user_id' => self::$admin])['message']);
        $this->assertSame('', $role(self::$admin));
        $this->assertSame(404, $this->refusal('/admin/groups/remove-member', ['group_id' => $group, 'user_id' => self::$admin])[0], 'not a member any more');

        $this->assertGreaterThanOrEqual(2, $this->events('group_member_add'));
        $this->assertGreaterThanOrEqual(3, $this->events('group_member_role_update'));
        $this->assertGreaterThanOrEqual(1, $this->events('group_member_remove'));

        return $group;
    }

    #[Depends('testMembersOfAGroup')]
    public function testACapabilityForManyHoldersAtOnce(int $group): void
    {
        $other = self::$other;
        $admin = self::$admin;
        $key = 'allow.zerotest.bulk';
        $page = '/admin/permissions/holders/allow/zerotest/bulk';
        $rows = fn() => (int) $this->dbValue("SELECT COUNT(*) FROM permission WHERE permission_key='$key'");

        // nobody holds it: the screen says so, and offers everyone
        $empty = $this->page($page, 'GET', [], self::$sid);
        $this->assertSame(200, $empty['status']);
        $this->assertStringContainsString('Nobody has been granted this capability', $empty['body']);
        $this->assertStringContainsString('grace@example.test', $empty['body']);

        $data = $this->accepted('/admin/permissions/grant', ['key' => $key, 'user_ids' => [$admin, $other], 'group_ids' => [$group]]);
        $this->assertSame(3, $rows());
        $this->assertNotSame('', $data['message']);
        $this->accepted('/admin/permissions/grant', ['key' => $key, 'user_ids' => [$other]]);
        $this->assertSame(3, $rows(), 'granting again holds it once');

        $held = $this->page($page, 'GET', [], self::$sid)['body'];
        $this->assertStringContainsString('Household', $held);
        $this->assertStringContainsString('Every user and every active group has been granted', $held);
        $this->assertStringContainsString('through a group', $held, 'Grace is in the group that holds it');
        $this->assertMatchesRegularExpression('~allow\.zerotest\.bulk.*?<td class="num">2</td>\s*<td class="num">1</td>~s', $this->page('/admin/permissions', 'GET', [], self::$sid)['body']);

        foreach ([
            ['/admin/permissions/grant', ['key' => $key], 400, 'at least one'],
            ['/admin/permissions/grant', ['key' => 'zerotest.bulk', 'user_ids' => [$other]], 400, 'allow.'],
            ['/admin/permissions/grant', ['key' => $key, 'user_ids' => 'all'], 400, 'list'],
            ['/admin/permissions/grant', ['key' => $key, 'user_ids' => ['x']], 400, 'whole numbers'],
            ['/admin/permissions/grant', ['key' => $key, 'user_ids' => [999999]], 404, 'User 999999'],
            ['/admin/permissions/grant', ['key' => $key, 'group_ids' => [999999]], 404, 'Group 999999'],
            ['/admin/permissions/grant', ['user_ids' => [$other]], 400, 'key'],
            ['/admin/permissions/revoke', ['key' => $key], 400, 'at least one'],
            // one unknown holder refuses the whole request: the known one keeps it
            ['/admin/permissions/revoke', ['key' => $key, 'user_ids' => [$other, 999999]], 404, 'User 999999'],
            ['/admin/permissions/revoke', ['key' => 'allow.admin', 'user_ids' => [$admin]], 409, 'own account'],
        ] as [$uri, $post, $status, $says]) {
            [$got, $detail] = $this->refusal($uri, $post);
            $this->assertSame($status, $got, "$uri " . json_encode($post) . ": $detail");
            $this->assertStringContainsString($says, $detail, $uri);
        }
        $this->assertSame(3, $rows());
        $this->assertSame('1', $this->dbValue("SELECT COUNT(*) FROM permission WHERE permission_key='allow.admin' AND user_id=$admin"), 'the admin is still one');
        $this->assertSame(404, $this->page('/admin/permissions/holders/not/a/key', 'GET', [], self::$sid)['status'], 'a URL that is not a capability key is no page');

        $this->assertSame(302, $this->page('/admin/permissions/revoke', 'POST', ['key' => $key, 'user_ids' => [$admin]], self::$sid)['status']);
        $this->assertSame(2, $rows());
        $this->accepted('/admin/permissions/revoke', ['key' => $key, 'user_ids' => [$other], 'group_ids' => [$group]]);
        $this->assertSame(0, $rows());

        $this->assertGreaterThanOrEqual(2, $this->events('permission_bulk_grant'));
        $this->assertGreaterThanOrEqual(2, $this->events('permission_bulk_revoke'));
    }

    #[Depends('testAnAdminIsLetIn')]
    public function testModules(): void
    {
        $ini = self::$siteDir . '/app/modules.ini';
        $before = file_get_contents($ini);
        $hash = function (): string {
            $this->assertSame(1, preg_match('~name="hash" value="([^"]*)"~', $this->page('/admin/modules', 'GET', [], self::$sid)['body'], $m));
            return html_entity_decode($m[1]);
        };

        try {
            $this->accepted('/admin/modules/set', ['module' => 'Push', 'hash' => $hash(), 'enabled' => 'off', 'order' => '7']);
            $this->assertStringContainsString('Push = off', file_get_contents($ini));
            $this->assertStringContainsString('Push = 7', file_get_contents($ini));
            $this->assertSame(404, $this->page('/admin/push', 'GET', [], self::$sid)['status'], 'a module that is off has no admin screen');
            $this->assertStringNotContainsString('href="/admin/push"', $this->page('/admin', 'GET', [], self::$sid)['body'], 'and no card');

            // back to its default: the departure leaves the file
            $this->assertSame(302, $this->page('/admin/modules/set', 'POST', ['module' => 'Push', 'hash' => $hash(), 'enabled' => 'default', 'order' => ''], self::$sid)['status']);
            $this->assertStringNotContainsString('Push', file_get_contents($ini));
            $this->assertSame(200, $this->page('/admin/push', 'GET', [], self::$sid)['status']);

            $saved = file_get_contents($ini);
            foreach ([
                [['module' => 'Push', 'hash' => 'stale', 'enabled' => 'off'], 409, ''],
                [['module' => 'NoSuchModule', 'hash' => $hash(), 'enabled' => 'off'], 404, 'Unknown module'],
                [['module' => '', 'hash' => $hash(), 'enabled' => 'off'], 400, ''],
                [['module' => 'Push', 'hash' => $hash(), 'enabled' => 'maybe'], 400, 'on, off or default'],
                [['module' => 'Push', 'hash' => $hash(), 'order' => 'first'], 400, 'whole number'],
                [['module' => 'Push', 'hash' => $hash(), 'order' => '10000'], 400, '0 to 9999'],
                [['module' => 'Push', 'hash' => ['x'], 'enabled' => 'off'], 400, 'string'],
                // a required module is always on
                [['module' => 'Admin', 'hash' => $hash(), 'enabled' => 'off'], 400, ''],
                [['module' => 'Push', 'enabled' => 'off'], 400, 'hash'],
            ] as [$post, $status, $says]) {
                [$got, $detail] = $this->refusal('/admin/modules/set', $post);
                $this->assertSame($status, $got, json_encode($post) . ": $detail");
                $this->assertStringContainsString($says, $detail, json_encode($post));
            }
            $this->assertSame($saved, file_get_contents($ini), 'a refused change left the file as it was');
            $this->assertSame(200, $this->page('/admin', 'GET', [], self::$sid)['status']);

            $this->assertGreaterThanOrEqual(2, $this->events('module_setting_change'));
        } finally {
            file_put_contents($ini, $before);
        }
    }

    #[Depends('testACapabilityForManyHoldersAtOnce')]
    public function testTheAuditLogFilters(): void
    {
        $admin = self::$admin;
        $get = fn(string $query) => $this->page('/admin/audit' . $query, 'GET', [], self::$sid)['body'];
        $total = function (string $body): int {
            return preg_match('~of ([0-9,]+) events~', $body, $m) === 1 ? (int) str_replace(',', '', $m[1]) : 0;
        };

        $all = $total($get(''));
        $this->assertGreaterThan(20, $all, 'the tests before this one left their events');

        // one event; one user; a user who did nothing; both at once
        $saves = $total($get('?event=group_save'));
        $this->assertGreaterThanOrEqual(3, $saves);
        $this->assertLessThan($all, $saves);
        // everything an admin did carries their id; the mail of the sign-up carries nobody's
        $theirs = (int) $this->dbValue("SELECT COUNT(*) FROM event_view WHERE detail LIKE '%User ID: $admin %'");
        $this->assertGreaterThan(20, $theirs);
        $this->assertLessThan($all, $theirs, 'the sign-up mail is an event with no user');
        $this->assertSame($theirs, $total($get('?user=' . $admin)));
        $this->assertStringContainsString('No event matches these filters', $get('?user=999999'));
        $this->assertSame($saves, $total($get('?event=group_save&user=' . $admin)));
        $this->assertStringContainsString('No event matches these filters', $get('?event=group_save&user=999999'));

        // by what the detail says, by address, by day
        $this->assertGreaterThan(0, $total($get('?detail=allow.zerotest.bulk')));
        $this->assertSame(0, $total($get('?detail=nothing-says-this')));
        $this->assertSame($all, $total($get('?ip=203.0.113')));
        $this->assertSame(0, $total($get('?ip=198.51.100')));
        $this->assertSame($all, $total($get('?date_from=' . date('Y-m-d', time() - 86400) . '&date_to=' . date('Y-m-d', time() + 86400))));
        $this->assertSame(0, $total($get('?date_to=2001-01-01')));
        $this->assertSame(0, $total($get('?date_from=2099-01-01')));

        // a page holds 25, 50 or 100; any other size is 25. The links to the next page keep the filters
        $paged = $get('?per_page=25&user=' . $admin);
        $this->assertSame(25, substr_count($paged, 'class="nowrap audit-when"'));
        $this->assertStringContainsString('Showing 1 to 25 of', $paged);
        $this->assertMatchesRegularExpression('~href="/admin/audit\?[^"]*user=' . $admin . '[^"]*page=2"|href="/admin/audit\?[^"]*page=2[^"]*user=' . $admin . '~', $paged);
        $this->assertStringContainsString('Showing 26 to', $get('?page=2'));
        $this->assertSame(25, substr_count($get('?per_page=10'), 'class="nowrap audit-when"'), 'a size that is not offered');
        $this->assertGreaterThan(25, substr_count($get('?per_page=100'), 'class="nowrap audit-when"'));

        // what is not a filter value is ignored or refused, never an error page from the database
        foreach (['?per_page=7', '?page=9999', '?page=abc', '?date_from=yesterday', '?user=abc', '?event=' . urlencode("x' OR '1'='1")] as $query) {
            $r = $this->page('/admin/audit' . $query, 'GET', [], self::$sid);
            $this->assertContains($r['status'], [200, 400], $query);
            $this->assertStringNotContainsString('SQLSTATE', $r['body'], $query);
        }

        // each event names who did it, and links to them
        $this->assertStringContainsString('href="/admin/users/edit/' . $admin . '"', $get('?event=group_save'));
    }

    #[Depends('testAnAdminIsLetIn')]
    public function testFirewallUnblockNeedsAnAddress(): void
    {
        // Only the refusal that is decided before Cloudflare is asked: no test calls out.
        [$got, $detail] = $this->refusal('/admin/firewall/unblock', ['ip' => '']);
        $this->assertSame(400, $got);
        $this->assertStringContainsString('No IP provided', $detail);
        $this->assertSame(400, $this->refusal('/admin/firewall/unblock', [])[0]);
        $this->assertSame(200, $this->page('/admin/firewall', 'GET', [], self::$sid)['status']);
    }

    #[Depends('testTheAuditLogFilters')]
    public function testNoRequestTouchedAnotherDatabase(): void
    {
        $this->assertOnlyTheThrowawayDatabase();
    }
}
