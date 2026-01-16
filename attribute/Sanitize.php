<?php
namespace Zero\Core\Attribute;

use \Attribute;
use \Zero\Core\Console;

/**
 * Sanitize Attribute - Sanitizes POST/GET parameters before method execution
 *
 * Usage examples:
 *   #[Sanitize(['title', 'content'])]                    // Default: strip special chars
 *   #[Sanitize(['email' => FILTER_SANITIZE_EMAIL])]      // Use filter_var filter
 *   #[Sanitize(['slug' => '/^[a-z0-9-]+$/'])]            // Use regex extraction
 *   #[Sanitize(['user_id'])]                             // Auto-detected as numeric (ends in 'id')
 *
 * Behavior:
 * - Numeric array keys: apply default sanitization (strip special symbols)
 * - String keys with integer values: treat as filter_var filter constant
 * - String keys with string values: treat as regex pattern for extraction
 * - Keys ending in 'id' (case-insensitive): auto-sanitize as integer
 */
#[Attribute]
class Sanitize {

    private array $params;

    /** Default filter removes special characters while preserving basic text */
    private const DEFAULT_PATTERN = '/[^\p{L}\p{N}\s\-_.,!?@#$%&*()+=\[\]{}|\\\\:;"\'<>\/~`]/u';

    /** Filename-safe pattern - only letters, numbers, spaces, hyphens, underscores, dots */
    private const FILENAME_PATTERN = '/[^\p{L}\p{N}\s\-_.]/u';

    /** Built-in presets for common sanitization needs */
    private const PRESETS = [
        'filename' => self::FILENAME_PATTERN,
        'slug' => '/[^a-z0-9\-]/',
        'alphanumeric' => '/[^\p{L}\p{N}]/u',
        'alpha' => '/[^\p{L}]/u',
        'numeric' => '/[^0-9]/',
    ];

    /**
     * @param array $params Parameters to sanitize
     *   - Simple array: ['title', 'content'] - default sanitization
     *   - Associative: ['email' => FILTER_SANITIZE_EMAIL] - specific filter
     *   - Regex: ['slug' => '/^[a-z0-9-]+$/'] - regex extraction
     */
    public function __construct(array $params) {
        $this->params = $params;
    }

    public function handler(): bool {
        Console::debug("Sanitize processing " . count($this->params) . " parameter(s)");

        foreach ($this->params as $key => $value) {
            // Determine param name and sanitization method
            if (is_int($key)) {
                // Simple array item: ['title', 'content']
                $paramName = $value;
                $this->sanitizeParam($paramName, null);
            } else {
                // Associative: ['email' => FILTER_*] or ['slug' => '/regex/']
                $paramName = $key;
                $this->sanitizeParam($paramName, $value);
            }
        }

        return true;
    }

    /**
     * Sanitize a single parameter in both POST and GET
     */
    private function sanitizeParam(string $paramName, mixed $method): void {
        // Check POST
        if (isset($_POST[$paramName])) {
            $original = $_POST[$paramName];
            $_POST[$paramName] = $this->applySanitization($paramName, $original, $method);
            $this->logChange($paramName, $original, $_POST[$paramName], 'POST');
        }

        // Check GET
        if (isset($_GET[$paramName])) {
            $original = $_GET[$paramName];
            $_GET[$paramName] = $this->applySanitization($paramName, $original, $method);
            $this->logChange($paramName, $original, $_GET[$paramName], 'GET');
        }
    }

    /**
     * Apply the appropriate sanitization method
     */
    private function applySanitization(string $paramName, mixed $value, mixed $method): mixed {
        // Handle arrays recursively
        if (is_array($value)) {
            return array_map(fn($v) => $this->applySanitization($paramName, $v, $method), $value);
        }

        // Auto-detect ID fields (case-insensitive check for 'id' suffix)
        if ($method === null && preg_match('/id$/i', $paramName)) {
            Console::debug("  Auto-detected '{$paramName}' as numeric ID");
            return $this->sanitizeAsInt($value);
        }

        // No method specified - use default sanitization
        if ($method === null) {
            return $this->sanitizeDefault($value);
        }

        // Method is a preset name (filename, slug, alphanumeric, etc.)
        if (is_string($method) && isset(self::PRESETS[$method])) {
            Console::debug("  Using preset '{$method}' for '{$paramName}'");
            return $this->sanitizeWithPattern($value, self::PRESETS[$method]);
        }

        // Method is a filter_var constant (integer)
        if ($this->isValidFilterVar($method)) {
            return filter_var($value, $method);
        }

        // Method is a regex pattern (string starting/ending with delimiter)
        if (is_string($method) && $this->isRegexPattern($method)) {
            return $this->sanitizeWithPattern($value, $method);
        }

        // Fallback to default
        Console::warn("Sanitize: Unknown method for '{$paramName}', using default");
        return $this->sanitizeDefault($value);
    }

    /**
     * Default sanitization - strip special symbols while preserving readable text
     */
    private function sanitizeDefault(string $value): string {
        // Remove control characters and unusual unicode
        $sanitized = preg_replace(self::DEFAULT_PATTERN, '', $value);
        // Normalize whitespace
        $sanitized = preg_replace('/\s+/', ' ', $sanitized);
        return trim($sanitized);
    }

    /**
     * Sanitize as integer
     */
    private function sanitizeAsInt(mixed $value): int {
        return (int) filter_var($value, FILTER_SANITIZE_NUMBER_INT);
    }

    /**
     * Sanitize using pattern - remove characters matching the pattern
     * Pattern should match characters to REMOVE (negated character class)
     */
    private function sanitizeWithPattern(string $value, string $pattern): string {
        $sanitized = preg_replace($pattern, '', $value);
        // Normalize whitespace and trim
        $sanitized = preg_replace('/\s+/', ' ', $sanitized);
        return trim($sanitized);
    }

    /**
     * Check if value is a valid filter_var filter constant
     */
    private function isValidFilterVar(mixed $value): bool {
        if (!is_int($value)) {
            return false;
        }
        // filter_list() returns names, we need to check if our int is a valid filter
        // Valid sanitize filters are in the 500+ range
        return $value >= 500 && $value <= 600;
    }

    /**
     * Check if string looks like a regex pattern
     */
    private function isRegexPattern(string $value): bool {
        if (strlen($value) < 2) {
            return false;
        }
        $delimiter = $value[0];
        // Common regex delimiters
        $validDelimiters = ['/', '#', '~', '@', '!', '%'];
        if (!in_array($delimiter, $validDelimiters)) {
            return false;
        }
        // Check if it ends with the same delimiter (possibly with flags)
        return (bool) preg_match('/^' . preg_quote($delimiter, '/') . '.*' . preg_quote($delimiter, '/') . '[imsxuADU]*$/', $value);
    }

    /**
     * Log when a value is changed by sanitization
     */
    private function logChange(string $param, mixed $original, mixed $sanitized, string $source): void {
        if ($original !== $sanitized) {
            $origStr = is_array($original) ? json_encode($original) : (string) $original;
            $sanitizedStr = is_array($sanitized) ? json_encode($sanitized) : (string) $sanitized;

            // Truncate for logging
            if (strlen($origStr) > 50) $origStr = substr($origStr, 0, 50) . '...';
            if (strlen($sanitizedStr) > 50) $sanitizedStr = substr($sanitizedStr, 0, 50) . '...';

            Console::debug("  Sanitized {$source}['{$param}']: '{$origStr}' -> '{$sanitizedStr}'");
        }
    }
}
