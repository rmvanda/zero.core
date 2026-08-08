<?php
namespace Zero\Core;

/**
 * TrackedStatement - PDOStatement subclass that times and records every query.
 *
 * Applied to the PDO connection via ATTR_STATEMENT_CLASS so ALL queries
 * are captured regardless of whether they go through Database::execute()
 * or direct getConnection()->prepare() calls.
 */
class TrackedStatement extends \PDOStatement {

    /** @var array Bound parameter values, populated by our bindValue/bindParam overrides */
    private array $boundParams = [];

    protected function __construct() {}

    public function execute(?array $params = null): bool {
        $start = microtime(true);
        $result = parent::execute($params);
        $elapsed = round((microtime(true) - $start) * 1000, 3);

        // Merge params passed to execute() with any previously bound params
        $allParams = array_merge($this->boundParams, $params ?? []);

        Database::recordQuery(
            $this->queryString,
            $allParams,
            $elapsed,
            $this->rowCount()
        );

        // Reset bound params after execution
        $this->boundParams = [];

        return $result;
    }

    public function bindValue($param, $value, $type = \PDO::PARAM_STR): bool {
        $this->boundParams[$param] = $value;
        return parent::bindValue($param, $value, $type);
    }

    public function bindParam($param, &$var, $type = \PDO::PARAM_STR, $maxLength = 0, $driverOptions = null): bool {
        $this->boundParams[$param] = $var;
        return parent::bindParam($param, $var, $type, $maxLength, $driverOptions);
    }
}

/**
 * Database Base Class
 *
 * Provides PDO connection using constants from database.ini
 * Modules should extend this for their specific database operations
 */
class Database {

    public static $connection;

    public static $pdoOpts = [
        \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
        \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        \PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    /**
     * Whether query tracking has been enabled (opt-in by DevToolbar plugin)
     */
    private static bool $trackingEnabled = false;

    /**
     * Recorded queries for DevToolbar display
     */
    private static array $queries = [];

    /**
     * Enable query tracking — called by DevToolbar plugin in afterConstants().
     * Never called in production or when the plugin is disabled.
     * If a connection already exists, applies tracking immediately.
     * Otherwise getConnection()/init() will apply it when the connection is created.
     */
    public static function enableQueryTracking(): void {
        self::$trackingEnabled = true;
        if (self::$connection) {
            self::$connection->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [TrackedStatement::class, []]);
        }
    }

    /**
     * Record a query execution — called by TrackedStatement
     */
    public static function recordQuery(string $sql, array $params, float $ms, int $rows): void {
        self::$queries[] = [
            'sql'    => $sql,
            'params' => $params,
            'ms'     => $ms,
            'rows'   => $rows,
        ];
    }

    /**
     * Get all queries recorded during this request
     * Used by DevToolbar plugin
     */
    public static function getQueries(): array {
        return self::$queries;
    }

    /**
     * Build a PDO DSN string for the requested driver.
     *
     * Deliberately pure — it opens nothing and touches no state — so DSN
     * construction can be unit tested without a live database.
     *
     * Recognised keys:
     *   driver  PDO driver name; 'sqltype' is accepted as a legacy alias
     *   host    Server hostname (ignored by sqlite)
     *   port    Omitted when blank so the driver applies its own default
     *   name    Database name, or the file path for sqlite
     *   charset Client encoding; applied per-driver, see below
     *
     * @param array $conn_info Connection settings
     * @return string PDO DSN
     */
    public static function buildDsn(array $conn_info): string {
        $driver  = strtolower($conn_info['driver'] ?? $conn_info['sqltype'] ?? 'mysql');
        $host    = $conn_info['host'] ?? 'localhost';
        $name    = $conn_info['name'] ?? '';
        // Treat blank as absent: an empty DB_PORT= in the ini means
        // "let the driver pick" (3306 for mysql, 5432 for pgsql), which
        // is more correct than us hard-coding a default here.
        $port    = ($conn_info['port']    ?? '') !== '' ? $conn_info['port']    : null;
        $charset = ($conn_info['charset'] ?? '') !== '' ? $conn_info['charset'] : null;

        // sqlite has no host, port or credentials — just a file path (or :memory:)
        if ($driver === 'sqlite') {
            return "sqlite:$name";
        }

        $parts = ["host=$host"];
        if ($port !== null) {
            $parts[] = "port=$port";
        }
        $parts[] = "dbname=$name";

        if ($driver === 'mysql') {
            // MySQL takes the charset directly in the DSN. The utf8mb4 default
            // matches the collation the existing schema was created with.
            $parts[] = "charset=" . ($charset ?? 'utf8mb4');
        } elseif ($driver === 'pgsql' && $charset !== null) {
            // Postgres has no charset DSN parameter — the client encoding is
            // passed through as a libpq startup option instead.
            $parts[] = "options='--client_encoding=$charset'";
        }
        // Any other driver falls through to the generic host/port/dbname form,
        // so adding one is a config change rather than a code change.

        return $driver . ":" . implode(";", $parts);
    }

