# Push Module

Push notification system using Web Push API with VAPID authentication.

## Overview

The Push module provides a complete push notification system for sending browser notifications to subscribed users. It's built on the native Web Push API and requires no third-party services.

## For Module Developers: Sending Push Notifications

Any module can send push notifications using the `PushHelper` library class.

### Basic Usage

```php
use \Zero\Lib\Push\PushHelper;

// 1. Create a notification
$notificationId = PushHelper::createNotification([
    'title' => 'New Message',
    'body' => 'You have a new message from John',
    'icon' => '/assets/img/message-icon.png',  // Optional, defaults to logo
    'url' => '/messages/123',                   // Optional, defaults to '/'
    'created_by' => \Zero\Core\Session::userId()        // Required
]);

// 2. Send to specific user(s)
$result = PushHelper::sendPush($notificationId, [123, 456]); // Send to user IDs 123 and 456

// OR send to all subscribed users
$result = PushHelper::sendPushToAll($notificationId);

// 3. Check results
echo "Sent to {$result['success']} devices";
echo "Failed: {$result['failed']} devices";
```

### Use Cases & Examples

#### Example 1: New Comment Notification (Blog Module)

```php
// In modules/Blog/Blog.php
public function addComment($postId) {
    $userId = \Zero\Core\Session::userId();

    // Save comment to database...

    // Notify post author
    $post = $this->getPost($postId);

    $notificationId = \Zero\Lib\Push\PushHelper::createNotification([
        'title' => 'New Comment',
        'body' => 'Someone commented on your post',
        'url' => "/blog/post/{$postId}",
        'created_by' => $userId
    ]);

    \Zero\Lib\Push\PushHelper::sendPush($notificationId, [$post['author_id']]);
}
```

#### Example 2: System Alert (Admin Module)

```php
// In core/modules/Admin/Admin.php
public function sendSystemAlert() {
    $message = $_POST['message'];

    $notificationId = \Zero\Lib\Push\PushHelper::createNotification([
        'title' => 'System Alert',
        'body' => $message,
        'icon' => '/assets/img/alert-icon.png',
        'url' => '/admin',
        'created_by' => \Zero\Core\Session::userId()
    ]);

    // Send to all subscribed users
    \Zero\Lib\Push\PushHelper::sendPushToAll($notificationId);
}
```

#### Example 3: Order Status Update (E-commerce Module)

```php
// In modules/Shop/Shop.php
public function updateOrderStatus($orderId, $status) {
    // Update order in database...

    $order = $this->getOrder($orderId);

    $statusMessages = [
        'shipped' => 'Your order has been shipped!',
        'delivered' => 'Your order has been delivered',
        'cancelled' => 'Your order has been cancelled'
    ];

    $notificationId = \Zero\Lib\Push\PushHelper::createNotification([
        'title' => 'Order Update',
        'body' => $statusMessages[$status] ?? 'Order status updated',
        'url' => "/shop/order/{$orderId}",
        'created_by' => 1 // System user
    ]);

    \Zero\Lib\Push\PushHelper::sendPush($notificationId, [$order['customer_id']]);
}
```

## PushHelper API Reference

### `PushHelper::createNotification(array $data): int`

Creates a notification record in the database.

**Parameters:**
- `$data['title']` (string, required) - Notification title
- `$data['body']` (string, required) - Notification message
- `$data['icon']` (string, optional) - Icon URL (defaults to `/assets/img/logo.png`)
- `$data['url']` (string, optional) - Destination URL when clicked (defaults to `/`)
- `$data['target_type']` (string, optional) - 'all' or 'specific' (defaults to 'specific')
- `$data['created_by']` (int, required) - User ID of creator

**Returns:** `int` - The notification ID

---

### `PushHelper::sendPush(int $notificationId, array $userIds): array`

Sends push notification to specific users.

**Parameters:**
- `$notificationId` (int) - The notification ID to send
- `$userIds` (array) - Array of user IDs to send to (empty array = all users)

**Returns:** `array` - `['success' => int, 'failed' => int]`

**Notes:**
- Automatically removes expired/invalid subscriptions
- Each user can have multiple device subscriptions
- Logs detailed debug information to console

---

### `PushHelper::sendPushToAll(int $notificationId): array`

Convenience method to send to all subscribed users.

**Parameters:**
- `$notificationId` (int) - The notification ID to send

**Returns:** `array` - `['success' => int, 'failed' => int]`

---

### `PushHelper::trackClick(int $notificationId, int $userId): bool`

Track when a user clicks a notification (handled automatically by service worker).

**Parameters:**
- `$notificationId` (int) - The notification that was clicked
- `$userId` (int) - The user who clicked

**Returns:** `bool` - Success status

---

### `PushHelper::isSubscribed(int $userId): bool`

