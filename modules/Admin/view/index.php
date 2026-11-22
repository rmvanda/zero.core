<?php
// Admin::index() loads Admin's own screens and the modules that offer one.
$groups = [
    'Users and access' => $this->ownScreens ?? [],
    'Modules' => $this->adminModules ?? [],
];
?>
<div class="content-wrapper admin-dashboard">
    <header class="admin-head">
        <div>
            <h1>Admin</h1>
            <p class="admin-lede">Manage users and the modules that offer an admin screen</p>
        </div>
    </header>

    <?php foreach ($groups as $heading => $cards): ?>
    <?php if (empty($cards)) { continue; } ?>
    <section class="admin-section">
        <h2><?= htmlspecialchars($heading) ?></h2>
        <div class="admin-card-grid">
            <?php foreach ($cards as $card): ?>
            <a href="<?= htmlspecialchars($card['url']) ?>" class="admin-card">
                <span class="material-symbols-outlined admin-card-icon" aria-hidden="true"><?= htmlspecialchars($card['icon']) ?></span>
                <span class="admin-card-text">
                    <span class="admin-card-title"><?= htmlspecialchars($card['label']) ?></span>
                    <?php if (!empty($card['description'])): ?>
                    <span class="admin-card-desc"><?= htmlspecialchars($card['description']) ?></span>
                    <?php endif; ?>
                </span>
            </a>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endforeach; ?>
</div>
