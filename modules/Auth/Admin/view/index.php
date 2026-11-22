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
    <header class="admin-head">
        <div>
            <h1>OAuth Providers</h1>
            <p class="admin-lede">The third-party services people can sign in with. A switch takes effect as soon as it changes.</p>
        </div>
    </header>

    <div class="stats-row">
        <div class="stat-card">
            <span class="material-symbols-outlined" aria-hidden="true">extension</span>
            <div><div class="stat-value"><?= $total ?></div><div class="stat-label">Provider<?= $total !== 1 ? 's' : '' ?></div></div>
        </div>
        <div class="stat-card">
            <span class="material-symbols-outlined" aria-hidden="true">toggle_on</span>
            <div><div class="stat-value"><?= $enabledCount ?></div><div class="stat-label">Enabled</div></div>
        </div>
        <div class="stat-card">
            <span class="material-symbols-outlined" aria-hidden="true">verified_user</span>
            <div><div class="stat-value"><?= $configuredCount ?></div><div class="stat-label">Configured</div></div>
        </div>
        <div class="stat-card">
            <span class="material-symbols-outlined" aria-hidden="true">link</span>
            <div><div class="stat-value"><?= $connTotal ?></div><div class="stat-label">Connection<?= $connTotal !== 1 ? 's' : '' ?></div></div>
        </div>
    </div>

    <div class="admin-info-box">
        Disabling a provider immediately hides it from the login screen and the profile page and
        blocks new sign-ins with it — but it does <strong>not</strong> remove tokens users have already
        connected. A provider only appears to users when it is both <strong>enabled</strong> here and
        <strong>configured</strong> (its credentials exist in <code>app/config</code>).
    </div>

    <?php if ($total === 0): ?>
        <div class="admin-empty">
            <span class="material-symbols-outlined" aria-hidden="true">hub</span>
            <p>No providers are registered. A provider is a row of the <code>oauth_provider</code> table.</p>
        </div>
    <?php else: ?>
    <div class="admin-table-wrap">
    <table class="admin-table oauth-admin-table">
        <thead>
            <tr>
                <th>Provider</th>
                <th>Credentials</th>
                <th class="num">Users</th>
                <th class="text-right">Enabled</th>
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
                    <span class="status-badge status-active">Configured</span>
                    <?php else: ?>
                    <span class="status-badge status-inactive" title="Add credentials in app/config/<?= htmlspecialchars($slug) ?>.ini">Not configured</span>
                    <?php endif; ?>
                </td>
                <td class="num"><?= (int) $users ?></td>
                <td class="text-right">
                    <label class="oauth-admin-toggle">
                        <input type="checkbox" class="admin-switch oauth-admin-switch"
                               data-slug="<?= htmlspecialchars($slug) ?>"
                               aria-label="Enable <?= htmlspecialchars($p['name']) ?>"
                               <?= $p['enabled'] ? 'checked' : '' ?>>
                    </label>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</div>
