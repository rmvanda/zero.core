<?php

namespace Zero\Core\Attribute;

use \Attribute;
use \Zero\Core\Console;

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
        $this->permissions = is_array($permissions) ? $permissions : [$permissions];
    }

    /**
     * Check if user has required permissions
     *
     * @return bool Returns true if approved, redirects if denied
     */
    public function handler() {
        // RequirePermission implicitly requires login — a permission check is
        // meaningless without an authenticated user. Delegate to RequireLogin
        // so the login-redirect behavior has a single source of truth.
        // If the user is anonymous, RequireLogin::handler() redirects and exits
        // before we reach the permission check below.
        (new RequireLogin())->handler();

        $userId = $_SESSION['user_id'];

        // Check each required permission
        foreach ($this->permissions as $permission) {
            if (!$this->hasPermission($userId, $permission)) {
                Console::warn("RequirePermission attribute blocked request: user {$userId} missing permission '{$permission}'");
                // Redirect to access request page with the missing permission
                $this->redirectToAccessRequest($permission);
            }
        }

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
            return $this->isGranted($_SESSION['user_settings'][$permission]);
        }

        // Fall back to database query
        try {
            $db = \Zero\Core\Database::getConnection();

            $stmt = $db->prepare(
                "SELECT setting_value FROM user_settings WHERE user_id = ? AND setting_key = ?"
            );
            $stmt->execute([$userId, $permission]);
            $result = $stmt->fetch();

            return $result && $this->isGranted($result['setting_value']);
        } catch (\Exception $e) {
            Console::error("RequirePermission error checking permission '{$permission}': " . $e->getMessage());
            return false;
        }
    }

    /**
     * Strict whitelist for "permission granted" values.
     *
     * A permission row counts as granted only when the stored value is
     * literally '1', 1, or true. Anything else — '0', '', 'false', 'off',
     * whitespace, arbitrary strings — denies. This avoids foot-guns where a
     * permission accidentally stored as the string 'false' would have been
     * treated as truthy under a loose check.
     *
     * @param mixed $value
     * @return bool
     */
    private function isGranted($value): bool {
        return in_array($value, ['1', 1, true], true);
    }
}
