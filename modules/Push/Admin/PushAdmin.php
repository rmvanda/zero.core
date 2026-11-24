<?php
namespace Zero\Core\Module\Push\Admin;

use Zero\Core\AdminResponse;
use Zero\Core\Database;
use Zero\Core\Session;
use Zero\Core\Attribute\RequirePermission;
use Zero\Core\Attribute\AllowedMethods;
use Zero\Core\Attribute\AuditLog;
use Zero\Core\Module\Push\Lib\PushHelper;

/**
 * Push notification admin: send a notification and manage the history
 */
#[RequirePermission("allow.admin")]
class PushAdmin extends AdminResponse {

    /**
     * Push Notifications Management
     * GET: Show form and history
     * POST: Send push notification
     */
    public function index() {
        $db = Database::getConnection();

        // Handle POST - send push notification
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            try {
                $title = $_POST['title'] ?? '';
                $body = $_POST['body'] ?? '';
                $icon = $_POST['icon'] ?? '/assets/img/logo.png';
                $url = $_POST['url'] ?? '/';
                $targetType = $_POST['target_type'] ?? 'all';
                $userIds = isset($_POST['user_ids']) ? explode(',', $_POST['user_ids']) : [];

                if (empty($title) || empty($body)) {
                    throw new \Exception("Title and body are required");
                }

                // Create notification using PushHelper
                $notificationId = PushHelper::createNotification([
                    'title' => $title,
                    'body' => $body,
                    'icon' => $icon,
                    'url' => $url,
                    'target_type' => $targetType,
                    'created_by' => Session::userId()
                ]);

                // Send notification
                if ($targetType === 'all') {
                    $result = PushHelper::sendPushToAll($notificationId);
                } else {
                    // Filter out empty values and convert to integers
                    $userIds = array_filter(array_map('intval', $userIds));
                    $result = PushHelper::sendPush($notificationId, $userIds);
                }

                $this->success(
                    "Push notification sent to {$result['success']} user(s)",
                    ['notification_id' => $notificationId, 'result' => $result]
                );

                // Redirect to avoid resubmission
                header("Location: /admin/push");
                exit;
            } catch (\Exception $e) {
                \Zero\Core\Console::error("Push notification send error: " . $e->getMessage());
                $this->error('Failed to send notification: ' . $e->getMessage());
            }
        }

        // GET request - show form and history
        // Get notification history with search/filter/sort
        $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
        $perPage = 25;
        $offset = ($page - 1) * $perPage;

        // Build WHERE clause
        $where = [];
        $params = [];

        if (!empty($_GET['search'])) {
            $where[] = "(pn.title LIKE ? OR pn.body LIKE ?)";
            $searchTerm = '%' . $_GET['search'] . '%';
            $params[] = $searchTerm;
            $params[] = $searchTerm;
        }

        if (!empty($_GET['target_filter'])) {
            $where[] = "pn.target_type = ?";
            $params[] = $_GET['target_filter'];
        }

        $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        // Build ORDER BY clause
        $orderBy = match ($_GET['sort'] ?? 'date_desc') {
            'date_asc' => 'pn.created_at ASC',
            'recipients_desc' => 'pn.recipient_count DESC, pn.created_at DESC',
            'clicks_desc' => 'click_count DESC, pn.created_at DESC',
            default => 'pn.created_at DESC'
        };

        $sql = "
            SELECT
                pn.*,
                u.name as creator_name,
                (SELECT COUNT(*) FROM push_notification_clicks WHERE notification_id = pn.id) as click_count
            FROM push_notifications pn
            LEFT JOIN users u ON pn.created_by = u.id
            $whereClause
            ORDER BY $orderBy
            LIMIT ? OFFSET ?
        ";

        $stmt = $db->prepare($sql);
        $stmt->execute(array_merge($params, [$perPage, $offset]));
        $notifications = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // Get total count with filters
        $countSql = "SELECT COUNT(*) FROM push_notifications pn $whereClause";
        $countStmt = $db->prepare($countSql);
        $countStmt->execute($params);
        $totalCount = $countStmt->fetchColumn();
        $totalPages = ceil($totalCount / $perPage);

        // Get subscription stats
        $subscriptionCount = $db->query("SELECT COUNT(DISTINCT user_id) FROM push_subscriptions")->fetchColumn();
        $totalUsers = $db->query("SELECT COUNT(*) FROM users")->fetchColumn();

        // Get list of users for targeting
        $stmt = $db->query("
            SELECT id, name, email,
            (SELECT COUNT(*) FROM push_subscriptions WHERE user_id = users.id) as subscription_count
            FROM users
            ORDER BY name
        ");
        $users = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $this->notifications = $notifications;
        $this->page = $page;
        $this->perPage = $perPage;
        $this->totalPages = $totalPages;
        $this->totalCount = $totalCount;
        $this->subscriptionCount = $subscriptionCount;
        $this->totalUsers = $totalUsers;
        $this->users = $users;

        $this->build(__DIR__."/view/index.php");
    }

    /**
     * Delete push notifications
     * POST /admin/push/delete
     */
    #[AuditLog('push_notification_delete', includeParams: true)]
    #[AllowedMethods('POST')]
    public function delete() {
        $action = $_POST['action'] ?? '';
        $db = Database::getConnection();

        try {
            $db->beginTransaction();

            switch ($action) {
                case 'delete_single':
                    $id = (int)($_POST['single_id'] ?? 0);
                    if (!$id) {
                        throw new \Exception('Invalid notification ID');
                    }

                    // Delete clicks first (foreign key)
                    $db->prepare("DELETE FROM push_notification_clicks WHERE notification_id = ?")->execute([$id]);
                    // Delete notification
                    $db->prepare("DELETE FROM push_notifications WHERE id = ?")->execute([$id]);

                    $message = 'Notification deleted successfully';
                    break;

                case 'delete_selected':
                    $ids = $_POST['notification_ids'] ?? [];
                    if (empty($ids)) {
                        throw new \Exception('No notifications selected');
                    }

                    $ids = array_map('intval', $ids);
                    $placeholders = implode(',', array_fill(0, count($ids), '?'));

                    // Delete clicks first
                    $db->prepare("DELETE FROM push_notification_clicks WHERE notification_id IN ($placeholders)")->execute($ids);
                    // Delete notifications
                    $db->prepare("DELETE FROM push_notifications WHERE id IN ($placeholders)")->execute($ids);

                    $message = count($ids) . ' notification(s) deleted successfully';
                    break;

                case 'delete_all':
                    // Delete all clicks first
                    $db->exec("DELETE FROM push_notification_clicks");
                    // Delete all notifications
                    $db->exec("DELETE FROM push_notifications");

                    $message = 'All notification history deleted successfully';
                    break;

                default:
                    throw new \Exception('Invalid action');
            }

            $db->commit();
            $this->success($message);

            header("Location: /admin/push");
            exit;
        } catch (\Exception $e) {
            $db->rollBack();
            \Zero\Core\Console::error("Error deleting push notifications: " . $e->getMessage());
            $this->error('Failed to delete: ' . $e->getMessage());

            header("Location: /admin/push");
            exit;
        }
    }

}
