<div class="content-wrapper">
    <header class="admin-head">
        <div>
            <h1>Entities</h1>
            <p class="admin-lede">The types of entity this site stores. Open a type to see and edit its records.</p>
        </div>
        <?php if ($this->tablesInstalled): ?>
        <div class="admin-head-actions">
            <!-- Create Entity -->
            <a href="/admin/entity/create" class="btn-primary">
                <span class="material-symbols-outlined" aria-hidden="true">add</span>
                New entity type
            </a>
        </div>
        <?php endif; ?>
    </header>

    <?php if (!$this->tablesInstalled): ?>
    <div class="admin-info-box">The entity tables are not installed on this site.</div>
    <?php elseif (empty($this->entityTypes)): ?>
    <div class="admin-empty">
        <span class="material-symbols-outlined" aria-hidden="true">database</span>
        <p>No entity type is defined yet. <a href="/admin/entity/create">Create the first one</a>.</p>
    </div>
    <?php else: ?>
    <div class="admin-card-grid">
        <?php foreach ($this->entityTypes as $type): ?>
        <a href="/admin/entity/manage/<?= htmlspecialchars($type['label']) ?>" class="admin-card">
            <span class="material-symbols-outlined admin-card-icon" aria-hidden="true">
                <?= $type['label'] === 'event' ? 'event' : 'person' ?>
            </span>
            <span class="admin-card-text">
                <span class="admin-card-title">
                    <?= htmlspecialchars(ucfirst($type['label'])) ?>s
                </span>
                <span class="admin-card-desc">See and edit the records of this type</span>
            </span>
        </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>
