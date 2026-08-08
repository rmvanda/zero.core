<?php

namespace Zero\Tests\Core;

use PHPUnit\Framework\Attributes\DataProvider;
use Zero\Core\Database;

class DatabaseTest extends ZeroTestCase
{
    /* ── buildDsn() ── */

    #[DataProvider('dsnProvider')]
    public function testBuildDsn(string $expected, array $config): void
    {
        $this->assertSame($expected, Database::buildDsn($config));
    }

    public static function dsnProvider(): array
    {
        return [
            // Default driver is mysql, and the charset default is utf8mb4 —
            // this is the exact DSN the framework built before it was portable.
            'defaults to mysql' => [
                'mysql:host=localhost;dbname=unisolu;charset=utf8mb4',
                ['host' => 'localhost', 'name' => 'unisolu'],
            ],
            'mysql explicit charset' => [
                'mysql:host=db.local;dbname=app;charset=latin1',
                ['driver' => 'mysql', 'host' => 'db.local', 'name' => 'app', 'charset' => 'latin1'],
            ],

            // Postgres must NOT receive a charset= parameter; it has no such key.
            'pgsql omits charset key' => [
                'pgsql:host=db.local;dbname=app',
                ['driver' => 'pgsql', 'host' => 'db.local', 'name' => 'app'],
            ],
            'pgsql charset becomes client_encoding' => [
                "pgsql:host=db.local;dbname=app;options='--client_encoding=UTF8'",
                ['driver' => 'pgsql', 'host' => 'db.local', 'name' => 'app', 'charset' => 'UTF8'],
            ],
            'pgsql with port' => [
                'pgsql:host=db.local;port=5433;dbname=app',
                ['driver' => 'pgsql', 'host' => 'db.local', 'port' => 5433, 'name' => 'app'],
            ],

            // Blank port must be dropped so the driver applies its own default
            'blank port omitted' => [
                'mysql:host=localhost;dbname=app;charset=utf8mb4',
                ['host' => 'localhost', 'port' => '', 'name' => 'app'],
            ],
            'blank charset falls back to utf8mb4' => [
                'mysql:host=localhost;dbname=app;charset=utf8mb4',
                ['host' => 'localhost', 'name' => 'app', 'charset' => ''],
            ],

            // sqlite takes a bare path, no host/port/credentials
            'sqlite path' => [
                'sqlite:/var/db/app.sqlite',
                ['driver' => 'sqlite', 'name' => '/var/db/app.sqlite', 'host' => 'ignored'],
            ],
            'sqlite memory' => [
                'sqlite::memory:',
                ['driver' => 'sqlite', 'name' => ':memory:'],
            ],

            // Legacy alias accepted by init() before buildDsn() existed
            'sqltype alias' => [
                'pgsql:host=localhost;dbname=app',
                ['sqltype' => 'pgsql', 'name' => 'app'],
            ],
            'driver wins over sqltype alias' => [
                'pgsql:host=localhost;dbname=app',
                ['driver' => 'pgsql', 'sqltype' => 'mysql', 'name' => 'app'],
            ],
            'driver name is case insensitive' => [
                'pgsql:host=localhost;dbname=app',
                ['driver' => 'PGSQL', 'name' => 'app'],
            ],

            // Unknown drivers get the generic form rather than an error
            'unknown driver generic form' => [
                'firebird:host=localhost;dbname=app',
                ['driver' => 'firebird', 'name' => 'app'],
            ],
            'host defaults to localhost' => [
                'mysql:host=localhost;dbname=app;charset=utf8mb4',
                ['name' => 'app'],
            ],
        ];
    }

    /* ── connect() ── */

    /**
     * The whole point of connect(): a second database alongside the primary
     * one, without disturbing the singleton the rest of the framework uses.
     */
    public function testConnectLeavesSingletonUntouched(): void
    {
        $primary = $this->createMockPdo();
        Database::init($primary);

        $secondary = Database::connect(['driver' => 'sqlite', 'name' => ':memory:']);

        $this->assertInstanceOf(\PDO::class, $secondary);
        $this->assertNotSame($primary, $secondary);
        $this->assertSame($primary, Database::getConnection());
    }

