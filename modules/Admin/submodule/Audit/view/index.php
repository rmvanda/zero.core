<?php
// Audit::index() loads the filters, one page of events and the event names.
use Zero\Core\Module\Admin\Model\EventLog;

$filters = $this->filters;
$events = $this->events;
$page = (int) $events['page'];
$pages = (int) $events['pages'];
$perPage = (int) $events['perPage'];
$first = $events['total'] === 0 ? 0 : ($page - 1) * $perPage + 1;
$last = min($page * $perPage, (int) $events['total']);

// A link to another page, or to one more filter, that keeps the filters already set
$link = function (array $change) use ($filters): string {
    $query = array_filter(array_merge($filters, $change), fn($value) => $value !== '' && $value !== null);
    return '/admin/audit?' . http_build_query($query);
};
?>

<div class="content-wrapper">
    <header class="admin-head">
        <div>
            <h1>Audit Log</h1>
            <p class="admin-lede">What was done, by whom and from where. Select an event or a user in a row to narrow the list to it.</p>
        </div>
    </header>

    <?php if (!$this->installed): ?>
        <div class="admin-info-box">The entity tables are not installed on this site, so no event is recorded.</div>
</div>
    <?php return; endif; ?>

    <!-- Filter Form -->
    <div class="filter-container">
        <form method="get" action="/admin/audit" aria-label="Filter the events">
            <div class="filter-grid">
                <div class="admin-form-field">
                    <label for="event">Event</label>
                    <select name="event" id="event">
                        <option value="">All events</option>
                        <?php foreach ($this->eventNames as $name): ?>
                            <option value="<?= htmlspecialchars($name) ?>"<?= $filters['event'] === $name ? ' selected' : '' ?>><?= htmlspecialchars($name) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="admin-form-field">
                    <label for="user">User ID</label>
                    <input type="text" inputmode="numeric" name="user" id="user" placeholder="e.g., 5" value="<?= htmlspecialchars($filters['user']) ?>">
                </div>

                <div class="admin-form-field">
                    <label for="ip">IP address</label>
                    <input type="text" name="ip" id="ip" placeholder="e.g., 192.168" value="<?= htmlspecialchars($filters['ip']) ?>">
                </div>

                <div class="admin-form-field">
                    <label for="date_from">From</label>
                    <input type="date" name="date_from" id="date_from" value="<?= htmlspecialchars($filters['date_from']) ?>">
                </div>

                <div class="admin-form-field">
                    <label for="date_to">To</label>
                    <input type="date" name="date_to" id="date_to" value="<?= htmlspecialchars($filters['date_to']) ?>">
                </div>

                <div class="admin-form-field">
                    <label for="detail">Detail contains</label>
                    <input type="text" name="detail" id="detail" placeholder="e.g., allow.admin" value="<?= htmlspecialchars($filters['detail']) ?>">
                </div>

                <div class="admin-form-field">
                    <label for="per_page">Rows per page</label>
                    <select name="per_page" id="per_page">
                        <?php foreach (EventLog::PER_PAGE as $size): ?>
                            <option value="<?= $size ?>"<?= $perPage === $size ? ' selected' : '' ?>><?= $size ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="filter-actions">
                <a href="/admin/audit" class="btn-secondary">Clear filters</a>
                <button type="submit" class="btn-primary">Apply filters</button>
            </div>
        </form>
    </div>

    <!-- Events Table -->
    <?php if (!empty($events['rows'])): ?>
        <!-- Results Summary -->
        <p class="admin-note audit-summary">
            Showing <?= number_format($first) ?> to <?= number_format($last) ?> of <?= number_format((int) $events['total']) ?> events
        </p>

        <div class="admin-table-wrap">
        <table class="admin-table audit-table">
            <thead>
                <tr>
                    <th>When</th>
                    <th>Event</th>
                    <th>User</th>
                    <th>Detail</th>
                    <th>IP address</th>
                    <th class="num">ID</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($events['rows'] as $event): ?>
                <?php $userId = EventLog::userId($event['detail']); ?>
                <tr>
                    <td class="nowrap audit-when"><?= htmlspecialchars((string) $event['datetime']) ?></td>
                    <td>
                        <a class="event-badge" href="<?= htmlspecialchars($link(['event' => (string) $event['event'], 'page' => 1])) ?>"><?= htmlspecialchars((string) $event['event']) ?></a>
                    </td>
                    <td class="nowrap">
                        <?php if ($userId !== null): ?>
                            <a href="/admin/users/edit/<?= $userId ?>" title="This user's screen"><?= $userId ?></a>
                            <a class="audit-filter-link" href="<?= htmlspecialchars($link(['user' => (string) $userId, 'page' => 1])) ?>" title="Only this user's events">
                                <span class="material-symbols-outlined" aria-hidden="true">filter_alt</span>
                            </a>
                        <?php endif; ?>
                    </td>
                    <td class="audit-detail"><?= htmlspecialchars((string) $event['detail']) ?></td>
                    <td class="nowrap audit-ip"><?= htmlspecialchars((string) $event['ip']) ?></td>
                    <td class="num dim"><?= (int) $event['id'] ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>

        <!-- Pagination Controls -->
        <?php if ($pages > 1): ?>
        <nav class="pagination" aria-label="Pages of events">
            <div class="pagination-info">Page <?= $page ?> of <?= $pages ?></div>
            <div class="pagination-controls">
                <?php foreach (['First' => 1, 'Previous' => $page - 1] as $label => $to): ?>
                    <?php if ($page > 1): ?>
                        <a class="pagination-btn" href="<?= htmlspecialchars($link(['page' => $to])) ?>"><?= $label ?></a>
                    <?php else: ?>
                        <span class="pagination-btn disabled" aria-disabled="true"><?= $label ?></span>
                    <?php endif; ?>
                <?php endforeach; ?>

                <span class="pagination-current"><?= $page ?></span>

                <?php foreach (['Next' => $page + 1, 'Last' => $pages] as $label => $to): ?>
                    <?php if ($page < $pages): ?>
                        <a class="pagination-btn" href="<?= htmlspecialchars($link(['page' => $to])) ?>"><?= $label ?></a>
                    <?php else: ?>
                        <span class="pagination-btn disabled" aria-disabled="true"><?= $label ?></span>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
        </nav>
        <?php endif; ?>
    <?php else: ?>
        <div class="admin-empty">
            <span class="material-symbols-outlined" aria-hidden="true">search_off</span>
            <p>No event matches these filters. <a href="/admin/audit">Clear the filters</a> to see every event.</p>
        </div>
    <?php endif; ?>
</div>
