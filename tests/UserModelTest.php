<?php
namespace Zero\Tests\Core;

use Zero\Core\Database;
use Zero\Core\User;

/**
 * User-specific behaviour of the migrated table — above all, that the
 * UNIQUE KEY on email is real. Under the old EAV storage this was an
 * application-level SELECT-then-write with a TOCTOU window.
 */
class UserModelTest extends ZeroTestCase
{
    private const E = 'zerotest-user-1@example.invalid';

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

    public function testDuplicateEmailIsRejectedByTheDatabase(): void
    {
        User::create(['name' => 'First', 'email' => self::E, 'verified' => 1]);

        try {
            User::create(['name' => 'Second', 'email' => self::E, 'verified' => 1]);
            $this->fail('Expected a PDOException on duplicate email');
        } catch (\PDOException $e) {
            $this->assertTrue(
                User::isDuplicateKey($e),
                'isDuplicateKey() should recognise SQLSTATE 23000'
            );
        }
    }

    public function testIsDuplicateKeyRejectsOtherSqlStates(): void
    {
        // PDOException has no setCode() and its constructor takes an int, so a
        // string SQLSTATE cannot be faked from userland — provoke a real one.
        // A missing table yields '42S02'.
        try {
            Database::getConnection()->query("SELECT * FROM definitely_not_a_table");
            $this->fail('Expected a PDOException for a missing table');
        } catch (\PDOException $e) {
            $this->assertSame('42S02', $e->getCode());
            $this->assertFalse(User::isDuplicateKey($e));
        }
    }

    public function testVerifiedReadsBackAsInt(): void
    {
        $u = User::create(['name' => 'Typed', 'email' => self::E, 'verified' => 1]);

        // PDO with ATTR_EMULATE_PREPARES => false returns TINYINT as a PHP int.
        // Under the old EAV storage this was the string '1'.
        $this->assertSame(1, User::find($u->id)->verified);
    }

    public function testMaxLengthEmailSurvivesRoundTrip(): void
    {
        // Exactly 254 chars, the RFC 5321 maximum: 9 + 229 + 16. Deliberately
        // built with the zerotest- prefix and .invalid suffix so cleanup()
        // reclaims it even if the assertion fails.
        $long = 'zerotest-' . str_repeat('a', 229) . '@example.invalid';
        $this->assertSame(254, strlen($long));

        $u = User::create(['name' => 'Long', 'email' => $long, 'verified' => 0]);
        $this->assertSame($long, User::find($u->id)->email);
    }
}
