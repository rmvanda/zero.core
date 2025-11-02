<?php

namespace Zero\Core\Attribute;

use \Attribute;
use \Zero\Core\Console;
use \Zero\Core\Error;

#[Attribute]
class RequirePermission {

    public $approved;
    public $permissions;

    /**
     * RequirePermission constructor
     *
     * @param string|array $permissions Single permission string or array of permissions
     */
    public function __construct(string|array $permissions) {
        session_start();
        $this->permissions = is_array($permissions) ? $permissions : [$permissions];
    }

    /**
     * Check if user has required permissions
     *
     * @return bool|Error Returns true if approved, Error object if denied
     */
    public function handler() {
        // Check if user is logged in
        if (session_status() == PHP_SESSION_NONE || !isset($_SESSION['user_id'])) {
            Console::warn("RequirePermission attribute blocked request: user not logged in");
            return new Error(ERROR_CODE_403);
        }

        $userId = $_SESSION['user_id'];

        // Check each required permission
        foreach ($this->permissions as $permission) {
            if (!$this->hasPermission($userId, $permission)) {
                Console::warn("RequirePermission attribute blocked request: user {$userId} missing permission '{$permission}'");
                return new Error(ERROR_CODE_403);
            }
        }

        Console::debug("RequirePermission attribute passed: user {$userId} has all required permissions");
        return $this->approved = true;
    }

    /**
     * Check if user has a specific permission
     *
     * @param int $userId
     * @param string $permission
     * @return bool
     */
    private function hasPermission(int $userId, string $permission): bool {
        // Check session first (fastest)
        if (isset($_SESSION['user_settings'][$permission])) {
            $value = $_SESSION['user_settings'][$permission];
            return !empty($value) && $value !== '0';
        }

        // Fall back to database query
        try {
            $db = \Zero\Core\Database::getConnection();

            $stmt = $db->prepare(
                "SELECT setting_value FROM user_settings WHERE user_id = ? AND setting_key = ?"
            );
            $stmt->execute([$userId, $permission]);
            $result = $stmt->fetch();

            // Permission exists and is truthy (1, true, "1", etc.)
            return $result && !empty($result['setting_value']) && $result['setting_value'] !== '0';
        } catch (\Exception $e) {
            Console::error("RequirePermission error checking permission '{$permission}': " . $e->getMessage());
            return false;
        }
    }
}
