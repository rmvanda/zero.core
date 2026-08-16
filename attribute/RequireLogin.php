<?php

namespace Zero\Core\Attribute;

use \Attribute;
use \Zero\Core\Console;
use \Zero\Core\User;

#[Attribute]
class RequireLogin {

    public $approved;

    /**
     * Redirect to login page
     *
     * @return void
     */
    private function redirectToLogin(): void {
        // Store the current path so we can redirect back after login
        $_SESSION['return_url'] = $_SERVER['REQUEST_URI'] ?? '/';

        $url = '/user/login?r=y';
        header('Location: ' . $url);
        exit;
    }

    /**
     * RequireLogin constructor
     */
    public function __construct() {
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
