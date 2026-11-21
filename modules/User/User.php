<?php

namespace Zero\Module;

use \Zero\Core\Attribute\RequireLogin;
use \Zero\Core\Attribute\RequireAuthLevel;
use \Zero\Core\Attribute\{AllowedMethods, AllowWithToken, RequiredParams, Sanitize};

class User extends \Zero\Core\Module {

    public function __construct(){
        // This module can be used by other modules.
        // Therefore, let this module pretend to be part of those other modules
        //
        // The referring URL is the handoff, so it names the frame to borrow.
        // Runs BEFORE parent::__construct() because defineBasePaths() leaves an
        // already-set path alone. With no usable Referer this changes nothing
        // and the module's own frame resolution stands.
        $this->adoptFrameFrom($_SERVER['HTTP_REFERER'] ?? null);

        parent::__construct();
    }

    /**
     * Display user profile
     * GET /user/profile
     */
    #[RequireLogin]
    #[AllowedMethods('GET')]
    public function profile() {
        $this->user = $_SESSION['user'] ?? [];
        $this->userId = \Zero\Core\Session::userId();
        // The profile view needs the user's capabilities, the whole settings
        // map and the numeric auth level — all read through the current user
        // instance rather than the session cache that used to carry them.
        $currentUser = \Zero\Core\Session::user();
        $this->capabilities = $currentUser?->capabilities() ?? [];
        $this->settings = $currentUser?->settings() ?? [];
        $this->authLevel = $currentUser?->authLevel() ?? 0;
        $this->sessionCreatedAt = $_SESSION['created_at'] ?? null;

        // OAuth providers the user can manage: everything currently available
        // (admin-enabled AND configured) plus any they've already connected —
        // including a since-disabled one, so it can still be disconnected.
        // Guarded so a registry/DB hiccup renders an empty section, not a 500.
        try {
            $connections = \Zero\Module\Auth\OAuth\Token::connectionsFor((int) ($this->userId ?? 0));
            $bySlug = [];
            foreach (\Zero\Module\Auth\OAuth\Provider::available() as $p) {
                $bySlug[$p['slug']] = $p;
            }
            foreach (array_keys($connections) as $slug) {
                if (!isset($bySlug[$slug]) && ($p = \Zero\Module\Auth\OAuth\Provider::bySlug($slug))) {
                    $bySlug[$slug] = $p;
                }
            }
            $providers = array_values($bySlug);
            usort($providers, fn($a, $b) => ($a['sort_order'] ?? 0) <=> ($b['sort_order'] ?? 0));
        } catch (\Throwable $e) {
            \Zero\Core\Console::error("Profile OAuth providers load failed: " . $e->getMessage());
            $providers = [];
            $connections = [];
        }

        $this->oauthProviders = $providers;
        $this->oauthConnections = $connections;

        $this->respond($this->viewPath . 'profile.php');
    }

    /**
     * Display login page
     * GET /user/login
     */
    #[AllowedMethods('GET')]
    public function login() {
        $this->display('login');
    }

    /**
     * Get all permissions for the logged-in user
     * GET /user/permissions
     */
    #[RequireLogin]
    #[RequireAuthLevel(1)]
    #[AllowedMethods('GET')]
    public function permissions() {
        if (!isset($_SESSION['user_id'])) {
            $this->error('User not logged in');
            return;
        }

        $userId = \Zero\Core\Session::userId();
        $permissions = $this->getAllPermissions($userId);
        $this->export($permissions);
    }

    /**
     * Check a specific permission for the logged-in user
     * POST /user/permission
     */
    #[RequireLogin]
    #[RequireAuthLevel(1)]
    #[AllowedMethods('POST')]
    #[RequiredParams('permission')]
    public function permission() {
        if (!isset($_SESSION['user_id'])) {
            $this->error('User not logged in');
            return;
        }

        $userId = \Zero\Core\Session::userId();
        $permissionKey = $_POST['permission'];
        $value = $this->getPermission($userId, $permissionKey);

        $this->export([
            'permission' => $permissionKey,
            'value' => $value
        ]);
    }

