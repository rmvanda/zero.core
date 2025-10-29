<?php
namespace Zero\Core\Attribute;

use \Attribute;
use \Zero\Core\Request;
use \Zero\Core\Error;

#[Attribute]
class AllowedMethods {

    private $methods;

    /**
     * @param array|string $methods Allowed HTTP methods (e.g., ['GET', 'POST'] or 'POST')
     */
    public function __construct($methods) {
        // Convert single method to array
        $this->methods = is_array($methods) ? $methods : [$methods];

        // Normalize to uppercase
        $this->methods = array_map('strtoupper', $this->methods);
    }

    public function handler() {
        $currentMethod = strtoupper(Request::$method);

        if (!in_array($currentMethod, $this->methods)) {
            new Error(405, 'Method not allowed. Allowed: ' . implode(', ', $this->methods));
        }

        return true;
    }
}
