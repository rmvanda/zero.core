<?php

namespace Zero\Core\Attribute;

use \Attribute;
use \Zero\Core\Console;
use \Zero\Core\Error;

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
     * @return bool|Error Returns true if approved, Error object if denied
     */
    public function handler() {
        // Check if user is logged in
        if (session_status() == PHP_SESSION_NONE || !isset($_SESSION['user_id'])) {
            Console::warn("RequireLogin attribute blocked request: user not logged in");
            $this->redirectToLogin();
        }

        Console::debug("RequireLogin attribute passed: user {$_SESSION['user_id']} is logged in");
        return $this->approved = true;
    }
}
