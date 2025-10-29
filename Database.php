<?php
namespace Zero\Core;

/**
 * Database Base Class
 *
 * Provides PDO connection using constants from database.ini
 * Modules should extend this for their specific database operations
 */
class Database {

    protected static $connection;

    /**
     * Get database connection (singleton pattern)
     *
     * @return \PDO
     */
    protected function getConnection() {
        if (!isset(self::$connection)) {
            try {
                $dsn = "mysql:host=" . HOST . ";dbname=" . NAME . ";charset=utf8mb4";
                self::$connection = new \PDO($dsn, USER, PASS);
                self::$connection->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
                self::$connection->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
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
    protected function execute($query, $params = []) {
        $stmt = $this->getConnection()->prepare($query);
        $stmt->execute($params);
        return $stmt;
    }

    /**
     * Get last inserted ID
     *
     * @return string
     */
    protected function lastInsertId() {
        return $this->getConnection()->lastInsertId();
    }
}
