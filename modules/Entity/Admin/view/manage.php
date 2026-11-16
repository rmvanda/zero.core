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
        echo '<h1>Entity Not Found</h1>';
        echo '<p><a href="/admin/entity">&larr; Back to Entities</a></p>';
        echo '<div class="admin-info-box">The requested entity type was not found.</div>';
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

    <h1><?= $entityLabelCapitalized ?> Management</h1>
    <p><a href="/admin/entity">&larr; Back to Entities</a></p>

    <?php if (empty($schema)): ?>
        <div class="admin-info-box">
            No schema defined for this entity type. Please define the entity schema first.
        </div>
    <?php else: ?>
        <!-- Create New Entity Form -->
        <div class="admin-info-box entity-create-box">
            <h3>Create New <?= $entityLabelCapitalized ?></h3>
            <form method="POST" action="/admin/entity/manage/<?= $entityLabel ?>" class="admin-form-grid">
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
        </div>

        <!-- Results Summary -->
        <div class="admin-info-box">
            <?php if ($totalRecords > 0): ?>
                Showing <?= number_format(min((($page - 1) * $perPage) + 1, $totalRecords)) ?>
                to <?= number_format(min($page * $perPage, $totalRecords)) ?>
                of <?= number_format($totalRecords) ?> <?= $entityLabel ?> records
                <?php if ($totalPages > 1): ?>
                    (Page <?= $page ?> of <?= $totalPages ?>)
                <?php endif; ?>
            <?php else: ?>
                No <?= $entityLabel ?> records found.
            <?php endif; ?>
        </div>

        <!-- Pagination Controls (Top) -->
        <?php if ($totalPages > 1): ?>
        <div class="pagination">
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
        </div>
        <?php endif; ?>

        <!-- Bulk Actions -->
        <?php if (count($records) > 0): ?>
            <form method="POST" action="/admin/entity/delete" id="bulkDeleteForm">
                <input type="hidden" name="entity_type" value="<?= $entityLabel ?>">
                <div class="bulk-delete-bar">
                    <button type="submit" class="btn-small btn-danger">Delete Selected</button>
                </div>

                <!-- Entity Table -->
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th class="col-checkbox">
                                <input type="checkbox" id="selectAll">
                            </th>
                            <th>ID</th>
                            <?php foreach ($schema as $columnName): ?>
                                <th><?= htmlspecialchars(ucfirst($columnName)) ?></th>
                            <?php endforeach; ?>
                            <th class="col-actions">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($records as $record): ?>
                        <tr>
                            <td>
                                <input type="checkbox" name="entity_ids[]" value="<?= $record['_id'] ?>" class="row-checkbox">
                            </td>
                            <td class="nowrap"><?= htmlspecialchars($record['_id']) ?></td>
                            <?php foreach ($schema as $columnName): ?>
                                <td class="editable-cell"
                                    data-entity-id="<?= $record['_id'] ?>"
                                    data-entity-type="<?= $entityLabel ?>"
                                    data-attribute="<?= htmlspecialchars($columnName) ?>"
                                    data-value="<?= htmlspecialchars($record[$columnName] ?? '') ?>">
                                    <?= htmlspecialchars($record[$columnName] ?? '') ?>
                                </td>
                            <?php endforeach; ?>
                            <td>
                                <form method="POST" action="/admin/entity/delete" class="form-inline">
                                    <input type="hidden" name="entity_type" value="<?= $entityLabel ?>">
                                    <input type="hidden" name="entity_ids[]" value="<?= $record['_id'] ?>">
                                    <button type="submit" class="btn-small btn-danger">&times;</button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </form>

            <!-- Pagination Controls (Bottom) -->
            <?php if ($totalPages > 1): ?>
            <div class="pagination">
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
            </div>
            <?php endif; ?>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php $this->loadInlineAsset("Entity", "js", "manage.js"); ?>
