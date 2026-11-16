<div class="content-wrapper">
    <h1>Admin Panel</h1>
    <p>Manage users and the modules that offer an admin screen</p>

    <h2 class="mt-2">Users and access</h2>
    <div class="admin-card-grid">
        <a href="/admin/manage/users" class="btn-primary admin-card">
            <span class="material-symbols-outlined icon-lg">group</span>
            <span class="admin-card-title">User Management</span>
        </a>
        <a href="/admin/manage/groups" class="btn-primary admin-card">
            <span class="material-symbols-outlined icon-lg">groups</span>
            <span class="admin-card-title">Group Management</span>
        </a>
        <a href="/admin/firewall" class="btn-primary admin-card">
            <span class="material-symbols-outlined icon-lg">security</span>
            <span class="admin-card-title">Firewall</span>
        </a>
    </div>

    <h2 class="mt-2">Modules</h2>
    <div class="admin-card-grid">
        <?php if (!empty($this->adminModules)): ?>
        <?php foreach ($this->adminModules as $module): ?>
        <a href="<?= htmlspecialchars($module['url']) ?>" class="btn-primary admin-card">
            <span class="material-symbols-outlined icon-lg"><?= htmlspecialchars($module['icon']) ?></span>
            <span class="admin-card-title"><?= htmlspecialchars($module['label']) ?></span>
            <?php if (!empty($module['description'])): ?>
            <span class="admin-card-desc"><?= htmlspecialchars($module['description']) ?></span>
            <?php endif; ?>
        </a>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>
