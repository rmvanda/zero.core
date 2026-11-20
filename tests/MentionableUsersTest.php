<?php
namespace Zero\Tests\Core;

use Zero\Core\Access\Group;
use Zero\Core\Access\User;

/**
 * User::mentionableUsers() against the real `users`, `user_group` and
 * `user_group_member` tables. Every row it makes is named zerotest-* and is
 * removed again in cleanup().
 */
class MentionableUsersTest extends ZeroTestCase
{
    private User $asker;
    private User $mate;
    private User $stranger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connectTestDatabase();
        $this->cleanup();

        $this->asker    = User::create(['name' => 'Zerotest Asker', 'email' => 'zerotest-mention-asker@example.invalid', 'verified' => 1]);
        $this->mate     = User::create(['name' => 'Zerotest Findme', 'email' => 'zerotest-mention-Mate@example.invalid', 'verified' => 1]);
        $this->stranger = User::create(['name' => 'Zerotest Findme Too', 'email' => 'zerotest-mention-stranger@example.invalid', 'verified' => 1]);
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    private function cleanup(): void
    {
        $this->removeTestRows('zerotest-mention-%@example.invalid', 'zerotest-mention%');
    }

    private function shareAGroup(User ...$users): Group
    {
        $group = Group::create(['group_name' => 'zerotest-mention-group', 'description' => 'temporary', 'created_by' => (int) $this->asker->id]);
        foreach ($users as $user) {
            $group->add($user);
        }
        return $group;
    }

    /** id of each row, in order. */
    private function ids(array $rows): array
    {
        return array_column($rows, 'id');
    }

    public function testAUserInNoGroupSeesNobody(): void
    {
        $this->assertSame([], $this->asker->mentionableUsers());
        $this->assertSame([], $this->asker->mentionableUsers('zerotest'));
    }

    public function testAUserSeesTheMembersOfItsGroupsAndNotItself(): void
    {
        $this->shareAGroup($this->asker, $this->mate);

        $rows = $this->asker->mentionableUsers();
        $this->assertSame([(int) $this->mate->id], $this->ids($rows));
        $this->assertSame(['id', 'name', 'email'], array_keys($rows[0]));
    }

    public function testAnInactiveGroupShowsNobody(): void
    {
        $this->shareAGroup($this->asker, $this->mate)->deactivate();

        $this->assertSame([], $this->asker->mentionableUsers());
    }

    public function testTheTermFindsANameOrAnEmailWithoutRegardToCase(): void
    {
        $this->shareAGroup($this->asker, $this->mate);

        $this->assertSame([(int) $this->mate->id], $this->ids($this->asker->mentionableUsers('findme')));
        // The email column has a binary collation; the search must still ignore case.
        $this->assertSame([(int) $this->mate->id], $this->ids($this->asker->mentionableUsers('ZEROTEST-MENTION-MATE')));
        $this->assertSame([], $this->asker->mentionableUsers('zerotest-mention-stranger'));
        // "%" must not match every user
        $this->assertSame([], $this->asker->mentionableUsers('%%'));
    }

    public function testAnAdminSeesEveryUser(): void
    {
        $this->asker->grant('allow.admin', (int) $this->asker->id);

        $ids = $this->ids($this->asker->mentionableUsers('zerotest-mention-'));
        $this->assertContains((int) $this->mate->id, $ids);
        $this->assertContains((int) $this->stranger->id, $ids);
        $this->assertNotContains((int) $this->asker->id, $ids);
    }

    public function testTheLimitCapsTheRows(): void
    {
        $this->shareAGroup($this->asker, $this->mate, $this->stranger);

        $this->assertCount(2, $this->asker->mentionableUsers());
        $this->assertCount(1, $this->asker->mentionableUsers(null, 1));
    }
}
