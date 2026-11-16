<?php $this->loadInlineAsset('Push', 'css', 'push.css'); ?>
<div class="content-wrapper">
    <h1>Push Notifications</h1>

    <div class="stats-row">
        <div class="stat-card">
            <span class="material-symbols-outlined">notifications_active</span>
            <div>
                <div class="stat-value"><?= $this->subscriptionCount ?></div>
                <div class="stat-label">Subscribed Users</div>
            </div>
        </div>
        <div class="stat-card">
            <span class="material-symbols-outlined">group</span>
            <div>
                <div class="stat-value"><?= $this->totalUsers ?></div>
                <div class="stat-label">Total Users</div>
            </div>
        </div>
        <div class="stat-card">
            <span class="material-symbols-outlined">campaign</span>
            <div>
                <div class="stat-value"><?= $this->totalCount ?></div>
                <div class="stat-label">Notifications Sent</div>
            </div>
        </div>
    </div>

    <h2>Send Push Notification</h2>
    <form method="POST" action="/admin/push" class="push-notification-form">
        <div class="form-group">
            <label for="title">Title *</label>
            <input type="text" id="title" name="title" required maxlength="255" placeholder="Notification title">
        </div>

        <div class="form-group">
            <label for="body">Message *</label>
            <textarea id="body" name="body" required rows="4" placeholder="Notification message"></textarea>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label for="icon">Icon URL</label>
                <input type="text" id="icon" name="icon" value="/assets/img/logo.png" placeholder="Icon URL">
            </div>

            <div class="form-group">
                <label for="url">Destination URL</label>
                <input type="text" id="url" name="url" value="/" placeholder="URL to open on click">
            </div>
        </div>

        <div class="form-group">
            <label>Target Audience *</label>
            <div class="radio-group">
                <label>
                    <input type="radio" name="target_type" value="all" checked>
                    All Subscribed Users (<?= $this->subscriptionCount ?>)
                </label>
                <label>
                    <input type="radio" name="target_type" value="specific">
                    Specific Users
                </label>
            </div>
        </div>

        <div id="user-select-group" class="form-group hidden">
            <label for="user_ids">Select Users</label>
            <select id="user_ids" name="user_ids[]" multiple size="10" class="user-multiselect">
                <?php foreach ($this->users as $user): ?>
                    <?php if ($user['subscription_count'] > 0): ?>
                        <option value="<?= $user['id'] ?>">
                            <?= htmlspecialchars($user['name']) ?>
                            (<?= htmlspecialchars($user['email']) ?>)
                            - <?= $user['subscription_count'] ?> device(s)
                        </option>
                    <?php endif; ?>
                <?php endforeach; ?>
            </select>
            <small>Hold Ctrl/Cmd to select multiple users. Only users with active subscriptions are shown.</small>
        </div>

        <button type="submit" class="btn-primary">
            <span class="material-symbols-outlined">send</span>
            Send Push Notification
        </button>
    </form>

    <h2 class="mt-2">Notification History</h2>

    <?php if (empty($this->notifications)): ?>
        <p>No notifications sent yet.</p>
    <?php else: ?>
        <!-- Search and Filter Controls -->
        <div class="filter-container">
            <form method="GET" action="/admin/push" class="filter-grid" id="filter-form">
                <div class="admin-form-field">
                    <label for="search">Search</label>
                    <input type="text" id="search" name="search" value="<?= htmlspecialchars($_GET['search'] ?? '') ?>" placeholder="Title or body...">
                </div>
                <div class="admin-form-field">
                    <label for="target_filter">Target Type</label>
                    <select id="target_filter" name="target_filter">
                        <option value="">All</option>
                        <option value="all" <?= ($_GET['target_filter'] ?? '') === 'all' ? 'selected' : '' ?>>All Users</option>
                        <option value="specific" <?= ($_GET['target_filter'] ?? '') === 'specific' ? 'selected' : '' ?>>Specific Users</option>
                    </select>
                </div>
                <div class="admin-form-field">
                    <label for="sort">Sort By</label>
                    <select id="sort" name="sort">
                        <option value="date_desc" <?= ($_GET['sort'] ?? 'date_desc') === 'date_desc' ? 'selected' : '' ?>>Date (Newest)</option>
                        <option value="date_asc" <?= ($_GET['sort'] ?? '') === 'date_asc' ? 'selected' : '' ?>>Date (Oldest)</option>
                        <option value="recipients_desc" <?= ($_GET['sort'] ?? '') === 'recipients_desc' ? 'selected' : '' ?>>Recipients (High to Low)</option>
                        <option value="clicks_desc" <?= ($_GET['sort'] ?? '') === 'clicks_desc' ? 'selected' : '' ?>>Clicks (High to Low)</option>
                    </select>
                </div>
            </form>
            <div class="filter-actions">
                <button type="submit" form="filter-form" class="btn-small">Apply Filters</button>
                <a href="/admin/push" class="btn-small">Clear</a>
            </div>
        </div>

        <!-- Bulk Actions -->
        <form method="POST" action="/admin/push/delete" id="bulk-delete-form">
            <div class="bulk-actions">
                <button type="button" id="toggle-all-btn" class="btn-small">Select All</button>
                <button type="submit" name="action" value="delete_selected" class="btn-small btn-danger">Delete Selected</button>
                <button type="submit" name="action" value="delete_all" class="btn-small btn-danger">Delete All</button>
            </div>

            <table class="admin-table">
                <thead>
                    <tr>
                        <th width="40"><input type="checkbox" id="select-all"></th>
                        <th>Date</th>
                        <th>Title</th>
                        <th>Body</th>
                        <th>Target</th>
                        <th>Recipients</th>
                        <th>Clicks</th>
                        <th>Created By</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($this->notifications as $notification): ?>
                    <tr>
                        <td><input type="checkbox" name="notification_ids[]" value="<?= $notification['id'] ?>" class="notification-checkbox"></td>
                        <td><?= date('M j, Y g:i A', strtotime($notification['created_at'])) ?></td>
                        <td><?= htmlspecialchars($notification['title']) ?></td>
                        <td class="text-truncate"><?= htmlspecialchars($notification['body']) ?></td>
                        <td>
                            <span class="badge badge-<?= $notification['target_type'] === 'all' ? 'primary' : 'secondary' ?>">
                                <?= ucfirst($notification['target_type']) ?>
                            </span>
                        </td>
                        <td><?= $notification['recipient_count'] ?></td>
                        <td>
                            <?= $notification['click_count'] ?>
                            <?php if ($notification['recipient_count'] > 0): ?>
                                <small>(<?= round(($notification['click_count'] / $notification['recipient_count']) * 100) ?>%)</small>
                            <?php endif; ?>
                        </td>
                        <td><?= htmlspecialchars($notification['creator_name'] ?? 'Unknown') ?></td>
                        <td>
                            <button type="submit" name="action" value="delete_single" class="btn-small btn-danger btn-single-delete" data-notification-id="<?= $notification['id'] ?>">
                                Delete
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <input type="hidden" name="single_id" id="single-delete-id">
        </form>

        <?php if ($this->totalPages > 1): ?>
        <div class="pagination">
            <?php if ($this->page > 1): ?>
                <a href="?page=<?= $this->page - 1 ?>" class="btn-secondary">Previous</a>
            <?php endif; ?>

            <span>Page <?= $this->page ?> of <?= $this->totalPages ?></span>

            <?php if ($this->page < $this->totalPages): ?>
                <a href="?page=<?= $this->page + 1 ?>" class="btn-secondary">Next</a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php $this->loadInlineAsset('Push', 'js', 'push.js'); ?>
