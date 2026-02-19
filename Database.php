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
            $user = $conn_info['user'];
            $host = $conn_info['host'] ?? 'localhost';
            $pass = $conn_info['pass'];
            $name = $conn_info['name'];
            $charset = $conn_info['charset'] ?? 'utf8mb4';
            $sqltype = $conn_info['sqltype'] ?? 'mysql';

            $dsn = "$sqltype:host=$host;dbname=$name;charset=$charset";
            self::$connection = new \PDO($dsn, $user, $pass, self::$pdoOpts);
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
                $dsn = "mysql:host=" . HOST . ";dbname=" . NAME . ";charset=utf8mb4";
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
