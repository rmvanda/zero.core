<?php

namespace Zero\Core;

/**
 * User Class
 *
 * Provides convenient methods for accessing user session data
 * and checking authentication status.
 *
 * @author James Pope
 */
class User
{
    /**
     * Check if user is logged in and verified
     *
     * @return bool
     */
    public static function isLoggedIn(): bool
    {
        return isset($_SESSION['email']) &&
               isset($_SESSION['verified']) &&
               $_SESSION['verified'];
    }

    /**
     * Get user's full name
     *
     * @return string|null
     */
    public static function getName(): ?string
    {
        return $_SESSION['name'] ?? $_SESSION['user']['full_name'] ?? null;
    }

    /**
     * Get user's profile picture URL
     *
     * @return string|null
     */
    public static function getPicture(): ?string
    {
        return $_SESSION['pic'] ?? $_SESSION['user']['pic'] ?? null;
    }

    /**
     * Get user's email
     *
     * @return string|null
     */
    public static function getEmail(): ?string
    {
        return $_SESSION['email'] ?? $_SESSION['user']['email'] ?? null;
    }

    /**
     * Get user's ID
     *
     * @return mixed
     */
    public static function getId()
    {
        return $_SESSION['user']['id'] ?? null;
    }

    /**
     * Get user's auth level
     *
     * @return int
     */
    public static function getAuthLevel(): int
    {
        return $_SESSION['auth_level'] ?? 0;
    }

    /**
     * Check if user is verified
     *
     * @return bool
     */
    public static function isVerified(): bool
    {
        return isset($_SESSION['verified']) && $_SESSION['verified'];
    }

    /**
     * Get all user session data
     *
     * @return array
     */
    public static function getAll(): array
    {
        return $_SESSION['user'] ?? [];
    }

    /**
     * Logout user by clearing session
     *
     * @return void
     */
    public static function logout(): void
    {
        session_unset();
        session_destroy();
    }

    /**
     * Check if user has a specific permission
     *
     * @param string $permission Permission key to check
     * @return bool True if user has permission and it's enabled
     */
    public static function hasPermission(string $permission): bool
    {
        $userId = self::getId();
        if (!$userId) {
            return false;
        }

        try {
            $db = Database::getConnection();

            $stmt = $db->prepare(
                "SELECT setting_value FROM user_settings WHERE user_id = ? AND setting_key = ?"
            );
            $stmt->execute([$userId, $permission]);
            $result = $stmt->fetch();

            // Permission exists and is truthy (1, true, "1", etc.)
            return $result && !empty($result['setting_value']) && $result['setting_value'] !== '0';
        } catch (\Exception $e) {
            error_log("User::hasPermission error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get a specific permission value
     *
     * @param string $permission Permission key
     * @return mixed Permission value or null if not found
     */
    public static function getPermission(string $permission)
    {
        $userId = self::getId();
        if (!$userId) {
            return null;
        }

        try {
            $db = Database::getConnection();

            $stmt = $db->prepare(
                "SELECT setting_value FROM user_settings WHERE user_id = ? AND setting_key = ?"
            );
            $stmt->execute([$userId, $permission]);
            $result = $stmt->fetch();

            return $result ? $result['setting_value'] : null;
        } catch (\Exception $e) {
            error_log("User::getPermission error: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Get all permissions for the current user
     *
     * @return array Associative array of permission => value
     */
    public static function getAllPermissions(): array
    {
        $userId = self::getId();
        if (!$userId) {
            return [];
        }

        try {
            $db = Database::getConnection();

            $stmt = $db->prepare(
                "SELECT setting_key, setting_value FROM user_settings WHERE user_id = ?"
            );
            $stmt->execute([$userId]);
            $results = $stmt->fetchAll();

            $permissions = [];
            foreach ($results as $row) {
                $permissions[$row['setting_key']] = $row['setting_value'];
            }

            return $permissions;
        } catch (\Exception $e) {
            error_log("User::getAllPermissions error: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Set a permission for the current user
     *
     * @param string $permission Permission key
     * @param mixed $value Permission value
     * @return bool Success status
     */
    public static function setPermission(string $permission, $value): bool
    {
        $userId = self::getId();
        if (!$userId) {
            return false;
        }

        try {
            $db = Database::getConnection();

            $stmt = $db->prepare(
                "INSERT INTO user_settings (user_id, setting_key, setting_value, updated_by)
                 VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                 setting_value = VALUES(setting_value),
                 updated_by = VALUES(updated_by),
                 updated_on = CURRENT_TIMESTAMP"
            );

            return $stmt->execute([$userId, $permission, $value, $userId]);
        } catch (\Exception $e) {
            error_log("User::setPermission error: " . $e->getMessage());
            return false;
        }
    }
}
