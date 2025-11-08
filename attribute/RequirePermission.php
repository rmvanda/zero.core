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
     * Generate the access request HTML form
     *
     * @return string HTML form for requesting access
     */
    private function getRequestAccessHTML(): string {
        return <<<HTML
    <div class="content-wrapper mx-auto surface-medium p-2 border-radius-8 mt-3">
        <div class="text-center mb-2">
            <span class="material-symbols-outlined icon-xl" style="color: rgba(255, 200, 0, 0.8);">lock</span>
            <h2 class="mt-1">Access Required</h2>
            <p>You don't have permission to access this resource. You can request access from the administrator.</p>
        </div>
        <form id="accessRequestForm" method="POST" action="/access-request/submit">
            <input type="hidden" name="requested_url" id="requested_url">
            <div class="form-group">
                <label for="reason">Reason for Access Request:</label>
                <textarea name="reason" id="reason" rows="6" placeholder="Please explain why you need access to this resource..." required></textarea>
            </div>
            <div class="text-center mt-2">
                <button type="submit" class="btn-primary">
                    <span class="material-symbols-outlined" style="vertical-align: middle; font-size: 24px; margin-right: 8px;">send</span>
                    Request Access
                </button>
            </div>
        </form>
    </div>
    <script>
        // Populate requested URL from current location
        document.getElementById('requested_url').value = window.location.pathname;
    </script>
HTML;
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
            return new Error(403, "You require special permissions to view this page.", $this->getRequestAccessHTML());
        }

        $userId = $_SESSION['user_id'];

        // Check each required permission
        foreach ($this->permissions as $permission) {
            if (!$this->hasPermission($userId, $permission)) {
                Console::warn("RequirePermission attribute blocked request: user {$userId} missing permission '{$permission}'");
                return new Error(403, "You require specific persmissions to view this page.", $this->getRequestAccessHTML());
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