    public function testConnectWorksWithNoSingletonPresent(): void
    {
        Database::$connection = null;

        $pdo = Database::connect(['driver' => 'sqlite', 'name' => ':memory:']);

        $this->assertInstanceOf(\PDO::class, $pdo);
        // connect() must not lazily populate the singleton as a side effect
        $this->assertNull(Database::$connection);
    }

    public function testConnectAppliesSharedPdoOptions(): void
    {
        $pdo = Database::connect(['driver' => 'sqlite', 'name' => ':memory:']);

        $this->assertSame(\PDO::ERRMODE_EXCEPTION, $pdo->getAttribute(\PDO::ATTR_ERRMODE));
    }

    public function testConnectReturnsUsableConnection(): void
    {
        $pdo = Database::connect(['driver' => 'sqlite', 'name' => ':memory:']);

        $this->assertSame('1', (string) $pdo->query('SELECT 1 AS one')->fetchColumn());
    }

    /* ── init() with PDO instance ── */

    public function testInitWithPdoInstance(): void
    {
        $pdo = $this->createMockPdo();

        $result = Database::init($pdo);

        $this->assertTrue($result);
        $this->assertSame($pdo, Database::getConnection());
    }

    public function testInitReturnsFalseIfAlreadyConnected(): void
    {
        $pdo1 = $this->createMockPdo();
        $pdo2 = $this->createMockPdo();

        Database::init($pdo1);
        $result = Database::init($pdo2);

        $this->assertFalse($result);
        $this->assertSame($pdo1, Database::getConnection());
    }

    /* ── execute() ── */

    public function testExecuteReturnsPdoStatement(): void
    {
        $pdo = $this->createMockPdo();
        Database::init($pdo);

        $db = new Database();
        $stmt = $db->execute('SELECT 1');

        $this->assertInstanceOf(\PDOStatement::class, $stmt);
    }

    public function testExecutePassesParams(): void
    {
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->expects($this->once())
             ->method('execute')
             ->with([1, 'test'])
             ->willReturn(true);

        $pdo = $this->createMock(\PDO::class);
        $pdo->expects($this->once())
            ->method('prepare')
            ->with('SELECT * FROM users WHERE id = ? AND name = ?')
            ->willReturn($stmt);

        Database::init($pdo);

        $db = new Database();
        $db->execute('SELECT * FROM users WHERE id = ? AND name = ?', [1, 'test']);
    }

    /* ── lastInsertId() ── */

    public function testLastInsertId(): void
    {
        $pdo = $this->createStub(\PDO::class);
        $pdo->method('lastInsertId')->willReturn('42');

        Database::init($pdo);

        $db = new Database();
        $this->assertSame('42', $db->lastInsertId());
    }

    /* ── Query tracking ── */

    public function testQueryTrackingDisabledByDefault(): void
    {
        // getQueries should return empty array when tracking is not enabled
        $this->assertSame([], Database::getQueries());
    }

    public function testRecordQueryManually(): void
    {
        Database::recordQuery('SELECT 1', [], 1.5, 1);

        $queries = Database::getQueries();
        $this->assertCount(1, $queries);
        $this->assertSame('SELECT 1', $queries[0]['sql']);
        $this->assertSame(1.5, $queries[0]['ms']);
        $this->assertSame(1, $queries[0]['rows']);
    }

    public function testEnableQueryTracking(): void
    {
        $pdo = $this->createMock(\PDO::class);
        // When tracking is enabled on an existing connection, setAttribute should be called
        $pdo->expects($this->once())
            ->method('setAttribute')
            ->with(\PDO::ATTR_STATEMENT_CLASS, $this->anything());

        Database::init($pdo);
        Database::enableQueryTracking();
    }

    /* ── Static connection reset (for test isolation) ── */

    public function testConnectionCanBeReset(): void
    {
        $pdo = $this->createMockPdo();
        Database::init($pdo);

        $this->assertNotNull(Database::$connection);

        Database::$connection = null;
        $this->assertNull(Database::$connection);
    }
}
