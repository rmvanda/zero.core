<?php
namespace Zero\Core\Attribute;

use \Attribute;
use \Zero\Core\Error;
use \Zero\Core\Console;

#[Attribute]
class RequiredParams {

    private $params;

    /**
     * @param array|string $params Required parameter names (e.g., ['text', 'category'] or 'text')
     */
    public function __construct($params) {
        // Convert single param to array
        $this->params = is_array($params) ? $params : [$params];
    }

    public function handler() {
        $missing = [];

        Console::debug("RequiredParams checking for: " . implode(', ', $this->params));

        foreach ($this->params as $param) {
            // Check both POST and GET
            if (empty($_POST[$param]) && empty($_GET[$param])) {
                $missing[] = $param;
                Console::debug("  - Missing: {$param}");
            } else {
                $value = $_POST[$param] ?? $_GET[$param] ?? '';
                Console::debug("  - Found: {$param} = " . (strlen($value) > 50 ? substr($value, 0, 50) . '...' : $value));
            }
        }

        if (!empty($missing)) {
            $missingList = implode(', ', $missing);
            Console::error("RequiredParams attribute blocked request - Missing: {$missingList}");
            Console::debug("POST params available: " . implode(', ', array_keys($_POST)));
            Console::debug("GET params available: " . implode(', ', array_keys($_GET)));
            new Error(400, "Missing required parameter(s): $missingList");
        }

        Console::debug("RequiredParams passed - all required params present");
        return true;
    }
}
