<?php
namespace Zero\Module\Admin\Tests;

use Zero\Tests\Core\ZeroTestCase;
use Zero\Module\Admin\Model\UserAdmin;
use Zero\Core\Database;
use Zero\Core\Group;
use Zero\Core\Permission;
use Zero\Core\User;

/**
 * Verifies that the nine tables UserAdmin::delete() clears (the five in
 * UserAdmin::CASCADE and the four Zero\Core\User::delete() owns) are empty
 * when an account is deleted. This test's table list is hardcoded independently of
 * CASCADE, so it catches a table being removed from CASCADE — it does NOT
 * catch the schema growing a tenth user-keyed table, since that would
 * require the two lists to be compared against each other rather than each
 * simply being iterated on its own.
 */
class UserAdminTest extends ZeroTestCase
{
    private const E = 'zerotest-useradmin@example.invalid';

    // Not zerotest-*: the core Group tests delete every group with that prefix.
    private const GROUP = 'ztadmin-cascade-group';

    // Deliberately NOT sourced from UserAdmin::CASCADE — this list must stay
    // independent of the class under test, or a table silently dropped from
    // CASCADE would shrink this list too and the test would pass vacuously.
    private const TABLES = [
        'user_settings',
        'user_profile',
        'auth_token',
        'user_group_member',
        'permission',
        'api_tokens',
        'oauth_token',
        'ws_tokens',
        'push_subscriptions',
    ];

    // The entity the fixture's permission grant is on. Any id does: no row
    // of any module is behind the ztadmin.probe key.
    private const ENTITY = 1;

    private int $userId;
    private int $groupId;

    // Captured the moment seed() creates the row, independent of $userId's
    // typed-property lifecycle, so cleanup() can still find orphaned rows by
    // id after delete() has removed the `users` row the email lookup below
    // depends on. Null until seed() runs. cleanup() must never sweep on
    // null or 0 — see the guard there.
    private ?int $seededUserId = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connectTestDatabase();
        $this->cleanup();
        $this->seed();
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    /** Create one account with a row in every table the cascade must clear. */
    private function seed(): void
    {
        $db = Database::getConnection();

        $db->prepare("INSERT INTO users (name, email, verified) VALUES (?, ?, 1)")
           ->execute(['Cascade Probe', self::E]);
        $this->userId = (int) $db->lastInsertId();
        $this->seededUserId = $this->userId;

        // user_group_member.group_id has the schema's only relevant FK, onto `user_group`.
        // Groups and their members are core's, so the fixture goes through Group.
        $group = Group::create([
            'group_name'  => self::GROUP,
            'description' => 'temporary, deleted in cleanup',
            'created_by'  => $this->userId,
        ]);
        $this->groupId = (int) $group->group_id;

        $db->prepare("INSERT INTO user_settings (user_id, setting_key, setting_value) VALUES (?, ?, ?)")
           ->execute([$this->userId, 'allow.zerotest', '1']);
        $db->prepare("INSERT INTO user_profile (user_id, field_key, value, encrypted) VALUES (?, ?, ?, 0)")
           ->execute([$this->userId, 'zerotest_field', 'probe value']);
        $db->prepare("INSERT INTO auth_token (selector, validator, user_id, purpose, expires_at)
                      VALUES (?, ?, ?, 'verify', DATE_ADD(NOW(), INTERVAL 1 DAY))")
           ->execute([str_repeat('a', 22), str_repeat('b', 64), $this->userId]);
        $group->add($this->userId, 'member', $this->userId);
        // A permission granted to the user directly: also core's, so through Permission.
        $this->assertTrue(
            self::probePermission()->grant(self::ENTITY, User::find($this->userId), true, false, false, $this->userId)
        );
        $db->prepare("INSERT INTO api_tokens (user_id, token_hash, label) VALUES (?, ?, ?)")
           ->execute([$this->userId, str_repeat('c', 64), 'zerotest']);
        $db->prepare("INSERT INTO oauth_token (user_id, provider, access_token) VALUES (?, ?, ?)")
           ->execute([$this->userId, 'zerotest-provider', 'irrelevant']);
        $db->prepare("INSERT INTO ws_tokens (user_id, token, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 1 DAY))")
           ->execute([$this->userId, str_repeat('d', 64)]);
        $db->prepare("INSERT INTO push_subscriptions (user_id, endpoint, p256dh_key, auth_token) VALUES (?, ?, ?, ?)")
           ->execute([$this->userId, 'https://zerotest.invalid/push-endpoint', 'zerotest-p256dh-key', 'zerotest-auth-token']);
    }

    private function cleanup(): void
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([self::E]);
        $ids = $stmt->fetchAll(\PDO::FETCH_COLUMN);

        // delete() removes the `users` row itself, so once the test's own
        // subject has run (successfully, or failed partway through the
        // assertion loop below) the email lookup above finds nothing and
        // cannot locate any row still keyed to the id seed() created. Sweep
        // that id too, in addition to email. Guarded to only ever fire on an
        // id this test itself seeded: production currently holds 8 rows
        // with user_id = 0 dating from January, and an unguarded sweep on 0
        // (or on this property before seed() has run, when it is still
        // null) would destroy real data.
        if ($this->seededUserId !== null && $this->seededUserId > 0 && !in_array($this->seededUserId, $ids, true)) {
            $ids[] = $this->seededUserId;
        }

        foreach ($ids as $id) {
            foreach (self::TABLES as $table) {
                $db->prepare("DELETE FROM `{$table}` WHERE user_id = ?")->execute([$id]);
            }
        }

        $db->prepare("DELETE FROM users WHERE email = ?")->execute([self::E]);
        if ($this->seededUserId !== null && $this->seededUserId > 0) {
            $db->prepare("DELETE FROM users WHERE id = ?")->execute([$this->seededUserId]);
        }
        foreach (Group::all() as $group) {
            if ($group->group_name === self::GROUP) {
                $group->delete();
            }
        }
        self::probePermission()->forget(self::ENTITY);
    }

    /** The permission the fixture grants: a key no module owns. */
    private static function probePermission(): Permission
    {
        return new Permission('ztadmin', 'probe');
    }

    private function countIn(string $table): int
    {
        $stmt = Database::getConnection()
            ->prepare("SELECT COUNT(*) FROM `{$table}` WHERE user_id = ?");
        $stmt->execute([$this->userId]);
        return (int) $stmt->fetchColumn();
    }

    public function testSeedCreatedARowInEveryCascadedTable(): void
    {
        foreach (self::TABLES as $table) {
            $this->assertSame(1, $this->countIn($table),
                "Fixture is not exercising {$table}; the cascade test would pass vacuously.");
        }
    }

    public function testDeleteLeavesNothingBehind(): void
    {
        $this->assertGreaterThan(0, $this->userId);

        (new UserAdmin(Database::getConnection()))->delete($this->userId);

        foreach (self::TABLES as $table) {
            $this->assertSame(0, $this->countIn($table),
                "Orphan rows survived in {$table} after the account was deleted.");
        }

        $stmt = Database::getConnection()->prepare("SELECT COUNT(*) FROM users WHERE id = ?");
        $stmt->execute([$this->userId]);
        $this->assertSame(0, (int) $stmt->fetchColumn());
    }

    public function testDeletingAnUnknownUserThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        (new UserAdmin(Database::getConnection()))->delete(2147483600);
    }
}
