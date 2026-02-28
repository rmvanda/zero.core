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

        foreach ($this->params as $param) {
            // Check both POST and GET
            if (empty($_POST[$param]) && empty($_GET[$param])) {
                $missing[] = $param;
            }
        }

        if (!empty($missing)) {
            $missingList = implode(', ', $missing);
            Console::error("RequiredParams attribute blocked request - Missing: {$missingList}");
            new Error(400, "Missing required parameter(s): $missingList");
        }

        return true;
    }
}
