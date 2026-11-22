<div class="content-wrapper">
    <h1>Entities</h1>

    <?php if (!$this->tablesInstalled): ?>
    <div class="admin-info-box">The entity tables are not installed on this site.</div>
    <?php else: ?>
    <div class="admin-card-grid">
        <!-- Create Entity Card -->
        <a href="/admin/entity/create" class="btn-primary admin-card">
            <span class="material-symbols-outlined icon-lg">add_circle</span>
            <span class="admin-card-title">Create Entity</span>
        </a>

        <!-- Event Logs Card -->
        <a href="/admin/entity/events" class="btn-primary admin-card">
            <span class="material-symbols-outlined icon-lg">list_alt</span>
            <span class="admin-card-title">Event Logs</span>
        </a>

        <?php foreach ($this->entityTypes as $type): ?>
        <a href="/admin/entity/manage/<?= htmlspecialchars($type['label']) ?>" class="btn-primary admin-card">
            <span class="material-symbols-outlined icon-lg">
                <?= $type['label'] === 'event' ? 'event' : 'person' ?>
            </span>
            <span class="admin-card-title text-capitalize">
                <?= htmlspecialchars($type['label']) ?>s
            </span>
        </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>
