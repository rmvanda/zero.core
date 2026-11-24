<?php
namespace Zero\Core\Module\Auth\Model;

use Zero\Core\Exception\HTTPError;
use Zero\Core\Console;

/**
 * The registration field definitions, from core/modules/Auth/registration-fields.json.
 *
 * THE single source of truth for both rendering the form and validating the
 * POST. The old implementation's real defect was that those two paths were
 * written separately and drifted — the form was built from a field loop while
 * validation hard-coded four field names. Nothing may read the JSON but this.
 *
 * The file deliberately does NOT live under core/modules/Auth/config/: the module's
 * .gitignore ignores `config/`, and these definitions are not secrets — they must be
 * versioned. An .ini there would be worse still, since
 * Application::defineConstants() define()s every key it finds in that directory.
 */
class RegistrationFields
{
    /** Input types a field may declare. */
    private const TYPES = ['text', 'tel', 'email', 'url', 'number', 'date', 'textarea', 'select', 'checkbox'];

    /** Owned by the users table, always collected or DB-managed — never configurable. */
    private const RESERVED = ['email', 'id', 'verified', 'pic', 'name', 'created_at', 'updated_at',
                              'full_name'];

    /** Test seam: overrides the definition file path. */
    public static ?string $pathOverride = null;

    private static ?array $cache = null;

    /** All field definitions, validated, ordered by `order`. */
    public static function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $path = self::$pathOverride ?? (__DIR__ . '/../registration-fields.json');
        if (!is_file($path)) {
            throw new HTTPError(500, "Registration field definitions not found at $path");
        }

        $defs = json_decode((string) file_get_contents($path), true);
        if (!is_array($defs)) {
            throw new HTTPError(500, 'Registration field definitions are not valid JSON');
        }

        // Fail loudly at the top of the request. Rendering a form that silently
        // drops a misconfigured field is worse than a 500.
        $seen = [];
        foreach ($defs as $i => $def) {
            foreach (['key', 'label', 'type'] as $required) {
                if (!isset($def[$required])) {
                    throw new HTTPError(500, "Registration field #$i is missing '$required'");
                }
            }
            if (!preg_match('/^[a-z][a-z0-9_]{0,63}$/', $def['key'])) {
                throw new HTTPError(500, "Registration field key '{$def['key']}' is not a valid key");
            }
            if (in_array($def['key'], self::RESERVED, true)) {
                throw new HTTPError(500, "Registration field key '{$def['key']}' is reserved");
            }
            if (isset($seen[$def['key']])) {
                throw new HTTPError(500, "Duplicate registration field key '{$def['key']}'");
            }
            if (!in_array($def['type'], self::TYPES, true)) {
                throw new HTTPError(500, "Registration field '{$def['key']}' has unknown type '{$def['type']}'");
            }
            if ($def['type'] === 'select' && empty($def['options'])) {
                throw new HTTPError(500, "Registration field '{$def['key']}' is a select but declares no options");
            }
            if (isset($def['pattern'])) {
                if (@preg_match('/' . $def['pattern'] . '/', '') === false) {
                    throw new HTTPError(500, "Registration field '{$def['key']}' has an invalid pattern");
                }
                // preg_match() is a CONTAINS test and validate() stores the raw
                // submitted value rather than the matched portion, so an
                // unanchored pattern such as [0-9]{3} would accept AND STORE
                // "<script>123</script>" in full. Anchoring is a requirement,
                // not a convention.
                if (!str_starts_with($def['pattern'], '^') || !str_ends_with($def['pattern'], '$')) {
                    throw new HTTPError(500,
                        "Registration field '{$def['key']}' pattern must be anchored with ^ and " . '$');
                }
            }
            $seen[$def['key']] = true;
        }

        usort($defs, fn($a, $b) => ($a['order'] ?? 0) <=> ($b['order'] ?? 0));

        return self::$cache = $defs;
    }

    public static function byKey(string $key): ?array
    {
        foreach (self::all() as $def) {
            if ($def['key'] === $key) {
                return $def;
            }
        }
        return null;
    }

    /**
     * Validate a POST against the definitions.
     *
     * @return array{0: array<string,string>, 1: array<string,string>} [$clean, $errors]
     *         $clean holds only configured, non-empty, valid values.
     */
    public static function validate(array $post): array
    {
        $clean  = [];
        $errors = [];

        foreach (self::all() as $def) {
            $key   = $def['key'];
            $value = is_string($post[$key] ?? null) ? trim($post[$key]) : '';

            if ($value === '') {
                if (!empty($def['required'])) {
                    $errors[$key] = $def['label'] . ' is required.';
                }
                continue;   // optional and empty: store nothing
            }

            if (isset($def['pattern'])) {
                $matched = preg_match('/' . $def['pattern'] . '/', $value);

                if ($matched === false) {
                    // A runtime failure, not a non-match — almost always the
                    // backtrack limit, i.e. a catastrophically backtracking
                    // pattern meeting an input that triggers it. all()'s
                    // load-time check cannot catch this: it tests the pattern
                    // against an empty string, which can never backtrack. Log
                    // it loudly so a bad admin-supplied pattern is visible
                    // rather than silently rejecting every submission.
                    Console::error("Registration field '{$key}' pattern failed at runtime: "
                        . preg_last_error_msg());
                    $errors[$key] = $def['label'] . ' could not be validated.';
                    continue;
                }

                if ($matched !== 1) {
                    $errors[$key] = $def['label'] . ' is not in the expected format.';
                    continue;
                }
            }

            if ($def['type'] === 'select') {
                $allowed = array_column($def['options'] ?? [], 'value');
                if (!in_array($value, $allowed, true)) {
                    $errors[$key] = $def['label'] . ' is not a valid choice.';
                    continue;
                }
            }

            $clean[$key] = $value;
        }

        return [$clean, $errors];
    }

    /** Drop the cache. Tests only. */
    public static function reset(): void
    {
        self::$cache = null;
    }
}