Check if a user has any active subscriptions.

**Parameters:**
- `$userId` (int) - User ID to check

**Returns:** `bool` - True if user has subscriptions

---

### `PushHelper::getSubscriptionCount(int $userId): int`

Get the number of device subscriptions for a user.

**Parameters:**
- `$userId` (int) - User ID to check

**Returns:** `int` - Number of active subscriptions

## Best Practices

### 1. Don't Spam Users
```php
// BAD - Sending too frequently
foreach ($updates as $update) {
    PushHelper::sendPushToAll($notificationId); // Spamming!
}

// GOOD - Batch notifications or limit frequency
if (time() - $lastNotificationTime > 3600) { // Once per hour
    PushHelper::sendPushToAll($notificationId);
}
```

### 2. Use Meaningful URLs
```php
// BAD - Generic URL
'url' => '/'

// GOOD - Direct to relevant content
'url' => "/messages/{$messageId}#new"
```

### 3. Keep Messages Concise
```php
// BAD - Too verbose
'title' => 'Important Notification Regarding Your Recent Activity',
'body' => 'We wanted to inform you that there has been...'

// GOOD - Clear and concise
'title' => 'New Message',
'body' => 'John sent you a message'
```

### 4. Check Subscription Status Before Important Actions
```php
if (PushHelper::isSubscribed($userId)) {
    // User can receive notifications
    PushHelper::sendPush($notificationId, [$userId]);
} else {
    // Fallback to email or in-app notification
    $this->sendEmail($userId, $message);
}
```

## Error Handling

The `sendPush()` method automatically handles:
- **Expired subscriptions** - Removed from database
- **Invalid endpoints** - Logged and skipped
- **Network failures** - Logged with reason

```php
$result = PushHelper::sendPush($notificationId, [123]);

if ($result['failed'] > 0) {
    error_log("Some notifications failed to send");
    // Optionally: send email fallback
}
```

## Database Schema

**push_notifications** - Stores notification history
- `id`, `title`, `body`, `icon`, `url`, `target_type`
- `created_at`, `created_by`, `sent_at`, `recipient_count`

**push_subscriptions** - Stores user device subscriptions
- `id`, `user_id`, `endpoint`, `p256dh_key`, `auth_token`, `created_at`
- Unique constraint on `(user_id, endpoint)` allows multiple devices per user

**push_notification_clicks** - Tracks user engagement
- `id`, `notification_id`, `user_id`, `clicked_at`
- Unique constraint on `(notification_id, user_id)` prevents duplicate tracking

## Frontend Integration

Users opt-in via Settings → "Allow Notifications" toggle.

The service worker (`/push-worker.js`) automatically:
- Displays notifications when received
- Tracks clicks
- Opens the specified URL on click

## Admin Interface

Admins can send notifications at `/admin/push` with:
- Target selection (all users or specific users)
- Custom title, message, icon, and URL
- History view with click tracking
- Search, filter, and sort capabilities
- Bulk delete operations

## Testing

```php
// Test notification
$id = PushHelper::createNotification([
    'title' => 'Test Notification',
    'body' => 'Testing the push system',
    'created_by' => \Zero\Core\Session::userId()
]);

$result = PushHelper::sendPush($id, [\Zero\Core\Session::userId()]);
var_dump($result); // Check console logs for details
```

## Troubleshooting

**Notifications not sending:**
1. Check Console logs for debug output
2. Verify VAPID keys exist in `app/config/vapid.ini`
3. Ensure Composer autoloader is working
4. Check `push_subscriptions` table for active subscriptions

**User not subscribed:**
1. Check browser permission (not denied)
2. Verify service worker registered: `/push-worker.js`
3. Check HTTPS (required for Web Push)

## Security Notes

- VAPID private key must remain secret
- Subscriptions are per user AND per device
- No sensitive data should be included in notification body

### The acting user comes from the session ONLY

`subscribe`, `unsubscribe`, `status` and `click` each carry `#[RequireLogin]`
and read `Zero\Core\Session::userId()`. `vapid-key` is deliberately public —
`assets/js/push-manager.js` is loaded from the global frame head and fetches it
on every page load, anonymous ones included, and a VAPID *public* key is public
by definition. That is why the gate is per-method rather than class-level.

**Never accept the user id from the caller** — not from a cookie, not from the
request body, not from a query string. This module shipped that once and it was
remotely exploitable by anyone: `subscribe` registered the caller's push endpoint
against a named account, so the attacker received the victim's notifications;
`unsubscribe` deregistered the victim's devices; `status` disclosed whether an
arbitrary account was subscribed. `DEPRECATING_CHANGES.md` has the detail.

A service worker that has lost its session needs a real credential — a signed
subscription token, or an API token via `#[AllowWithToken]` — not an asserted id.
