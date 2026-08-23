<?php

namespace Zero\Core\Attribute;

use \Attribute;
use \Zero\Core\Console;
use \Zero\Core\HTTPError;
use \Zero\Core\Request;
use \Zero\Core\User;

/**
 * RequireLogin Attribute
 *
 * THE authentication gate. An endpoint that needs a signed-in user carries this
 * attribute; it does not re-implement the check in its own body.
 *
 * Usage:
 *   #[RequireLogin]                     // anonymous visitors go to /user/login
 *   #[RequireLogin('/notes/welcome')]   // ...or to a page of the module's choosing
 *
 * Declarable at class level (gates every endpoint) or method level (gates one).
 * A method-level RequireLogin REPLACES the class-level one for that endpoint —
 * see Application::checkAttributes() — so a class can be gated wholesale and
 * still send one endpoint to a different landing page. There is no way to
 * *drop* the gate for a single endpoint, so a class with any deliberately
 * public endpoint must gate per-method rather than at class level.
 *
 * NOTE for classes using __call(): Application resolves attributes against the
 * URL endpoint name, and a __call-dispatched endpoint has no ReflectionMethod
 * of its own, so it can carry no attributes itself. checkAttributes() reads the
 * attributes on __call() instead, which lets a class gate everything it
 * dispatches dynamically — but those are ADDITIVE on top of the class-level
 * ones, so they cannot express "class gated, __call deliberately public".
 * A class whose __call() serves anonymous traffic must therefore not carry a
 * class-level RequireLogin; gate each real method instead, as TheButton does.
 */
#[Attribute]
class RequireLogin {

    public $approved;

    /** Where anonymous visitors land when the attribute names nowhere else. */
    private const DEFAULT_LOGIN_URL = '/user/login?r=y';

    /**
     * Deny an anonymous request.
     *
     * JSON callers get a real 401 rather than a 302. An XHR follows a redirect
     * transparently and hands its caller the login page's HTML, which the
     * caller then fails to parse as JSON — the failure surfaces as a confusing
     * parse error rather than "you are not logged in". HTTPError bubbles to
     * Application::run() and renders through the live module, so the 401 comes
     * back in whatever shape that module already speaks.
     *
     * @return void Never actually returns: redirects and exits, or throws.
     */
    private function redirectToLogin(): void {
        if (Request::$acceptsJSON) {
            throw new HTTPError(401, "Authentication required");
        }

        // Store the current path so we can redirect back after login.
        //
        // REQUEST_URI is attacker-controlled, and Auth::complete() /
        // Auth::token() put this value straight into a Location header, so it
        // is guarded on the way IN as well as on the way out. Nothing today
        // reaches here with a hostile URI — Request trims leading slashes
        // before routing, so "//evil.example/x" resolves to a module that does
        // not exist and no gate ever runs — but that is an accident of the
        // router, not a property of this code. An unsafe value is dropped
        // rather than rewritten: both consumers already fall back to '/'.
        $requestUri = $_SERVER['REQUEST_URI'] ?? '/';
        if (Request::isSiteRelative($requestUri)) {
            $_SESSION['return_url'] = $requestUri;
        } else {
            Console::warn("RequireLogin refusing to store non-relative return_url '{$requestUri}'");
        }

        $url = self::DEFAULT_LOGIN_URL;
        if ($this->redirect !== null) {
            // Site-relative only. The value comes from source, not from the
            // request, so this is a typo guard rather than a security boundary
            // — but a stray "https://..." would still be an open redirect, and
            // silently falling back to the login page is the harmless failure.
            // Same predicate as the return_url above: a plain str_starts_with
            // '/' admitted "//evil.example/x", which is the very thing the
            // guard is for.
            if (Request::isSiteRelative($this->redirect)) {
                $url = $this->redirect;
            } else {
                Console::warn("RequireLogin ignoring non-relative redirect '{$this->redirect}'");
            }
        }

        header('Location: ' . $url);
        exit;
    }

    /**
     * RequireLogin constructor
     *
     * @param string|null $redirect Site-relative path to send anonymous
     *                              visitors to, overriding the default login
     *                              page. Useful where a module has a landing
     *                              page that explains what the user is signing
     *                              in for. Null keeps the default.
     */
    public function __construct(public ?string $redirect = null) {
        // No initialization needed
    }

    /**
     * Check if user is logged in
     *
     * Used to test only `isset($_SESSION['user_id'])`, which is weaker than
     * every caller of this attribute assumed and disagreed with
     * `User::isLoggedIn()`: a session holding a `user_id` but not verified —
     * a shape `AllowWithToken::handler()` can produce, since it calls
     * `User::establish()` without checking `verified` — passed this gate and
     * failed `isLoggedIn()`. Gating on `isLoggedIn()` here makes the two
     * agree, since `#[RequireLogin]` is the gate and `isLoggedIn()` is meant
     * only for branches whose behaviour differs by login state, not for
     * gating — a single predicate instead of two that can drift apart again.
     *
     * @return bool Returns true if approved, redirects if denied
     */
    public function handler() {
        // Check if user is logged in
        // TODO: make this configurable on whether verified should be a gate or not
        if (session_status() == PHP_SESSION_NONE || !User::isLoggedIn()) {
            Console::warn("RequireLogin attribute blocked request: user not logged in");
            $this->redirectToLogin();
        }

        return $this->approved = true;
    }
}
