# Push Notifications Implementation

Web Push Notifications system using the native Web Push API with VAPID authentication.

## Overview

- **No third-party service** - Direct browser-to-server push using Web Push API
- **Simple opt-in/opt-out** - Toggle in Settings menu
- **Admin interface** - Send to specific users or all subscribed users
- **Click tracking** - Monitor engagement with notification analytics

## Architecture

### Backend (PHP)

**Library:** `core/lib/Push/PushHelper.php`
- `PushHelper::createNotification($data)` - Create notification record
- `PushHelper::sendPush($notificationId, $userIds)` - Send to specific users
- `PushHelper::sendPushToAll($notificationId)` - Send to all subscribed users
- `PushHelper::trackClick($notificationId, $userId)` - Track clicks
- `PushHelper::isSubscribed($userId)` - Check subscription status

**Module:** `/core/modules/Push/Push.php`
- `POST /push/subscribe` - Save user subscription
- `POST /push/unsubscribe` - Remove subscription
- `GET /push/status` - Check subscription status
- `POST /push/click` - Track notification click
- `GET /push/vapid-key` - Get public VAPID key

**Admin:** `/core/modules/Push/Admin/PushAdmin.php::index()`
- Form to compose and send notifications
- History of sent notifications
- Click analytics

### Frontend (JavaScript)

**Service Worker:** `/assets/js/push-worker.js`
- Handles push events from server
- Displays notifications
- Tracks clicks when user interacts

**Manager:** `/assets/js/push-manager.js`
- `PushManager.init()` - Initialize and register service worker
- `PushManager.subscribe()` - Request permission and subscribe
- `PushManager.unsubscribe()` - Remove subscription
- `PushManager.isSubscribed()` - Check browser subscription status

**UI:** Settings toggle in `app/frontend/frame/footer.php`
- Checkbox to enable/disable notifications
- Shows current status (Enabled/Disabled/Blocked)

### Database Schema

**push_subscriptions**
```sql
id, user_id, endpoint, p256dh_key, auth_token, created_at
```

**push_notifications**
```sql
id, title, body, icon, url, target_type, created_at, created_by, sent_at, recipient_count
```

**push_notification_clicks**
```sql
id, notification_id, user_id, clicked_at
```

## Configuration

**VAPID Keys:** `/app/config/vapid.ini`
```ini
VAPID_PUBLIC_KEY="..."
VAPID_PRIVATE_KEY="..."
```

Keys are auto-generated during setup. Keep private key secret!

## User Authentication Fallback

The system supports three methods for identifying users:

1. `$_SESSION['user_id']` - Primary (logged-in users)
2. `$_COOKIE[SESSION_HINT_COOKIE]` - Persistent cookie, named by the site constant `SESSION_HINT_COOKIE` (never expires)
3. `localStorage['user_id']` - Frontend fallback (sent via API)

**Note:** Cookie value is currently plain user_id. Encryption will be added in future.

## Usage Examples

### From Any Module

```php
use \Zero\Lib\Push\PushHelper;

// Send notification to specific user
$notificationId = PushHelper::createNotification([
    'title' => 'New Message',
    'body' => 'You have a new message from John',
    'icon' => '/assets/img/message-icon.png',
    'url' => '/messages/123',
    'created_by' => $_SESSION['user_id']
]);

PushHelper::sendPush($notificationId, [456]); // Send to user 456

// Send to all users
PushHelper::sendPushToAll($notificationId);
```

### From Admin Panel

1. Navigate to `/admin/push`
2. Fill out the form:
   - Title (required)
   - Message (required)
   - Icon URL (optional)
   - Destination URL (optional)
   - Target: All users or specific users
3. Click "Send Push Notification"

## Dependencies

**Composer Package:** `minishlink/web-push` v8.0
- Handles VAPID signing and payload encryption
- Installed via: `composer require minishlink/web-push`

## Browser Support

Requires:
- Service Worker API
- Push API
- Notification API

Supported browsers: Chrome 50+, Firefox 44+, Edge 17+, Safari 16+ (macOS only)

## Permissions

Users must grant notification permission at the browser level:
- **Granted:** User can receive notifications
- **Denied:** User blocked notifications (toggle disabled)
- **Default:** Permission not yet requested

## Security Notes

1. **VAPID Keys:** Private key must remain secret (already in .gitignore)
2. **HTTPS Required:** Web Push only works over HTTPS
3. **User ID Cookie:** Currently unencrypted - encryption pending
4. **localStorage Fallback:** Not secure, but acceptable for this use case

## File Checklist

- [x] `core/lib/Push/PushHelper.php` - Helper library
- [x] `/core/modules/Push/Push.php` - API endpoints
- [x] `/core/modules/Push/CLAUDE.md` - Module documentation
- [x] `/core/modules/Push/Admin/PushAdmin.php` - Admin offering
- [x] `/core/modules/Push/Admin/view/index.php` - Admin UI
- [x] `/core/modules/Push/Admin/admin-config.php` - Listed on the admin dashboard
- [x] `/assets/js/push-worker.js` - Service worker
- [x] `/assets/js/push-manager.js` - Frontend manager
- [x] `/app/frontend/frame/footer.php` - Settings toggle + scripts
- [x] `/core/modules/Auth/Auth.php` - Persistent cookie added
- [x] `/app/config/vapid.ini` - VAPID keys (auto-generated)
- [x] `/core/Application.php` - Composer autoload path updated
- [x] Database tables created

## Testing

1. **User Subscription:**
   - Open Settings (gear icon in header)
   - Toggle "Allow Notifications"
   - Grant permission when prompted

2. **Send Test Notification:**
   - Go to `/admin/push`
   - Fill form with test data
   - Select "All Subscribed Users"
   - Click Send

3. **Verify:**
   - Notification appears on desktop/mobile
   - Click notification → redirects to URL
   - Check admin panel → click count increments

## Troubleshooting

**Notifications not appearing:**
- Check browser permission (not blocked)
- Verify HTTPS (required for Web Push)
- Check browser console for errors
- Verify service worker registered: `navigator.serviceWorker.getRegistrations()`

**Subscription fails:**
- Check VAPID keys exist in config
- Verify Composer autoload working
- Check network tab for API errors

**Admin panel errors:**
- Ensure user has admin permission
- Check database tables exist
- Verify PushHelper class loads correctly
