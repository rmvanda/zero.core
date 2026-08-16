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

        // Used only for the log message below. User::currentId() is the same
        // value with the $_SESSION['user']['id'] fallback, so this doesn't
        // raise an undefined-key warning on a legacy session that never got
        // the flat $_SESSION['user_id'] key.
        $userId = \Zero\Core\User::currentId();

        // Check each required permission
        foreach ($this->permissions as $permission) {
            if (!\Zero\Core\User::hasPermission($permission)) {
                Console::warn("RequirePermission attribute blocked request: user {$userId} missing permission '{$permission}'");
                // Redirect to access request page with the missing permission
                $this->redirectToAccessRequest($permission);
            }
        }

        return $this->approved = true;
    }
}