    /**
     * List user's API tokens
     * GET /user/tokens
     */
    #[RequireLogin]
    #[AllowedMethods('GET')]
    public function tokens() {
        $userId = \Zero\Core\Session::userId();

        try {
            $db = \Zero\Core\Database::getConnection();
            $stmt = $db->prepare(
                "SELECT id, label, created_at, last_used_at, expires_at
                 FROM api_tokens WHERE user_id = ? ORDER BY created_at DESC"
            );
            $stmt->execute([$userId]);
            $tokens = $stmt->fetchAll();
        } catch (\Exception $e) {
            \Zero\Core\Console::error("Error fetching tokens: " . $e->getMessage());
            $tokens = [];
        }

        $this->tokens = $tokens;
        $this->data = ['tokens' => $tokens];
        $this->respond($this->viewPath . 'tokens.php');
    }

    /**
     * Generate a new API token
     * POST /user/create-token
     */
    #[RequireLogin]
    #[AllowedMethods('POST')]
    #[Sanitize(['label'])]
    public function createToken() {
        $userId = \Zero\Core\Session::userId();
        $label = $_POST['label'] ?? '';

        // Generate a cryptographically secure token with zk_ prefix
        $token = 'zk_' . strtr(base64_encode(random_bytes(48)), '+/', '-_');
        $hash = hash('sha256', $token);

        try {
            $db = \Zero\Core\Database::getConnection();
            $stmt = $db->prepare(
                "INSERT INTO api_tokens (user_id, token_hash, label) VALUES (?, ?, ?)"
            );
            $stmt->execute([$userId, $hash, $label]);

            $this->export([
                'token' => $token,
                'label' => $label,
                'id'    => $db->lastInsertId()
            ]);
        } catch (\Exception $e) {
            \Zero\Core\Console::error("Error creating token: " . $e->getMessage());
            $this->export(['error' => 'Failed to create token']);
        }
    }

    /**
     * Revoke an API token
     * POST /user/delete-token/{tokenId}
     */
    #[RequireLogin]
    #[AllowedMethods('POST')]
    public function deleteToken($tokenId = null) {
        if (!$tokenId) {
            $this->export(['error' => 'Token ID required']);
            return;
        }

        $userId = \Zero\Core\Session::userId();
        $tokenId = filter_var($tokenId, FILTER_VALIDATE_INT);

        if (!$tokenId) {
            $this->export(['error' => 'Invalid token ID']);
            return;
        }

        try {
            $db = \Zero\Core\Database::getConnection();
            // AND user_id ensures users can only delete their own tokens
            $stmt = $db->prepare(
                "DELETE FROM api_tokens WHERE id = ? AND user_id = ?"
            );
            $stmt->execute([$tokenId, $userId]);

            if ($stmt->rowCount() === 0) {
                $this->export(['error' => 'Token not found']);
                return;
            }

            $this->export(['message' => 'Token revoked']);
        } catch (\Exception $e) {
            \Zero\Core\Console::error("Error deleting token: " . $e->getMessage());
            $this->export(['error' => 'Failed to delete token']);
        }
    }

    /**
     * Get a specific permission value for a user
     *
     * @param int $userId
     * @param string $permission
     * @return mixed Permission value or null if not found
     */
    private function getPermission(int $userId, string $permission) {
        try {
            // A capability reads as '1'; a setting reads as its value.
            return $this->getAllPermissions($userId)[$permission] ?? null;
        } catch (\Throwable $e) {
            // \Throwable, not \Exception: Database::getConnection() throws
            // \Error on a misconfiguration, same reasoning as
            // Zero\Core\Access\User::settings() and getAllPermissions() below.
            \Zero\Core\Console::error("Error fetching permission: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Get all permissions for a user
     *
     * @param int $userId
     * @return array Associative array of permission => value
     */
    private function getAllPermissions(int $userId): array {
        try {
            $user = \Zero\Core\Access\User::find($userId);
            if (!$user) {
                return [];
            }

            // Capabilities the user holds, each as '1', then their settings.
            return array_fill_keys($user->capabilities(), '1') + $user->settings();
        } catch (\Throwable $e) {
            // \Throwable, not \Exception: Database::getConnection() throws
            // \Error on a misconfiguration, same reasoning as
            // Zero\Core\Access\User::settings().
            \Zero\Core\Console::error("Error fetching permissions: " . $e->getMessage());
            return [];
        }
    }
}
