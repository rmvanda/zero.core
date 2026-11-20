<?php

namespace Zero\Module;

use \Zero\Core\Database;
use Zero\Core\Access\User;
use Zero\Core\Session;
use \Zero\Core\Attribute\RequireLogin;
use \Zero\Lib\Push\PushHelper;

/**
 * Push Module - Handles push notification subscriptions and tracking
 *
 * Endpoints:
 * - POST /push/subscribe - Subscribe user to push notifications
 * - POST /push/unsubscribe - Unsubscribe user from push notifications
 * - GET /push/status - Check subscription status for current user
 * - POST /push/click - Track notification click
 * - GET /push/vapid-key - Get public VAPID key for frontend
 *
 * AUTH: gated per method, NOT at class level, because vapid-key must stay
 * public — assets/js/push-manager.js is loaded from the global frame head and
 * fetches it on every page load, anonymous ones included. A VAPID *public* key
 * is public by definition. Every other endpoint acts on a user and carries
 * #[RequireLogin].
 *
 * The four acting endpoints used to resolve the user as
 * `currentId() ?? $_COOKIE['unisolu_user_id'] ?? $input['user_id']`. Both
 * fallbacks are client-controlled, and there was no gate, so an anonymous
 * caller could name any user id: subscribe() would register the CALLER's push
 * endpoint against that account — handing them the victim's notifications —
 * unsubscribe() would deregister the victim's devices, and status() disclosed
 * whether an arbitrary id was subscribed. Verified live before the fix:
 *
 *   $ curl -s "https://unisolu.com/push/status?user_id=1"
 *   {"data":{"subscribed":true,"count":2}, ...}
 *
 * The id now comes from the session alone. Do not reintroduce a caller-supplied
 * fallback: a service worker that has lost its session needs a real credential,
 * not an asserted id.
 * The hint cookie's name now comes from `Session::hintCookieName()`, and nothing here reads it.
 */
class Push extends \Zero\Core\Module {

    /**
     * Subscribe user to push notifications
     * POST /push/subscribe
     *
     * Expects JSON payload:
     * {
     *   "endpoint": "https://...",
     *   "keys": {
     *     "p256dh": "...",
     *     "auth": "..."
     *   }
     * }
     */
    #[RequireLogin]
    public function subscribe() {
        $input = json_decode(file_get_contents('php://input'), true);

        if (!isset($input['endpoint']) || !isset($input['keys'])) {
            http_response_code(400);
            $this->export(['error' => 'Missing required fields'], ['status' => 'error', 'code' => 400]);
            return;
        }

        // The signed-in user, and only that. See the class docblock for what
        // the two client-controlled fallbacks that used to sit here allowed.
        // #[RequireLogin] guarantees this is set; the guard is belt-and-braces
        // on an auth path, not a reachable branch.
        $userId = Session::userId();

        if (!$userId) {
            http_response_code(401);
            $this->export(['error' => 'User not authenticated'], ['status' => 'error', 'code' => 401]);
            return;
        }

        $db = Database::getConnection();

        try {
            $stmt = $db->prepare("
                INSERT INTO push_subscriptions
                (user_id, endpoint, p256dh_key, auth_token)
                VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                p256dh_key = VALUES(p256dh_key),
                auth_token = VALUES(auth_token)
            ");

            $stmt->execute([
                $userId,
                $input['endpoint'],
                $input['keys']['p256dh'],
                $input['keys']['auth']
            ]);

            $this->export(['success' => true, 'message' => 'Subscription saved']);
        } catch (\Exception $e) {
            error_log("Push subscription error: " . $e->getMessage());
            http_response_code(500);
            $this->export(['error' => 'Failed to save subscription'], ['status' => 'error', 'code' => 500]);
        }
    }

    /**
     * Unsubscribe user from push notifications
     * POST /push/unsubscribe
     *
     * Expects JSON payload:
     * {
     *   "endpoint": "https://..."
     * }
     */
    #[RequireLogin]
    public function unsubscribe() {
        $input = json_decode(file_get_contents('php://input'), true);

        if (!isset($input['endpoint'])) {
            http_response_code(400);
            $this->export(['error' => 'Missing endpoint'], ['status' => 'error', 'code' => 400]);
            return;
        }

        // The signed-in user, and only that. See the class docblock for what
        // the two client-controlled fallbacks that used to sit here allowed.
        // #[RequireLogin] guarantees this is set; the guard is belt-and-braces
        // on an auth path, not a reachable branch.
        $userId = Session::userId();

        if (!$userId) {
            http_response_code(401);
            $this->export(['error' => 'User not authenticated'], ['status' => 'error', 'code' => 401]);
            return;
        }

        $db = Database::getConnection();

        try {
            $stmt = $db->prepare("
                DELETE FROM push_subscriptions
                WHERE user_id = ? AND endpoint = ?
            ");

            $stmt->execute([$userId, $input['endpoint']]);

            $this->export(['success' => true, 'message' => 'Subscription removed']);
        } catch (\Exception $e) {
            error_log("Push unsubscribe error: " . $e->getMessage());
            http_response_code(500);
            $this->export(['error' => 'Failed to remove subscription'], ['status' => 'error', 'code' => 500]);
        }
    }

    /**
     * Get subscription status for current user
     * GET /push/status
     *
     * Returns:
     * {
     *   "subscribed": true/false,
     *   "count": 1
     * }
     */
    #[RequireLogin]
    public function status() {
        // The signed-in user, and only that. This endpoint previously answered
        // for ANY id a caller named, disclosing whether that account had push
        // subscriptions. See the class docblock.
        $userId = Session::userId();

        if (!$userId) {
            $this->export([
                'subscribed' => false,
                'count' => 0
            ]);
            return;
        }

        $count = PushHelper::getSubscriptionCount($userId);

        $this->export([
            'subscribed' => $count > 0,
            'count' => $count
        ]);
    }

    /**
     * Track notification click
     * POST /push/click
     *
     * Expects JSON payload:
     * {
     *   "notification_id": 123
     * }
     */
    #[RequireLogin]
    public function click() {
        $input = json_decode(file_get_contents('php://input'), true);

        if (!isset($input['notification_id'])) {
            http_response_code(400);
            $this->export(['error' => 'Missing notification_id'], ['status' => 'error', 'code' => 400]);
            return;
        }

        // The signed-in user, and only that. See the class docblock for what
        // the two client-controlled fallbacks that used to sit here allowed.
        // #[RequireLogin] guarantees this is set; the guard is belt-and-braces
        // on an auth path, not a reachable branch.
        $userId = Session::userId();

        if (!$userId) {
            http_response_code(401);
            $this->export(['error' => 'User not authenticated'], ['status' => 'error', 'code' => 401]);
            return;
        }

        $success = PushHelper::trackClick($input['notification_id'], $userId);

        $this->export(['success' => $success]);
    }

    /**
     * Get public VAPID key for frontend
     * GET /push/vapid-key
     *
     * Returns:
     * {
     *   "publicKey": "..."
     * }
     */
    public function vapidKey() {
        $vapidConfig = parse_ini_file(ZERO_ROOT . '/app/config/vapid.ini');

        $this->export([
            'publicKey' => $vapidConfig['VAPID_PUBLIC_KEY']
        ]);
    }
}
