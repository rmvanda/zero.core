<?php $this->loadInlineAsset('Push', 'css', 'push.css'); ?>
<div class="content-wrapper">
    <header class="admin-head">
        <div>
            <h1>Push Notifications</h1>
            <p class="admin-lede">Send a notification to the devices people have subscribed, and review what has been sent.</p>
        </div>
    </header>

    <div class="stats-row">
        <div class="stat-card">
            <span class="material-symbols-outlined" aria-hidden="true">notifications_active</span>
            <div>
                <div class="stat-value"><?= $this->subscriptionCount ?></div>
                <div class="stat-label">Subscribed users</div>
            </div>
        </div>
        <div class="stat-card">
            <span class="material-symbols-outlined" aria-hidden="true">group</span>
            <div>
                <div class="stat-value"><?= $this->totalUsers ?></div>
                <div class="stat-label">Total users</div>
            </div>
        </div>
        <div class="stat-card">
            <span class="material-symbols-outlined" aria-hidden="true">campaign</span>
            <div>
                <div class="stat-value"><?= $this->totalCount ?></div>
                <div class="stat-label">Notifications sent</div>
            </div>
        </div>
    </div>

    <section class="admin-section">
    <h2>Send a notification</h2>
    <form method="POST" action="/admin/push" class="admin-panel push-notification-form">
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
            <label id="target-audience-label">Target audience *</label>
            <div class="radio-group" role="radiogroup" aria-labelledby="target-audience-label">
                <label>
                    <input type="radio" name="target_type" value="all" checked>
                    All subscribed users (<?= $this->subscriptionCount ?>)
                </label>
                <label>
                    <input type="radio" name="target_type" value="specific">
                    Specific users
                </label>
            </div>
        </div>

        <div id="user-select-group" class="form-group hidden">
            <label for="user_ids">Select users</label>
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

        <div class="form-actions">
            <button type="submit" class="btn-primary">
                <span class="material-symbols-outlined" aria-hidden="true">send</span>
                Send notification
            </button>
        </div>
    </form>
    </section>

    <section class="admin-section">
    <h2>Notification history</h2>

    <?php if (empty($this->notifications)): ?>
        <div class="admin-empty">
            <span class="material-symbols-outlined" aria-hidden="true">notifications_off</span>
            <?php if (!empty($_GET['search']) || !empty($_GET['target_filter'])): ?>
            <p>No notification matches these filters. <a href="/admin/push">Clear the filters</a> to see every notification.</p>
            <?php else: ?>
            <p>No notification has been sent yet. Send the first one with the form above.</p>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <!-- Search and Filter Controls -->
        <div class="filter-container">
            <form method="GET" action="/admin/push" class="filter-grid" id="filter-form">
                <div class="admin-form-field">
                    <label for="search">Search</label>
                    <input type="text" id="search" name="search" value="<?= htmlspecialchars($_GET['search'] ?? '') ?>" placeholder="Title or body...">
                </div>
                <div class="admin-form-field">
                    <label for="target_filter">Target type</label>
                    <select id="target_filter" name="target_filter">
                        <option value="">All</option>
                        <option value="all" <?= ($_GET['target_filter'] ?? '') === 'all' ? 'selected' : '' ?>>All users</option>
                        <option value="specific" <?= ($_GET['target_filter'] ?? '') === 'specific' ? 'selected' : '' ?>>Specific users</option>
                    </select>
                </div>
                <div class="admin-form-field">
                    <label for="sort">Sort by</label>
                    <select id="sort" name="sort">
                        <option value="date_desc" <?= ($_GET['sort'] ?? 'date_desc') === 'date_desc' ? 'selected' : '' ?>>Date (newest)</option>
                        <option value="date_asc" <?= ($_GET['sort'] ?? '') === 'date_asc' ? 'selected' : '' ?>>Date (oldest)</option>
                        <option value="recipients_desc" <?= ($_GET['sort'] ?? '') === 'recipients_desc' ? 'selected' : '' ?>>Recipients (high to low)</option>
                        <option value="clicks_desc" <?= ($_GET['sort'] ?? '') === 'clicks_desc' ? 'selected' : '' ?>>Clicks (high to low)</option>
                    </select>
                </div>
            </form>
            <div class="filter-actions">
                <a href="/admin/push" class="btn-secondary">Clear filters</a>
                <button type="submit" form="filter-form" class="btn-primary">Apply filters</button>
            </div>
        </div>

        <!-- Bulk Actions -->
        <form method="POST" action="/admin/push/delete" id="bulk-delete-form">
            <div class="bulk-actions">
                <button type="button" id="toggle-all-btn" class="btn-small">Select all</button>
                <button type="submit" name="action" value="delete_selected" class="btn-small btn-danger">Delete selected</button>
                <button type="submit" name="action" value="delete_all" class="btn-small btn-danger">Delete all</button>
            </div>

            <div class="admin-table-wrap">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th class="col-select"><input type="checkbox" id="select-all" aria-label="Select every notification on this page"></th>
                        <th>Date</th>
                        <th>Title</th>
                        <th>Body</th>
                        <th>Target</th>
                        <th class="num">Recipients</th>
                        <th class="num">Clicks</th>
                        <th>Created by</th>
                        <th class="text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($this->notifications as $notification): ?>
                    <tr>
                        <td><input type="checkbox" name="notification_ids[]" value="<?= $notification['id'] ?>" class="notification-checkbox" aria-label="Select this notification"></td>
                        <td class="nowrap dim"><?= date('M j, Y g:i A', strtotime($notification['created_at'])) ?></td>
                        <td><?= htmlspecialchars($notification['title']) ?></td>
                        <td class="text-truncate"><?= htmlspecialchars($notification['body']) ?></td>
                        <td>
                            <span class="badge badge-<?= $notification['target_type'] === 'all' ? 'primary' : 'secondary' ?>">
                                <?= ucfirst($notification['target_type']) ?>
                            </span>
                        </td>
                        <td class="num"><?= $notification['recipient_count'] ?></td>
                        <td class="num nowrap">
                            <?= $notification['click_count'] ?>
                            <?php if ($notification['recipient_count'] > 0): ?>
                                <small class="push-click-rate">(<?= round(($notification['click_count'] / $notification['recipient_count']) * 100) ?>%)</small>
                            <?php endif; ?>
                        </td>
                        <td class="dim"><?= htmlspecialchars($notification['creator_name'] ?? 'Unknown') ?></td>
                        <td class="actions-cell">
                            <button type="submit" name="action" value="delete_single" class="btn-small btn-danger btn-single-delete" data-notification-id="<?= $notification['id'] ?>">
                                Delete
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <input type="hidden" name="single_id" id="single-delete-id">
        </form>

        <?php if ($this->totalPages > 1): ?>
        <nav class="pagination" aria-label="Pages of notifications">
            <div class="pagination-info">Page <?= $this->page ?> of <?= $this->totalPages ?></div>
            <div class="pagination-controls">
            <?php if ($this->page > 1): ?>
                <a href="?page=<?= $this->page - 1 ?>" class="pagination-btn">Previous</a>
            <?php endif; ?>

            <span class="pagination-current"><?= $this->page ?></span>

            <?php if ($this->page < $this->totalPages): ?>
                <a href="?page=<?= $this->page + 1 ?>" class="pagination-btn">Next</a>
            <?php endif; ?>
            </div>
        </nav>
        <?php endif; ?>
    <?php endif; ?>
    </section>
</div>

<?php $this->loadInlineAsset('Push', 'js', 'push.js'); ?>
