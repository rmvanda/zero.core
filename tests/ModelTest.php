<?php
namespace Zero\Tests\Core;

use Zero\Tests\Core\ZeroTestCase;

use Zero\Core\Database;
use Zero\Core\User;

/**
 * Exercises the Zero\Core\Model base class through its first concrete
 * subclass. Uses sentinel emails on the .invalid TLD so a stray row can
 * never collide with a real account.
 */
class ModelTest extends ZeroTestCase
{
    private const E1 = 'zerotest-model-1@example.invalid';
    private const E2 = 'zerotest-model-2@example.invalid';

    protected function setUp(): void
    {
        parent::setUp();
        $this->connectTestDatabase();
        $this->cleanup();
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    private function cleanup(): void
    {
        Database::getConnection()
            ->prepare("DELETE FROM users WHERE email LIKE 'zerotest-%@example.invalid'")
            ->execute();
    }

    public function testCreateThenFindRoundTrip(): void
    {
        $created = User::create([
            'name' => 'Model Probe', 'email' => self::E1, 'verified' => 1, 'pic' => null,
        ]);

        $this->assertNotNull($created->id);

        $loaded = User::find($created->id);
        $this->assertNotNull($loaded);
        $this->assertSame('Model Probe', $loaded->name);
        $this->assertSame(self::E1, $loaded->email);
    }

    public function testFindByReturnsNullOnMiss(): void
    {
        $this->assertNull(User::findBy('email', 'zerotest-nobody@example.invalid'));
    }

    public function testFindByReturnsSingleInstanceNotArray(): void
    {
        User::create(['name' => 'Solo', 'email' => self::E1, 'verified' => 1]);

        $found = User::findBy('email', self::E1);
        $this->assertInstanceOf(User::class, $found);
        $this->assertIsNotArray($found);
    }

    public function testAllByReturnsArray(): void
    {
        User::create(['name' => 'Dup Name', 'email' => self::E1, 'verified' => 1]);
        User::create(['name' => 'Dup Name', 'email' => self::E2, 'verified' => 1]);

        $rows = User::allBy('name', 'Dup Name');
        $this->assertIsArray($rows);
        $this->assertCount(2, $rows);
        $this->assertInstanceOf(User::class, $rows[0]);
    }

    public function testNonWhitelistedColumnIsNotWritten(): void
    {
        // created_at is a real column but is NOT in User::$columns — the DDL owns it.
        $u = User::create([
            'name' => 'Whitelist Probe', 'email' => self::E1, 'verified' => 1,
            'created_at' => '1999-01-01 00:00:00',
        ]);

        $stmt = Database::getConnection()->prepare("SELECT created_at FROM users WHERE id = ?");
        $stmt->execute([$u->id]);
        $this->assertStringStartsNotWith('1999', $stmt->fetchColumn());

        // ...and the object must not echo it back either, or it lies about a
        // DDL-owned column for the rest of its lifetime.
        $this->assertNull($u->created_at);
    }

    public function testSaveOnLoadedRowUpdatesInsteadOfInserting(): void
    {
        $u = User::create(['name' => 'Before', 'email' => self::E1, 'verified' => 1]);
        $id = $u->id;

        $loaded = User::find($id);
        $loaded->name = 'After';
        $loaded->save();

        $this->assertSame('After', User::find($id)->name);

        $stmt = Database::getConnection()->prepare("SELECT COUNT(*) FROM users WHERE email = ?");
        $stmt->execute([self::E1]);
        $this->assertSame(1, (int) $stmt->fetchColumn());
    }

    public function testDeleteRemovesTheRow(): void
    {
        $u = User::create(['name' => 'Doomed', 'email' => self::E1, 'verified' => 1]);
        $id = $u->id;

        $u->delete();

        $this->assertNull(User::find($id));
    }

    public function testUnknownColumnInFindByThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        User::findBy('nonexistent_column', 'x');
    }
}
