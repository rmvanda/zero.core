<?php
// Shared provider brand icons (also used by the login buttons + /user/profile).
if ($brandPartial = $this->framePiecePath('_oauth-brand')) {
    require_once $brandPartial;
}

$total           = count($this->providers);
$enabledCount    = count(array_filter($this->providers, fn($p) => $p['enabled']));
$configuredCount = count(array_filter($this->providers, fn($p) => $p['configured']));
$connTotal       = array_sum($this->connectionCounts);
?>
<div class="content-wrapper">
    <div class="page-header flex justify-between align-center">
        <h1>
            <span class="material-symbols-outlined">hub</span>
            OAuth Providers
        </h1>
        <a href="/admin" class="btn-secondary">
            <span class="material-symbols-outlined">arrow_back</span>
            Admin
        </a>
    </div>

    <div class="stats-row">
        <div class="stat-card">
            <span class="material-symbols-outlined">extension</span>
            <div><div class="stat-value"><?= $total ?></div><div class="stat-label">Provider<?= $total !== 1 ? 's' : '' ?></div></div>
        </div>
        <div class="stat-card">
            <span class="material-symbols-outlined">toggle_on</span>
            <div><div class="stat-value"><?= $enabledCount ?></div><div class="stat-label">Enabled</div></div>
        </div>
        <div class="stat-card">
            <span class="material-symbols-outlined">verified_user</span>
            <div><div class="stat-value"><?= $configuredCount ?></div><div class="stat-label">Configured</div></div>
        </div>
        <div class="stat-card">
            <span class="material-symbols-outlined">link</span>
            <div><div class="stat-value"><?= $connTotal ?></div><div class="stat-label">Connection<?= $connTotal !== 1 ? 's' : '' ?></div></div>
        </div>
    </div>

    <p class="admin-hint">
        Disabling a provider immediately hides it from the login screen and the profile page and
        blocks new sign-ins with it — but it does <strong>not</strong> remove tokens users have already
        connected. A provider only appears to users when it is both <strong>enabled</strong> here and
        <strong>configured</strong> (its credentials exist in <code>app/config</code>).
    </p>

    <?php if ($total === 0): ?>
        <div class="empty-state">
            <span class="material-symbols-outlined">hub</span>
            <p>No providers are registered.</p>
        </div>
    <?php else: ?>
    <table class="admin-table oauth-admin-table">
        <thead>
            <tr>
                <th>Provider</th>
                <th>Credentials</th>
                <th class="text-center">Users</th>
                <th class="text-center">Enabled</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($this->providers as $p):
                $slug  = $p['slug'];
                $users = $this->connectionCounts[$slug] ?? 0;
            ?>
            <tr class="oauth-admin-row<?= $p['enabled'] ? '' : ' is-disabled' ?>">
                <td>
                    <div class="oauth-admin-provider">
                        <span class="oauth-admin-icon"><?= oauth_provider_icon($slug) ?></span>
                        <div class="oauth-admin-name">
                            <span><?= htmlspecialchars($p['name']) ?></span>
                            <code>via=<?= (int) $p['via_code'] ?></code>
                        </div>
                    </div>
                </td>
                <td>
                    <?php if ($p['configured']): ?>
                    <span class="oauth-cred is-configured">
                        <span class="material-symbols-outlined">check_circle</span> Configured
                    </span>
                    <?php else: ?>
                    <span class="oauth-cred not-configured" title="Add credentials in app/config/<?= htmlspecialchars($slug) ?>.ini">
                        Not configured
                    </span>
                    <?php endif; ?>
                </td>
                <td class="text-center"><?= (int) $users ?></td>
                <td class="text-center">
                    <label class="oauth-admin-toggle">
                        <input type="checkbox" class="toggle-switch oauth-admin-switch"
                               data-slug="<?= htmlspecialchars($slug) ?>"
                               aria-label="Enable <?= htmlspecialchars($p['name']) ?>"
                               <?= $p['enabled'] ? 'checked' : '' ?>>
                    </label>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>
