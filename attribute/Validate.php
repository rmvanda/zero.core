<?php
namespace Zero\Core\Attribute;

use \Attribute;
use \Zero\Core\Console;
use \Zero\Core\Error;

/**
 * Validate Attribute - Validates POST/GET parameters before method execution
 *
 * STATUS: STUB/PROPOSAL - Not yet ready for production use
 *
 * Proposed usage examples:
 *   #[Validate(['email' => 'email'])]                     // Built-in: valid email format
 *   #[Validate(['url' => 'url'])]                         // Built-in: valid URL
 *   #[Validate(['age' => 'int'])]                         // Built-in: valid integer
 *   #[Validate(['age' => ['int', 'min:18', 'max:120']])]  // Multiple rules
 *   #[Validate(['status' => 'in:active,pending,closed'])] // Value must be in list
 *   #[Validate(['title' => 'length:1,255'])]              // String length between 1-255
 *   #[Validate(['slug' => '/^[a-z0-9-]+$/'])]             // Regex match
 *   #[Validate(['password' => ['required', 'length:8,']])]// Required + min 8 chars
 *
 * Proposed built-in validators:
 *   - email: Valid email format
 *   - url: Valid URL format
 *   - int: Valid integer
 *   - float: Valid float/decimal
 *   - bool: Boolean (1, 0, true, false, yes, no)
 *   - alpha: Letters only
 *   - alphanumeric: Letters and numbers only
 *   - in:val1,val2,val3: Value must be in list
 *   - length:min,max: String length (omit max for no upper limit)
 *   - range:min,max: Numeric range
 *   - required: Must not be empty
 *   - /regex/: Custom regex pattern
 *
 * Design considerations:
 *   1. Should validation failure throw Error(400) or return validation messages?
 *   2. Should we support custom validator callbacks?
 *   3. Should validators be chainable? (e.g., 'required|email|length:,255')
 *   4. How to handle conditional validation? (validate X only if Y is present)
 *   5. Should we support field-specific error messages?
 *
 * @see Sanitize for parameter sanitization (runs before validation)
 */
#[Attribute]
class Validate {

    private array $rules;
    private array $errors = [];

    /** Built-in validator types mapped to filter_var constants or custom handlers */
    private const VALIDATORS = [
        'email' => FILTER_VALIDATE_EMAIL,
        'url' => FILTER_VALIDATE_URL,
        'int' => FILTER_VALIDATE_INT,
        'float' => FILTER_VALIDATE_FLOAT,
        'bool' => FILTER_VALIDATE_BOOL,
    ];

    /**
     * @param array $rules Validation rules
     *   - ['field' => 'rule'] for single rule
     *   - ['field' => ['rule1', 'rule2']] for multiple rules
     */
    public function __construct(array $rules) {
        $this->rules = $rules;
    }

    /**
     * STUB: Main handler - validates all parameters
     *
     * TODO: Implement full validation logic
     */
    public function handler(): bool {

        foreach ($this->rules as $param => $rules) {
            $value = $_POST[$param] ?? $_GET[$param] ?? null;
            $ruleList = is_array($rules) ? $rules : [$rules];

            foreach ($ruleList as $rule) {
                if (!$this->validateRule($param, $value, $rule)) {
                    // Validation failed - error already added to $this->errors
                }
            }
        }

        if (!empty($this->errors)) {
            $errorList = implode('; ', $this->errors);
            Console::error("Validation failed: {$errorList}");
            new Error(400, "Validation failed: {$errorList}");
        }

        return true;
    }

    /**
     * STUB: Validate a single rule against a value
     *
     * TODO: Implement all validator types
     */
    private function validateRule(string $param, mixed $value, string $rule): bool {
        // Check for 'required' first
        if ($rule === 'required') {
            if ($value === null || $value === '') {
                $this->errors[] = "{$param} is required";
                return false;
            }
            return true;
        }

        // If value is empty and not required, skip other validations
        if ($value === null || $value === '') {
            return true;
        }

        // Built-in filter_var validators
        if (isset(self::VALIDATORS[$rule])) {
            if (filter_var($value, self::VALIDATORS[$rule]) === false) {
                $this->errors[] = "{$param} must be a valid {$rule}";
                return false;
            }
            return true;
        }

        // TODO: Implement these validators
        // - 'in:val1,val2,val3'
        // - 'length:min,max'
        // - 'range:min,max'
        // - 'alpha'
        // - 'alphanumeric'
        // - '/regex/'

        // Check if rule is a regex pattern
        if ($this->isRegexPattern($rule)) {
            if (!preg_match($rule, $value)) {
                $this->errors[] = "{$param} format is invalid";
                return false;
            }
            return true;
        }

        // Parse parameterized rules like 'length:1,255' or 'in:a,b,c'
        if (str_contains($rule, ':')) {
            [$ruleName, $ruleParams] = explode(':', $rule, 2);
            return $this->validateParameterizedRule($param, $value, $ruleName, $ruleParams);
        }

        Console::warn("Validate: Unknown rule '{$rule}' for '{$param}'");
        return true;
    }

    /**
     * STUB: Handle parameterized rules like 'length:1,255'
     *
     * TODO: Implement fully
     */
    private function validateParameterizedRule(string $param, mixed $value, string $rule, string $params): bool {
        switch ($rule) {
            case 'in':
                $allowed = explode(',', $params);
                if (!in_array($value, $allowed)) {
                    $this->errors[] = "{$param} must be one of: {$params}";
                    return false;
                }
                return true;

            case 'length':
                $parts = explode(',', $params);
                $min = (int) ($parts[0] ?? 0);
                $max = isset($parts[1]) && $parts[1] !== '' ? (int) $parts[1] : null;
                $len = strlen($value);

                if ($len < $min) {
                    $this->errors[] = "{$param} must be at least {$min} characters";
                    return false;
                }
                if ($max !== null && $len > $max) {
                    $this->errors[] = "{$param} must be at most {$max} characters";
                    return false;
                }
                return true;

            case 'range':
                $parts = explode(',', $params);
                $min = isset($parts[0]) && $parts[0] !== '' ? (float) $parts[0] : null;
                $max = isset($parts[1]) && $parts[1] !== '' ? (float) $parts[1] : null;
                $num = (float) $value;

                if ($min !== null && $num < $min) {
                    $this->errors[] = "{$param} must be at least {$min}";
                    return false;
                }
                if ($max !== null && $num > $max) {
                    $this->errors[] = "{$param} must be at most {$max}";
                    return false;
                }
                return true;

            default:
                Console::warn("Validate: Unknown parameterized rule '{$rule}'");
                return true;
        }
    }

    /**
     * Check if string looks like a regex pattern
     */
    private function isRegexPattern(string $value): bool {
        if (strlen($value) < 2) {
            return false;
        }
        $delimiter = $value[0];
        $validDelimiters = ['/', '#', '~', '@', '!', '%'];
        if (!in_array($delimiter, $validDelimiters)) {
            return false;
        }
        return (bool) preg_match('/^' . preg_quote($delimiter, '/') . '.*' . preg_quote($delimiter, '/') . '[imsxuADU]*$/', $value);
    }

    /**
     * Get validation errors (for programmatic access)
     */
    public function getErrors(): array {
        return $this->errors;
    }
}
