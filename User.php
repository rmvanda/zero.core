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
        return $_SESSION['user_id'] ?? $_SESSION['user']['id'] ?? null;
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
     * Build the login session. THE single writer of the session contract
     * documented in modules/Auth/CLAUDE.md.
     *
     * Three callers: Auth::complete() (OAuth), the magic-link token consumer,
     * and core/attribute/AllowWithToken.php (API tokens). Before this existed,
     * complete() and AllowWithToken each built the session by hand and had
     * already drifted apart — AllowWithToken set neither created_at nor
     * login_provider and never rotated the session id.
     *
     * @param \Zero\Model\User $user     The account being signed in.
     * @param array|null       $identity Normalized provider identity, when the
     *                                   caller has one richer than the stored
     *                                   row (OAuth). Null builds it from the row.
     */
    public static function establish(\Zero\Model\User $user, ?array $identity = null): void
    {
        // Rotate at the privilege boundary. Application::__construct() only
        // rotates on a timer, which leaves a session-fixation window at exactly
        // the moment it matters. Guarded because CLI/test contexts have no session.
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }

        $_SESSION['user'] = $identity ?? [
            'full_name' => $user->name,
            'email'     => $user->email,
            'verified'  => $user->verified,
            'pic'       => $user->pic,
            'id'        => $user->id,
        ];

        $_SESSION['user_id']  = $user->id;
        $_SESSION['name']     = $user->name;
        $_SESSION['email']    = $user->email;
        $_SESSION['verified'] = $user->verified;
        $_SESSION['pic']      = $user->pic;

        // Permissions/settings. A failure here must not break a sign-in.
        try {
            $stmt = Database::getConnection()->prepare(
                "SELECT setting_key, setting_value FROM user_settings WHERE user_id = ?"
            );
            $stmt->execute([$user->id]);

            $_SESSION['user_settings'] = [];
            foreach ($stmt->fetchAll() as $setting) {
                $_SESSION['user_settings'][$setting['setting_key']] = $setting['setting_value'];
            }
            $_SESSION['auth_level'] = (int) ($_SESSION['user_settings']['auth.level'] ?? 0);
        } catch (\Throwable $e) {
            // \Throwable, not \Exception: Database::getConnection() throws \Error
            // on a misconfiguration, which \Exception does not catch — and the
            // comment above says a failure here must not break a sign-in.
            error_log("User::establish failed to load settings: " . $e->getMessage());
            $_SESSION['user_settings'] = [];
            $_SESSION['auth_level']    = 0;
        }

        $_SESSION['created_at'] = time();

        // Non-expiring "probably a returning user" hint. NOT authentication —
        // it holds a plaintext id and must never be trusted as proof of identity.
        setcookie('unisolu_user_id', (string) $user->id, 2147483647, '/', '', true, true);
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
