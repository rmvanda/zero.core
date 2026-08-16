<?php

namespace Zero\Tests\Core;

use PHPUnit\Framework\TestCase;

/**
 * Base test case for Zero Framework core tests.
 *
 * Provides helpers for mocking superglobals and framework constants
 * that many core classes depend on.
 */
abstract class ZeroTestCase extends TestCase
{
    /* ── Superglobal backup/restore ── */

    private array $backupServer;
    private array $backupPost;
    private array $backupGet;
    private array $backupSession;
    private string $backupLogFile;
    private string $testLogFile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->backupServer  = $_SERVER;
        $this->backupPost    = $_POST;
        $this->backupGet     = $_GET;
        $this->backupSession = $_SESSION ?? [];

        // Redirect Console log output to a temp file so tests don't need
        // write access to /var/log/php-fpm/
        $this->backupLogFile = \Zero\Core\Console::$defaultLogFile;
        $this->testLogFile   = sys_get_temp_dir() . '/zero_test_' . spl_object_id($this) . '.log';
        \Zero\Core\Console::$defaultLogFile = $this->testLogFile;
    }

    protected function tearDown(): void
    {
        $_SERVER  = $this->backupServer;
        $_POST    = $this->backupPost;
        $_GET     = $this->backupGet;
        $_SESSION = $this->backupSession;

        // Reset static state that core classes cache
        \Zero\Core\Console::clearMessages();
        \Zero\Core\Console::$defaultLogFile = $this->backupLogFile;
        \Zero\Core\Database::$connection = null;

        if (file_exists($this->testLogFile)) {
            unlink($this->testLogFile);
        }

        parent::tearDown();
    }

    /* ── $_SERVER helpers ── */

    /**
     * Set up $_SERVER for a simulated HTTP request.
     */
    protected function simulateRequest(
        string $uri,
        string $method = 'GET',
        string $host = 'unisolu.com',
        bool $https = true,
        bool $ajax = false,
        ?string $accept = null,
    ): void {
        $_SERVER['REQUEST_URI']  = $uri;
        $_SERVER['REQUEST_METHOD'] = $method;
        $_SERVER['HTTP_HOST'] = $host;
        $_SERVER['HTTPS'] = $https ? 'on' : '';
        $_SERVER['HTTP_X_REQUESTED_WITH'] = $ajax ? 'XMLHttpRequest' : '';
        $_SERVER['HTTP_ACCEPT'] = $accept ?? 'text/html';

        // Clear forwarded-IP headers unless explicitly set
        unset(
            $_SERVER['HTTP_X_FORWARDED_FOR'],
            $_SERVER['HTTP_CF_CONNECTING_IP'],
        );
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    }

    /**
     * Bootstrap a real DB connection for tests that touch the database.
     * Reads app/config/database.ini directly (defineConstants() doesn't run
     * under PHPUnit) and injects it via Database::init(). tearDown() already
     * resets Database::$connection = null.
     */
    protected function connectTestDatabase(): void
    {
        if (isset(\Zero\Core\Database::$connection)) {
            return;
        }
        $iniPath = dirname(__DIR__, 2) . '/app/config/database.ini';
        $ini = parse_ini_file($iniPath, false, INI_SCANNER_RAW);
        \Zero\Core\Database::init([
            'host' => $ini['HOST'],
            'name' => $ini['NAME'],
            'user' => $ini['USER'],
            'pass' => $ini['PASS'],
        ]);
    }

    /* ── $_SESSION helpers ── */

    /**
     * Populate $_SESSION with a logged-in user.
     */
    protected function loginUser(array $overrides = []): void
    {
        $defaults = [
            'user_id'    => 1,
            'name'       => 'Test User',
            'email'      => 'test@example.com',
            'verified'   => true,
            'pic'        => '/img/default.png',
        ];

        $data = array_merge($defaults, $overrides);

        $_SESSION['user_id']    = $data['user_id'];
        $_SESSION['name']       = $data['name'];
        $_SESSION['email']      = $data['email'];
        $_SESSION['verified']   = $data['verified'];
        $_SESSION['pic']        = $data['pic'];
        $_SESSION['user'] = [
            'id'        => $data['user_id'],
            'full_name' => $data['name'],
            'email'     => $data['email'],
            'verified'  => $data['verified'],
            'pic'       => $data['pic'],
        ];
    }

    /**
     * Ensure the session is empty (logged-out state).
     */
    protected function logoutUser(): void
    {
        $_SESSION = [];
    }

    /* ── PDO mock helpers ── */

    /**
     * Create a mock PDO that returns a preconfigured statement.
     *
     * @param array|false $fetchReturn  What fetch() should return
     * @param array       $fetchAllReturn What fetchAll() should return
     * @param bool        $executeReturn What execute() should return
     */
    protected function createMockPdo(
        array|false $fetchReturn = false,
        array $fetchAllReturn = [],
        bool $executeReturn = true,
        ?int $rowCount = null,
    ): \PDO {
        $stmt = $this->createStub(\PDOStatement::class);
        $stmt->method('execute')->willReturn($executeReturn);
        $stmt->method('fetch')->willReturn($fetchReturn);
        $stmt->method('fetchAll')->willReturn($fetchAllReturn);
        if ($rowCount !== null) {
            $stmt->method('rowCount')->willReturn($rowCount);
        }

        $pdo = $this->createStub(\PDO::class);
        $pdo->method('prepare')->willReturn($stmt);
        $pdo->method('lastInsertId')->willReturn('1');

        return $pdo;
    }
}
