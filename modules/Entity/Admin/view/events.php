<div class="content-wrapper">
    <h1>Event Log Management</h1>
    <p><a href="/admin/entity">&larr; Back to Entities</a></p>

    <?php if (!$this->tablesInstalled) { ?>
    <div class="admin-info-box">The entity tables are not installed on this site.</div>
</div>
    <?php return; } ?>

    <?php
    $db = \Zero\Core\Database::getConnection();

    // Get filter parameters from query string
    $filterEvent = $_GET['event'] ?? '';
    $filterIp = $_GET['ip'] ?? '';
    $filterDateFrom = $_GET['date_from'] ?? '';
    $filterDateTo = $_GET['date_to'] ?? '';
    $filterDetail = $_GET['detail'] ?? '';

    // Pagination parameters
    $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
    $perPage = isset($_GET['per_page']) ? max(1, min(100, intval($_GET['per_page']))) : 25;
    $offset = ($page - 1) * $perPage;

    // Build WHERE clause
    $whereClauses = [];
    $params = [];

    if (!empty($filterEvent)) {
        $whereClauses[] = "event = ?";
        $params[] = $filterEvent;
    }

    if (!empty($filterIp)) {
        $whereClauses[] = "ip LIKE ?";
        $params[] = "%{$filterIp}%";
    }

    if (!empty($filterDateFrom)) {
        $whereClauses[] = "datetime >= ?";
        $params[] = $filterDateFrom . ' 00:00:00';
    }

    if (!empty($filterDateTo)) {
        $whereClauses[] = "datetime <= ?";
        $params[] = $filterDateTo . ' 23:59:59';
    }

    if (!empty($filterDetail)) {
        $whereClauses[] = "detail LIKE ?";
        $params[] = "%{$filterDetail}%";
    }

    $whereSQL = count($whereClauses) > 0 ? 'WHERE ' . implode(' AND ', $whereClauses) : '';

    // Get total count for pagination
    $countSQL = "SELECT COUNT(*) as total FROM event_view {$whereSQL}";
    $countStmt = $db->prepare($countSQL);
    $countStmt->execute($params);
    $totalRecords = $countStmt->fetch()['total'];
    $totalPages = ceil($totalRecords / $perPage);

    // Get paginated results
    $dataSQL = "SELECT * FROM event_view {$whereSQL} ORDER BY datetime DESC, id DESC LIMIT ? OFFSET ?";
    $dataParams = array_merge($params, [$perPage, $offset]);
    $dataStmt = $db->prepare($dataSQL);
    $dataStmt->execute($dataParams);
    $events = $dataStmt->fetchAll();

    // Get distinct event types for filter dropdown
    $eventTypesStmt = $db->query("SELECT DISTINCT event FROM event_view WHERE event IS NOT NULL ORDER BY event");
    $eventTypes = $eventTypesStmt->fetchAll(\PDO::FETCH_COLUMN);

    // Build query string for pagination links (preserve filters)
    function buildQueryString($page, $perPage) {
        $params = $_GET;
        $params['page'] = $page;
        $params['per_page'] = $perPage;
        return '?' . http_build_query($params);
    }
    ?>

    <!-- Filter Form -->
    <div class="filter-container">
        <h3>Filter Events</h3>
        <form method="get" action="/admin/entity/events">
            <div class="filter-grid">
                <div class="admin-form-field">
                    <label for="event">Event Type</label>
                    <select name="event" id="event">
                        <option value="">All Events</option>
                        <?php foreach ($eventTypes as $type): ?>
                            <option value="<?= htmlspecialchars($type) ?>"
                                    <?= $filterEvent === $type ? 'selected' : '' ?>>
                                <?= htmlspecialchars($type) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="admin-form-field">
                    <label for="ip">IP Address</label>
                    <input type="text"
                           name="ip"
                           id="ip"
                           placeholder="e.g., 192.168"
                           value="<?= htmlspecialchars($filterIp) ?>">
                </div>

                <div class="admin-form-field">
                    <label for="date_from">Date From</label>
                    <input type="date"
                           name="date_from"
                           id="date_from"
                           value="<?= htmlspecialchars($filterDateFrom) ?>">
                </div>

                <div class="admin-form-field">
                    <label for="date_to">Date To</label>
                    <input type="date"
                           name="date_to"
                           id="date_to"
                           value="<?= htmlspecialchars($filterDateTo) ?>">
                </div>

                <div class="admin-form-field">
                    <label for="detail">Detail (search)</label>
                    <input type="text"
                           name="detail"
                           id="detail"
                           placeholder="Search details..."
                           value="<?= htmlspecialchars($filterDetail) ?>">
                </div>

                <div class="admin-form-field">
                    <label for="per_page">Per Page</label>
                    <select name="per_page" id="per_page">
                        <option value="25" <?= $perPage === 25 ? 'selected' : '' ?>>25</option>
                        <option value="50" <?= $perPage === 50 ? 'selected' : '' ?>>50</option>
                        <option value="100" <?= $perPage === 100 ? 'selected' : '' ?>>100</option>
                    </select>
                </div>
            </div>

            <div class="filter-actions">
                <a href="/admin/entity/events" class="pagination-btn">Clear Filters</a>
                <button type="submit" class="pagination-btn">Apply Filters</button>
            </div>
        </form>
    </div>

    <!-- Results Summary -->
    <div class="admin-info-box">
        Showing <?= number_format(min($offset + 1, $totalRecords)) ?>
        to <?= number_format(min($offset + $perPage, $totalRecords)) ?>
        of <?= number_format($totalRecords) ?> events
        <?php if ($totalPages > 1): ?>
            (Page <?= $page ?> of <?= $totalPages ?>)
        <?php endif; ?>
    </div>

    <!-- Events Table -->
    <?php if (count($events) > 0): ?>
        <table class="admin-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Date & Time</th>
                    <th>Event Type</th>
                    <th>Detail</th>
                    <th>IP Address</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($events as $event): ?>
                <tr>
                    <td class="nowrap"><?= htmlspecialchars($event['id']) ?></td>
                    <td class="nowrap"><?= htmlspecialchars($event['datetime']) ?></td>
                    <td>
                        <span class="event-badge">
                            <?= htmlspecialchars($event['event']) ?>
                        </span>
                    </td>
                    <td><?= htmlspecialchars($event['detail']) ?></td>
                    <td class="nowrap"><?= htmlspecialchars($event['ip']) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <!-- Pagination Controls -->
        <?php if ($totalPages > 1): ?>
        <div class="pagination">
            <div class="pagination-info">
                Page <?= $page ?> of <?= $totalPages ?>
            </div>
            <div class="pagination-controls">
                <a href="<?= buildQueryString(1, $perPage) ?>"
                   class="pagination-btn <?= $page <= 1 ? 'disabled' : '' ?>"
                   >
                    First
                </a>

                <a href="<?= buildQueryString($page - 1, $perPage) ?>"
                   class="pagination-btn <?= $page <= 1 ? 'disabled' : '' ?>"
                   >
                    Previous
                </a>

                <span class="pagination-current">
                    <?= $page ?>
                </span>

                <a href="<?= buildQueryString($page + 1, $perPage) ?>"
                   class="pagination-btn <?= $page >= $totalPages ? 'disabled' : '' ?>"
                   >
                    Next
                </a>

                <a href="<?= buildQueryString($totalPages, $perPage) ?>"
                   class="pagination-btn <?= $page >= $totalPages ? 'disabled' : '' ?>"
                   >
                    Last
                </a>
            </div>
        </div>
        <?php endif; ?>
    <?php else: ?>
        <div class="admin-info-box">
            No events found matching the current filters.
        </div>
    <?php endif; ?>
</div>
