<?php

namespace Zero\Core\Attribute;

use \Attribute;
use \Zero\Core\Console;
use \Zero\Core\HTTPError;
use \Zero\Core\Request;

/**
 * RequirePermission Attribute
 *
 * THE authorization gate, one layer above RequireLogin. An endpoint that needs
 * a named grant carries this attribute; it does not re-implement the check in
 * its own body.
 *
 * Usage:
 *   #[RequirePermission('allow.receipt.view')]                  // one key
 *   #[RequirePermission(['allow.admin', 'allow.aws.bill.read'])] // ALL of them
 *
 * Declaring #[RequireLogin] alongside is redundant but harmless: handler()
 * opens by delegating to RequireLogin::handler(), because a permission check
 * is meaningless without an authenticated user. That delegation is also what
 * keeps the login predicate single — one isLoggedIn(), three attributes. See
 * core/attribute/AUTH_GATE_CONSISTENCY.md.
 *
 * Class level gates every endpoint; method level gates one. Unlike every other
 * attribute in this directory, a method-level RequirePermission ADDS to the
 * class-level one rather than replacing it — the effective requirement is the
 * union, and both declarations run. Application::isAdditive() names this class
 * as the sole exception, with the reasoning: a permission is neither a
 * destination nor a constraint, so two of them are a longer list rather than a
 * contradiction. Under the old replace semantics, modules/Receipts declaring
 * 'allow.receipt.view' on the class and 'allow.receipt.edit' on ten methods
 * meant those methods required edit ALONE. There is therefore no way to *drop*
 * or *narrow* the gate for a single endpoint, so a class with any deliberately
 * public endpoint must gate per-method rather than at class level.
 *
 * Grants are read through to the database on every request —
 * User::hasPermission() -> User::current()->can() -> User::settings(), memoized
 * per instance for the life of the request and nowhere else. There is no
 * session cache; there used to be a $_SESSION['user_settings'] one, and it made
 * a revoked permission survive until logout. A value counts as granted only if
 * it is strictly '1', 1 or true, and any lookup failure denies.
 *
 * NOTE for classes using __call(): Application resolves attributes against the
 * URL endpoint name, and a __call-dispatched endpoint has no ReflectionMethod
 * of its own, so it can carry no attributes itself. checkAttributes() reads the
 * attributes on __call() instead, and since this attribute is additive anyway,
 * nothing there can re-open what a class-level declaration closed. A class whose
 * __call() serves anonymous traffic must therefore not carry a class-level
 * RequirePermission; gate each real method instead.
 */
#[Attribute]
class RequirePermission {

    /** True once handler() has passed. Never read by the framework; here if a caller wants it. */
    public ?bool $approved = null;

    /** Every key the request must hold. A single string constructor argument is wrapped into one. */
    public array $permissions;

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
     * @return bool Always true. A denial never returns: deny() redirects and
     *              exits, or throws, and the delegated RequireLogin does the
     *              same for an anonymous caller.
     */
    public function handler(): bool {
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
