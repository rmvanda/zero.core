<?php

namespace Zero\Core\Attribute;

use \Attribute;
use \Zero\Core\Console;
use \Zero\Core\HTTPError;
use \Zero\Core\Request;

#[Attribute]
class RequirePermission {

    public $approved;
    public $permissions;

    /**
     * Deny a request that is missing a permission.
     *
     * JSON callers get a real 403 rather than a 302, for the same reason
     * RequireLogin returns a 401: an XHR follows a redirect transparently and
     * hands its caller the access-request page's HTML, which the caller then
     * fails to parse as JSON — the failure surfaces as a parse error rather
     * than "you do not have access". HTTPError bubbles to Application::run()
     * and renders through the live module, so the 403 comes back in whatever
     * shape that module already speaks.
     *
     * Without this the two halves of handler() disagreed. An ANONYMOUS JSON
     * caller already got a 401, because handler() opens by delegating to
     * RequireLogin — but a logged-in, under-privileged one got the 302. Same
     * request shape, two contracts, depending only on which half denied.
     *
     * Naming the permission in the detail is not a leak: the caller is
     * authenticated, and the redirect URL this replaces carried the same key
     * as ?p=.
     *
     * @param string $permission The permission key being requested
     * @return void Never actually returns: redirects and exits, or throws.
     */
    private function deny(string $permission): void {
        if (Request::$acceptsJSON) {
            throw new HTTPError(403, "Permission required: {$permission}");
        }

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

        // Used only for the log message below. currentId() reads
        // $_SESSION['user_id'] and returns null rather than raising an
        // undefined-key warning, so it is safe here whatever the session holds.
        // (It used to also fall back to $_SESSION['user']['id']; that fallback
        // is gone — it read the PROVIDER's id, not ours. See core/User.php.)
        $userId = \Zero\Core\User::currentId();

        // Check each required permission
        foreach ($this->permissions as $permission) {
            if (!\Zero\Core\User::hasPermission($permission)) {
                Console::warn("RequirePermission attribute blocked request: user {$userId} missing permission '{$permission}'");
                $this->deny($permission);
            }
        }

        return $this->approved = true;
    }
}
