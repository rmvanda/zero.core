<?php $this->loadInlineAsset("Entity", "css", "manage.css"); ?>
<div class="content-wrapper" data-entity-type="<?= htmlspecialchars($this->entityType['label'] ?? '') ?>">
    <?php
    // Get entity type from controller
    $entityType = $this->entityType ?? null;
    $schema = $this->schema ?? [];
    $records = $this->records ?? [];
    $totalRecords = $this->totalRecords ?? 0;
    $page = $this->page ?? 1;
    $perPage = $this->perPage ?? 25;
    $totalPages = $this->totalPages ?? 1;

    if (!$entityType) {
        echo '<header class="admin-head"><div><h1>Entity not found</h1></div></header>';
        echo '<div class="admin-info-box">The requested entity type was not found. <a href="/admin/entity">See every entity type</a>.</div>';
        echo '</div>';
        return;
    }

    $entityLabel = htmlspecialchars($entityType['label']);
    $entityLabelCapitalized = ucfirst($entityLabel);

    // Build query string for pagination links
    function buildQueryString($page, $perPage) {
        $params = $_GET;
        $params['page'] = $page;
        $params['per_page'] = $perPage;
        return '?' . http_build_query($params);
    }
    ?>

    <header class="admin-head">
        <div>
            <h1><?= $entityLabelCapitalized ?> management</h1>
            <p class="admin-lede">The records of the <code><?= $entityLabel ?></code> entity type. Select a cell to change its value.</p>
        </div>
    </header>

    <?php if (empty($schema)): ?>
        <div class="admin-info-box">
            No schema is defined for this entity type. Define the entity schema first.
        </div>
    <?php else: ?>
        <!-- Create New Entity Form -->
        <section class="admin-section entity-create">
            <h2>New <?= $entityLabel ?></h2>
            <form method="POST" action="/admin/entity/manage/<?= $entityLabel ?>" class="admin-panel entity-create-form">
                <?php foreach ($schema as $attrName): ?>
                    <div class="admin-form-field">
                        <label for="attr_<?= htmlspecialchars($attrName) ?>">
                            <?= htmlspecialchars(ucfirst($attrName)) ?>
                        </label>
                        <input type="text"
                               id="attr_<?= htmlspecialchars($attrName) ?>"
                               name="<?= htmlspecialchars($attrName) ?>"
                               placeholder="Enter <?= htmlspecialchars($attrName) ?>">
                    </div>
                <?php endforeach; ?>
                <div class="entity-form-submit-row">
                    <button type="submit" class="btn-primary">Create <?= $entityLabelCapitalized ?></button>
                    <button type="reset" class="btn-secondary">Clear</button>
                </div>
            </form>
        </section>

        <section class="admin-section">
        <!-- Results Summary -->
        <div class="admin-section-head">
            <h2>Records <span class="admin-count"><?= number_format($totalRecords) ?></span></h2>
            <?php if ($totalRecords > 0): ?>
            <p class="admin-note">
                Showing <?= number_format(min((($page - 1) * $perPage) + 1, $totalRecords)) ?>
                to <?= number_format(min($page * $perPage, $totalRecords)) ?>
                of <?= number_format($totalRecords) ?> <?= $entityLabel ?> records
                <?php if ($totalPages > 1): ?>
                    (Page <?= $page ?> of <?= $totalPages ?>)
                <?php endif; ?>
            </p>
            <?php endif; ?>
        </div>
        <?php if ($totalRecords <= 0): ?>
        <div class="admin-empty">
            <span class="material-symbols-outlined" aria-hidden="true">inbox</span>
            <p>There are no <?= $entityLabel ?> records yet. Create the first one with the form above.</p>
        </div>
        <?php endif; ?>

        <!-- Pagination Controls (Top) -->
        <?php if ($totalPages > 1): ?>
        <nav class="pagination" aria-label="Pages of records">
            <div class="pagination-info">
                Page <?= $page ?> of <?= $totalPages ?>
            </div>
            <div class="pagination-controls">
                <a href="<?= buildQueryString(1, $perPage) ?>"
                   class="pagination-btn <?= $page <= 1 ? 'disabled' : '' ?>">
                    First
                </a>

                <a href="<?= buildQueryString($page - 1, $perPage) ?>"
                   class="pagination-btn <?= $page <= 1 ? 'disabled' : '' ?>">
                    Previous
                </a>

                <span class="pagination-current">
                    <?= $page ?>
                </span>

                <a href="<?= buildQueryString($page + 1, $perPage) ?>"
                   class="pagination-btn <?= $page >= $totalPages ? 'disabled' : '' ?>">
                    Next
                </a>

                <a href="<?= buildQueryString($totalPages, $perPage) ?>"
                   class="pagination-btn <?= $page >= $totalPages ? 'disabled' : '' ?>">
                    Last
                </a>
            </div>
        </nav>
        <?php endif; ?>

        <!-- Bulk Actions -->
        <?php if (count($records) > 0): ?>
            <form method="POST" action="/admin/entity/delete" id="bulkDeleteForm">
                <input type="hidden" name="entity_type" value="<?= $entityLabel ?>">
                <div class="bulk-delete-bar">
                    <button type="submit" class="btn-small btn-danger">Delete selected</button>
                </div>

                <!-- Entity Table -->
                <div class="admin-table-wrap">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th class="col-checkbox">
                                <input type="checkbox" id="selectAll" aria-label="Select every record on this page">
                            </th>
                            <th>ID</th>
                            <?php foreach ($schema as $columnName): ?>
                                <th><?= htmlspecialchars(ucfirst($columnName)) ?></th>
                            <?php endforeach; ?>
                            <th class="col-actions text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($records as $record): ?>
                        <tr>
                            <td>
                                <input type="checkbox" name="entity_ids[]" value="<?= $record['_id'] ?>" class="row-checkbox" aria-label="Select record <?= htmlspecialchars($record['_id']) ?>">
                            </td>
                            <td class="nowrap dim"><?= htmlspecialchars($record['_id']) ?></td>
                            <?php foreach ($schema as $columnName): ?>
                                <td class="editable-cell"
                                    data-entity-id="<?= $record['_id'] ?>"
                                    data-entity-type="<?= $entityLabel ?>"
                                    data-attribute="<?= htmlspecialchars($columnName) ?>"
                                    data-value="<?= htmlspecialchars($record[$columnName] ?? '') ?>">
                                    <?= htmlspecialchars($record[$columnName] ?? '') ?>
                                </td>
                            <?php endforeach; ?>
                            <td class="actions-cell">
                                <form method="POST" action="/admin/entity/delete" class="form-inline">
                                    <input type="hidden" name="entity_type" value="<?= $entityLabel ?>">
                                    <input type="hidden" name="entity_ids[]" value="<?= $record['_id'] ?>">
                                    <button type="submit" class="btn-small btn-danger">Delete</button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            </form>

            <!-- Pagination Controls (Bottom) -->
            <?php if ($totalPages > 1): ?>
            <nav class="pagination" aria-label="Pages of records">
                <div class="pagination-info">
                    Page <?= $page ?> of <?= $totalPages ?>
                </div>
                <div class="pagination-controls">
                    <a href="<?= buildQueryString(1, $perPage) ?>"
                       class="pagination-btn <?= $page <= 1 ? 'disabled' : '' ?>">
                        First
                    </a>

                    <a href="<?= buildQueryString($page - 1, $perPage) ?>"
                       class="pagination-btn <?= $page <= 1 ? 'disabled' : '' ?>">
                        Previous
                    </a>

                    <span class="pagination-current">
                        <?= $page ?>
                    </span>

                    <a href="<?= buildQueryString($page + 1, $perPage) ?>"
                       class="pagination-btn <?= $page >= $totalPages ? 'disabled' : '' ?>">
                        Next
                    </a>

                    <a href="<?= buildQueryString($totalPages, $perPage) ?>"
                       class="pagination-btn <?= $page >= $totalPages ? 'disabled' : '' ?>">
                        Last
                    </a>
                </div>
            </nav>
            <?php endif; ?>
        <?php endif; ?>
        </section>
    <?php endif; ?>
</div>

<?php $this->loadInlineAsset("Entity", "js", "manage.js"); ?>
