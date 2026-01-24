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
     * Redirect to access request page with permission parameter
     *
     * @param string $permission The permission key being requested
     * @return void
     */
    private function redirectToAccessRequest(string $permission): void {
        $url = '/access-request/permission?p=' . urlencode($permission);
        header('Location: ' . $url);
        exit;
    }

    /**
     * RequirePermission constructor
     *
     * @param string|array $permissions Single permission string or array of permissions
     */
    public function __construct(string|array $permissions) {
        @session_start(); // TODO - consider not starting the session here. 
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
            // Redirect to access request page with first permission
            $this->redirectToAccessRequest($this->permissions[0]);
        }

        $userId = $_SESSION['user_id'];

        // Check each required permission
        foreach ($this->permissions as $permission) {
            if (!$this->hasPermission($userId, $permission)) {
                Console::warn("RequirePermission attribute blocked request: user {$userId} missing permission '{$permission}'");
                // Redirect to access request page with the missing permission
                $this->redirectToAccessRequest($permission);
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
