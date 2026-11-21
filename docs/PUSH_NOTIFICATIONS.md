# Push Notifications

Browser push notifications over the Web Push API with VAPID keys, sent straight from the server
with no third-party service. This file is the map of the pieces and what a site needs for them
to work. To send a notification from a module, see `core/modules/Push/CLAUDE.md`: the
`PushHelper` reference, examples and practices are there.

## The pieces

| Piece | Where | What it does |
|-------|-------|--------------|
| `Zero\Module\Push\Lib\PushHelper` | `core/modules/Push/lib/PushHelper.php` | Creates notification records, sends them, records clicks |
| `Push` module | `core/modules/Push/Push.php` | The `/push/*` endpoints the browser calls |
| `PushAdmin` | `core/modules/Push/Admin/` | The `/admin/push` screen |
| Service worker | the site's `app/frontend/www/push-worker.js`, served as `/push-worker.js` | Shows a notification when a push arrives; on a click, posts to `/push/click` and opens the URL; subscribes again on `pushsubscriptionchange` |
| `PushManager` | the site's `app/frontend/www/assets/js/push-manager.js` | Registers the worker and subscribes or unsubscribes the browser |
| Settings toggle | the site's `app/frontend/frame/footer.php` | The "Allow Notifications" checkbox |

Push is an optional module: on unless `app/modules.ini` turns it off. The three frontend files
belong to the site, not to core.

## Endpoints

| Endpoint | Login | Does |
|----------|-------|------|
| `POST /push/subscribe` | required | Saves the browser's subscription. JSON body: `endpoint`, `keys.p256dh`, `keys.auth` |
| `POST /push/unsubscribe` | required | Removes it. JSON body: `endpoint` |
| `GET /push/status` | required | `subscribed` and `count` for the logged-in user |
| `POST /push/click` | required | Records a click. JSON body: `notification_id` |
| `GET /push/vapid-key` | public | `publicKey`, for the browser to subscribe with |

The acting user is `Session::userId()` and nothing else. A `user_id` in a request body, query
string or cookie is ignored. `vapid-key` is public because `push-manager.js` is loaded in the
frame head and fetches it on every page, logged in or not; that is why `#[RequireLogin]` is on
each method and not on the class.

## Admin screen

`/admin/push` (`PushAdmin::index()`, `allow.admin`):

- GET shows the form and the history of sent notifications with their click counts. The history
  takes `search`, `target_filter` and `sort` in the query string.
- POST sends a notification: `title` and `body` (required), `icon`, `url`, `target_type` (`all`
  or `specific`) and `user_ids` (comma-separated, for `specific`).
- `POST /admin/push/delete` deletes one, several or all notifications with their clicks. It is audit
  logged as `push_notification_delete`.

## Sending from code

```php
use \Zero\Module\Push\Lib\PushHelper;

$notificationId = PushHelper::createNotification([
    'title' => 'New Message',
    'body' => 'You have a new message from John',
    'icon' => '/assets/img/message-icon.png',   // optional, /assets/img/logo.png when absent
    'image' => '/assets/img/preview.png',       // optional
    'url' => '/messages/123',                   // optional, / when absent
    'created_by' => \Zero\Core\Session::userId()
]);

$result = PushHelper::sendPush($notificationId, [456]);   // these users, every device of each
$result = PushHelper::sendPushToAll($notificationId);     // every subscription
// $result: ['success' => int, 'failed' => int], counted per device
```

- `sendPush()` with an empty list of users sends to everyone, the same as `sendPushToAll()`.
- A subscription the push service reports as expired is deleted during the send.
- A send sets the notification's `sent_at`, `target_type` and `recipient_count` (the number of
  devices that succeeded).
- Each send logs through `Console`: info for the totals, debug per device, warn for a failure.

## Tables

In `core/sql/core.sql`.

| Table | Columns | Notes |
|-------|---------|-------|
| `push_subscriptions` | `id`, `user_id`, `endpoint`, `p256dh_key`, `auth_token`, `created_at` | Unique on `(user_id, endpoint)`: one row per device of a user |
| `push_notifications` | `id`, `title`, `body`, `icon`, `image`, `url`, `target_type` (`all` or `specific`), `created_at`, `created_by`, `sent_at`, `recipient_count` | |
| `push_notification_clicks` | `id`, `notification_id`, `user_id`, `clicked_at` | Unique on `(notification_id, user_id)`: one click per user |

## What a site needs

- **`app/config/vapid.ini`** with the key pair. `PushHelper` and `/push/vapid-key` read it
  directly. The private key stays out of git.

  ```ini
  VAPID_PUBLIC_KEY="..."
  VAPID_PRIVATE_KEY="..."
  ```

- **`PRIMARY_DOMAIN`** defined: the VAPID subject is `https://` followed by it.
- **`minishlink/web-push` `^8.0`** installed with Composer. It signs with the VAPID keys and
  encrypts the payload. `Application::registerAutoloaders()` loads Composer's autoloader from
  `ROOT_PATH/vendor/` and from `ZERO_ROOT/vendor/`.
- **HTTPS.** Web Push does not work without it.
- **The three frontend files** listed above, with `push-manager.js` loaded in the frame head.

## In the browser

The browser needs the Service Worker, Push and Notification APIs: Chrome 50+, Firefox 44+,
Edge 17+, Safari 16+ (macOS only).

`Notification.permission` decides what the toggle can do:

- `granted`: the user can subscribe and receive notifications.
- `default`: not asked yet. Ticking the toggle asks.
- `denied`: the user blocked notifications. The toggle is disabled.

## Testing

1. Open Settings, tick "Allow Notifications" and grant permission when the browser asks.
2. At `/admin/push`, fill in the form, choose all users and send.
3. The notification appears; clicking it opens the URL, and the click count on `/admin/push`
   goes up.

## Troubleshooting

**Nothing appears:**
- The browser's permission is `denied`, or the site is not on HTTPS.
- The service worker is not registered: `navigator.serviceWorker.getRegistrations()`.
- The log shows the send: look for `[PushHelper]` lines and the reason given for a failure.

**Subscribing fails:**
- `app/config/vapid.ini` is missing or has no keys.
- The user is not logged in: the `/push/*` endpoints other than `vapid-key` require it.
- The network tab shows the error the endpoint returned.

**The admin screen fails:**
- The user lacks `allow.admin`.
- The `push_*` tables are missing.
- `Minishlink\WebPush` is not installed, so `PushHelper` cannot send.
