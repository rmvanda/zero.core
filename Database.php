<?php
namespace Zero\Core;

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
