<?php

namespace Zero\Module\Push\Lib;

use \Minishlink\WebPush\WebPush;
use \Minishlink\WebPush\Subscription;
use \Zero\Core\Database;

/**
 * PushHelper - Reusable helper for creating and sending push notifications
 *
 * Usage:
 * - PushHelper::createNotification($data) - Creates a notification record
 * - PushHelper::sendPush($notificationId, $userIds) - Sends push to users
 * - PushHelper::sendPushToAll($notificationId) - Sends push to all subscribed users
 */
class PushHelper {

    /**
     * Create a notification record in the database
     *
     * @param array $data Notification data [title, body, icon, url, created_by]
     * @return int Notification ID
     */
    public static function createNotification(array $data): int {
        $db = Database::getConnection();

        $stmt = $db->prepare("
            INSERT INTO push_notifications
            (title, body, icon, image, url, target_type, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");

        $stmt->execute([
            $data['title'],
            $data['body'],
            $data['icon'] ?? null,
            $data['image'] ?? null,
            $data['url'] ?? null,
            $data['target_type'] ?? 'specific',
            $data['created_by']
        ]);

        return (int)$db->lastInsertId();
    }

    /**
     * Send push notification to specific users
     *
     * @param int $notificationId The notification ID to send
     * @param array $userIds Array of user IDs to send to (empty array = all users)
     * @return array ['success' => int, 'failed' => int]
     */
    public static function sendPush(int $notificationId, array $userIds = []): array {
        $db = Database::getConnection();

        // Get notification data
        $stmt = $db->prepare("SELECT * FROM push_notifications WHERE id = ?");
        $stmt->execute([$notificationId]);
        $notification = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$notification) {
            throw new \Exception("Notification not found");
        }

        // Get subscriptions
        if (empty($userIds)) {
            // Send to all subscribed users
            $stmt = $db->query("SELECT * FROM push_subscriptions");
            $targetType = 'all';
        } else {
            // Send to specific users
            $placeholders = implode(',', array_fill(0, count($userIds), '?'));
            $stmt = $db->prepare("SELECT * FROM push_subscriptions WHERE user_id IN ($placeholders)");
            $stmt->execute($userIds);
            $targetType = 'specific';
        }

        $subscriptions = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        if (empty($subscriptions)) {
            return ['success' => 0, 'failed' => 0];
        }

        // Load VAPID keys
        $vapidConfig = parse_ini_file(ZERO_ROOT . '/app/config/vapid.ini');

        $auth = [
            'VAPID' => [
                'subject' => 'https://' . PRIMARY_DOMAIN,
                'publicKey' => $vapidConfig['VAPID_PUBLIC_KEY'],
                'privateKey' => $vapidConfig['VAPID_PRIVATE_KEY'],
            ]
        ];

        $webPush = new WebPush($auth);

        // Prepare notification payload
        $payload = json_encode([
            'title' => $notification['title'],
            'body' => $notification['body'],
            'icon' => $notification['icon'] ?? '/assets/img/logo.png',
            'image' => $notification['image'] ?? null,
            'url' => $notification['url'] ?? '/',
            'notification_id' => $notificationId
        ]);

        $successCount = 0;
        $failedCount = 0;

        \Zero\Core\Console::info("[PushHelper] Sending to " . count($subscriptions) . " subscription(s)");

        // Send to each subscription
        foreach ($subscriptions as $sub) {
            $subscription = Subscription::create([
                'endpoint' => $sub['endpoint'],
                'keys' => [
                    'p256dh' => $sub['p256dh_key'],
                    'auth' => $sub['auth_token']
                ]
            ]);

            \Zero\Core\Console::debug("[PushHelper] Queued notification for endpoint: " . substr($sub['endpoint'], 0, 50) . "...");
            $webPush->queueNotification($subscription, $payload);
        }

        // Send all queued notifications
        \Zero\Core\Console::debug("[PushHelper] Flushing queued notifications...");
        foreach ($webPush->flush() as $report) {
            if ($report->isSuccess()) {
                $successCount++;
                \Zero\Core\Console::debug("[PushHelper] ✓ Success for: " . substr($report->getEndpoint(), 0, 50) . "...");
            } else {
                $failedCount++;
                \Zero\Core\Console::warn("[PushHelper] ✗ Failed for: " . substr($report->getEndpoint(), 0, 50) . "... Reason: " . $report->getReason());

                // If subscription is expired/invalid, remove it
                if ($report->isSubscriptionExpired()) {
                    $endpoint = $report->getEndpoint();
                    \Zero\Core\Console::info("[PushHelper] Removing expired subscription: " . substr($endpoint, 0, 50) . "...");
                    $db->prepare("DELETE FROM push_subscriptions WHERE endpoint = ?")
                       ->execute([$endpoint]);
                }
            }
        }

        \Zero\Core\Console::info("[PushHelper] Send complete: {$successCount} success, {$failedCount} failed");

        // Update notification record
        $db->prepare("
            UPDATE push_notifications
            SET sent_at = NOW(),
                recipient_count = ?,
                target_type = ?
            WHERE id = ?
        ")->execute([$successCount, $targetType, $notificationId]);

        return [
            'success' => $successCount,
            'failed' => $failedCount
        ];
    }

    /**
     * Send push notification to all subscribed users
     * Convenience wrapper for sendPush with empty userIds
     *
     * @param int $notificationId The notification ID to send
     * @return array ['success' => int, 'failed' => int]
     */
    public static function sendPushToAll(int $notificationId): array {
        return self::sendPush($notificationId, []);
    }

    /**
     * Track notification click
     *
     * @param int $notificationId The notification that was clicked
     * @param int $userId The user who clicked
     * @return bool Success
     */
    public static function trackClick(int $notificationId, int $userId): bool {
        $db = Database::getConnection();

        try {
            $stmt = $db->prepare("
                INSERT IGNORE INTO push_notification_clicks
                (notification_id, user_id)
                VALUES (?, ?)
            ");
            $stmt->execute([$notificationId, $userId]);
            return true;
        } catch (\Exception $e) {
            \Zero\Core\Console::error("Failed to track push click: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get subscription count for a user
     *
     * @param int $userId User ID
     * @return int Number of subscriptions
     */
    public static function getSubscriptionCount(int $userId): int {
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT COUNT(*) FROM push_subscriptions WHERE user_id = ?");
        $stmt->execute([$userId]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Check if user has any active subscriptions
     *
     * @param int $userId User ID
     * @return bool True if user has subscriptions
     */
    public static function isSubscribed(int $userId): bool {
        return self::getSubscriptionCount($userId) > 0;
    }
}