    /**
     * Open a standalone connection without touching the singleton.
     *
     * self::$connection is a singleton and, in practice, is already holding the
     * primary MySQL connection long before module code runs — the login and
     * permission attributes reach for it first. Anything needing a *second*
     * database (a different driver, host, or credentials) goes through here
     * instead, and owns the returned handle itself.
     *
     * @param array $conn_info Connection settings, as accepted by buildDsn()
     * @return \PDO A fresh connection, unrelated to self::$connection
     */
    public static function connect(array $conn_info): \PDO {
        // Null-safe because sqlite takes neither user nor password
        $pdo = new \PDO(
            self::buildDsn($conn_info),
            $conn_info['user'] ?? null,
            $conn_info['pass'] ?? null,
            self::$pdoOpts
        );

        if (self::$trackingEnabled) {
            $pdo->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [TrackedStatement::class, []]);
        }

        return $pdo;
    }

    /**
     * Initialize database connection explicitly
     * Useful when you want to set up connection with specific parameters
     *
     * @param \PDO|array $conn_info PDO instance or connection array
     * @return bool True if connection was created, false if already exists
     */
    public static function init(\PDO|array $conn_info): bool {
        if (self::$connection) {
            return false;
        }

        if (is_array($conn_info)) {
            self::$connection = self::connect($conn_info);
        } elseif ($conn_info instanceof \PDO) {
            self::$connection = $conn_info;
        }

        if (self::$trackingEnabled) {
            self::$connection->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [TrackedStatement::class, []]);
        }

        return true;
    }

    /**
     * Get database connection (singleton pattern)
     * Lazy-loads connection using constants from database.ini if not already initialized
     *
     * @return \PDO
     */
    public static function getConnection(): \PDO {
        if (!isset(self::$connection)) {
            // Lazy initialization using constants from database.ini
            try {
                // DB_DRIVER/DB_PORT/DB_CHARSET are optional additions to
                // database.ini — with none of them set this yields exactly the
                // mysql DSN this method has always built.
                $dsn = self::buildDsn([
                    'driver'  => defined('DB_DRIVER')  ? DB_DRIVER  : 'mysql',
                    'host'    => HOST,
                    'port'    => defined('DB_PORT')    ? DB_PORT    : null,
                    'name'    => NAME,
                    'charset' => defined('DB_CHARSET') ? DB_CHARSET : null,
                ]);
                self::$connection = new \PDO($dsn, USER, PASS, self::$pdoOpts);
                if (self::$trackingEnabled) {
                    self::$connection->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [TrackedStatement::class, []]);
                }
            } catch (\PDOException $e) {
                error_log("Database connection failed: " . $e->getMessage());
                throw new \Exception("Database connection failed");
            }
        }
        return self::$connection;
    }

    /**
     * Execute a prepared statement
     *
     * @param string $query SQL query with placeholders
     * @param array $params Parameters for the query
     * @return \PDOStatement
     */
    public function execute($query, $params = []) {
        $stmt = $this->getConnection()->prepare($query);
        $stmt->execute($params);
        return $stmt;
    }

    /**
     * Get last inserted ID
     *
     * @return string
     */
    public function lastInsertId() {
        return $this->getConnection()->lastInsertId();
    }
}
