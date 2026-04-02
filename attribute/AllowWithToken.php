<?php

namespace Zero\Core\Attribute;

use \Attribute;
use \Zero\Core\Console;
use \Zero\Core\HTTPError;
use \Zero\Core\Database;

/**
 * AllowWithToken Attribute
 *
 * Authenticates API requests via Bearer token.
 * If a valid Authorization: Bearer zk_... header is present,
 * populates $_SESSION with user data so downstream attributes
 * (RequireLogin, RequireAuthLevel, etc.) work transparently.
 *
 * If no Authorization header is present, passes through silently
 * to allow normal session-based authentication.
 *
 * Usage — must be declared BEFORE RequireLogin:
 *   #[AllowWithToken]
 *   #[RequireLogin]
 *   public function myEndpoint() { ... }
 */
#[Attribute]
class AllowWithToken {

    public $approved;

    public function __construct() {}

    /**
     * Check for Bearer token and authenticate if present
     *
     * @return bool Returns true to continue, throws HTTPError to halt
     */
    public function handler() {
        // Get the Authorization header
        $authHeader = $_SERVER['HTTP_AUTHORIZATION']
                   ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
                   ?? null;

        // No header present — pass through for normal session auth
        if (!$authHeader) {
            return $this->approved = true;
        }

        // Must be Bearer format
        if (!preg_match('/^Bearer\s+(.+)$/i', $authHeader, $matches)) {
            Console::warn("AllowWithToken: malformed Authorization header");
            throw new HTTPError(401, "Invalid authorization header");
        }

        $token = $matches[1];

        // Must be a zk_ prefixed token
        if (!str_starts_with($token, 'zk_')) {
            Console::warn("AllowWithToken: token missing zk_ prefix");
            throw new HTTPError(401, "Invalid API token format");
        }

        // Look up the token hash
        $hash = hash('sha256', $token);

        try {
            $db = Database::getConnection();

            $stmt = $db->prepare(
                "SELECT id, user_id, expires_at FROM api_tokens WHERE token_hash = ?"
            );
            $stmt->execute([$hash]);
            $tokenRow = $stmt->fetch();

            if (!$tokenRow) {
                Console::warn("AllowWithToken: token not found");
                throw new HTTPError(401, "Invalid API token");
            }

            // Check expiration
            if ($tokenRow['expires_at'] !== null && strtotime($tokenRow['expires_at']) < time()) {
                Console::warn("AllowWithToken: token expired");
                throw new HTTPError(401, "API token expired");
            }

            // Update last_used_at
            $update = $db->prepare("UPDATE api_tokens SET last_used_at = NOW() WHERE id = ?");
            $update->execute([$tokenRow['id']]);

            // Load the user
            $userStmt = $db->prepare("SELECT * FROM user_view WHERE id = ?");
            $userStmt->execute([$tokenRow['user_id']]);
            $user = $userStmt->fetch();

            if (!$user) {
                Console::warn("AllowWithToken: user not found for token");
                throw new HTTPError(401, "Token owner not found");
            }

            // Populate session — same keys as Auth::complete()
            $_SESSION['user'] = [
                'full_name' => $user['name'],
                'email'     => $user['email'],
                'verified'  => $user['verified'],
                'pic'       => $user['pic'],
                'id'        => $user['id'],
            ];
            $_SESSION['user_id']  = $user['id'];
            $_SESSION['name']     = $user['name'];
            $_SESSION['email']    = $user['email'];
            $_SESSION['verified'] = $user['verified'];
            $_SESSION['pic']      = $user['pic'];

            // Load user settings/permissions
            $settingsStmt = $db->prepare(
                "SELECT setting_key, setting_value FROM user_settings WHERE user_id = ?"
            );
            $settingsStmt->execute([$user['id']]);
            $settings = $settingsStmt->fetchAll();

            $_SESSION['user_settings'] = [];
            foreach ($settings as $setting) {
                $_SESSION['user_settings'][$setting['setting_key']] = $setting['setting_value'];
            }
            $_SESSION['auth_level'] = $_SESSION['user_settings']['auth.level'] ?? 0;

            // Flag so downstream code can distinguish token-based auth
            $_SESSION['_api_token_auth'] = true;

            Console::info("AllowWithToken: authenticated user {$user['id']} via API token");

        } catch (\Exception $e) {
            error_log("AllowWithToken error: " . $e->getMessage());
            throw new HTTPError(500, "Authentication error");
        }

        return $this->approved = true;
    }
}
