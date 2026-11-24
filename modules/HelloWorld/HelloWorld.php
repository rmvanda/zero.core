<?php

namespace Zero\Core\Module;

use \Zero\Core\Attribute\{AllowedMethods, RequireAuthLevel, RequirePermission};

class HelloWorld extends \Zero\Core\Module
{
    /**
     * Index endpoint - shows links and descriptions of other endpoints
     * Only responds to GET requests
     */
    #[AllowedMethods('GET')]
    public function index()
    {
        $this->respond($this->viewPath . "index.php");
    }

    /**
     * Say hello endpoint
     * - No args: "hello world!"
     * - Single arg: "Hello, {arg}!"
     * - Multiple args: "Hello, {arg1, arg2, and arg3}!"
     * Responds correctly to Accept: application/json header
     */
    public function sayHello(...$names)
    {
        $this->data = [
            'greeting' => $this->buildGreeting($names)
        ];
        $this->respond($this->viewPath . "sayHello.php");
    }

    /**
     * Secure hello endpoint - requires authentication
     * Same behavior as sayHello but for authenticated users only
     */
    #[RequireAuthLevel(1)]
    public function secureHello(...$names)
    {
        $this->data = [
            'greeting' => $this->buildGreeting($names)
        ];
        $this->respond($this->viewPath . "secureHello.php");
    }

    /**
     * Permitted hello endpoint - requires authentication and "allow.hello.world" permission
     * Same behavior as sayHello but with permission check
     */
    #[RequirePermission('allow.hello.world')]
    public function permittedHello(...$names)
    {
        $this->data = [
            'greeting' => $this->buildGreeting($names)
        ];
        $this->respond($this->viewPath . "permittedHello.php");
    }

    /**
     * Build the greeting string based on arguments
     *
     * @param array $names Array of name strings
     * @return string The formatted greeting
     */
    private function buildGreeting(array $names): string
    {
        // No arguments - default greeting
        if (empty($names)) {
            return "hello world!";
        }

        // Sanitize all inputs
        $names = array_map(function($name) {
            return htmlspecialchars(trim($name), ENT_QUOTES, 'UTF-8');
        }, $names);

        // Filter out empty strings
        $names = array_filter($names, fn($name) => $name !== '');

        if (empty($names)) {
            return "hello world!";
        }

        $count = count($names);

        if ($count === 1) {
            // Single name: "Hello, Name!"
            return "Hello, {$names[0]}!";
        }

        if ($count === 2) {
            // Two names: "Hello, Name1 and Name2!"
            return "Hello, {$names[0]} and {$names[1]}!";
        }

        // Three or more: "Hello, Name1, Name2, and Name3!"
        $lastIndex = $count - 1;
        $lastItem = array_pop($names);
        $list = implode(', ', $names);

        return "Hello, {$list}, and {$lastItem}!";
    }
}
