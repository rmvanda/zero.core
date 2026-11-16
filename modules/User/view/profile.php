<?php
$user = $this->user;
$pic = $user['pic'] ?? null;
$name = htmlspecialchars($user['full_name'] ?? 'Unknown');
$email = htmlspecialchars($user['email'] ?? '—');
$verified = $user['verified'] ?? false;
$gender = $user['gender'] ?? null;
$bday = $user['bday'] ?? null;
$userId = $this->userId;
$authLevel = $this->authLevel;
$sessionCreatedAt = $this->sessionCreatedAt ? date('F j, Y \a\t g:i A', $this->sessionCreatedAt) : null;
$settings = $this->settings;

/* Capabilities the user holds, and their settings */
$permissions = array_fill_keys($this->capabilities, '1');
$config = $settings;

// Shared provider brand icons (also used by the login buttons).
if ($brandPartial = $this->framePiecePath('_oauth-brand')) {
    require_once $brandPartial;
}
?>

<div class="profile-page">

    <!-- Identity Card -->
    <div class="profile-card profile-identity">
        <?php if ($pic): ?>
        <img src="<?= htmlspecialchars($pic) ?>" alt="<?= $name ?>" class="profile-avatar">
        <?php else: ?>
        <div class="profile-avatar profile-avatar-fallback">
            <span class="material-symbols-outlined">person</span>
        </div>
        <?php endif; ?>

        <div class="profile-identity-info">
            <h1 class="profile-name"><?= $name ?></h1>
            <p class="profile-email">
                <span class="material-symbols-outlined">mail</span>
                <?= $email ?>
                <?php if ($verified): ?>
                <span class="profile-badge-verified" title="Verified">
                    <span class="material-symbols-outlined">verified</span>
                    Verified
                </span>
                <?php endif; ?>
            </p>
        </div>
    </div>

    <!-- Details Grid -->
    <div class="profile-grid">

        <!-- Account Info -->
        <div class="profile-card">
            <h2 class="profile-card-title">
                <span class="material-symbols-outlined">account_circle</span>
                Account
            </h2>
            <dl class="profile-details">
                <div class="profile-detail-row">
                    <dt>User ID</dt>
                    <dd><code><?= $userId ?? '—' ?></code></dd>
                </div>
                <div class="profile-detail-row">
                    <dt>Auth Level</dt>
                    <dd><code><?= htmlspecialchars($authLevel) ?></code></dd>
                </div>
                <?php if ($sessionCreatedAt): ?>
                <div class="profile-detail-row">
                    <dt>Session Created</dt>
                    <dd><?= $sessionCreatedAt ?> (GMT)</dd>
                </div>
                <?php endif; ?>
                <?php if ($gender): ?>
                <div class="profile-detail-row">
                    <dt>Gender</dt>
                    <dd><?= htmlspecialchars($gender) ?></dd>
                </div>
                <?php endif; ?>
                <?php if ($bday): ?>
                <div class="profile-detail-row">
                    <dt>Birthday</dt>
                    <dd><?= htmlspecialchars($bday) ?></dd>
                </div>
                <?php endif; ?>
            </dl>
        </div>

        <!-- Permissions -->
        <div class="profile-card">
            <h2 class="profile-card-title">
                <span class="material-symbols-outlined">shield</span>
                Permissions
            </h2>
            <?php if (empty($permissions)): ?>
            <p class="profile-empty">No permissions assigned.</p>
            <?php else: ?>
            <ul class="profile-permissions">
                <?php foreach ($permissions as $key => $val): ?>
                <li class="profile-permission-item <?= $val ? 'granted' : 'denied' ?>">
                    <span class="material-symbols-outlined"><?= $val ? 'check_circle' : 'cancel' ?></span>
                    <span class="profile-permission-label"><?= htmlspecialchars(str_replace('allow.', '', $key)) ?></span>
                </li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
        </div>

        <!-- API Tokens -->
        <div class="profile-card">
            <h2 class="profile-card-title">
                <span class="material-symbols-outlined">key</span>
                API Tokens
            </h2>
            <dl class="profile-details">
                <div class="profile-detail-row">
                    <dt>Manage tokens for IoT devices and external services</dt>
                    <dd><a href="/user/tokens" class="profile-tokens-link">Manage Tokens</a></dd>
                </div>
            </dl>
        </div>

        <!-- Other Settings -->
        <?php if (!empty($config)): ?>
        <div class="profile-card">
            <h2 class="profile-card-title">
                <span class="material-symbols-outlined">tune</span>
                Settings
            </h2>
            <dl class="profile-details">
                <?php foreach ($config as $key => $val): ?>
                <div class="profile-detail-row">
                    <dt><?= htmlspecialchars($key) ?></dt>
                    <dd><code><?= htmlspecialchars($val) ?></code></dd>
                </div>
                <?php endforeach; ?>
            </dl>
        </div>
        <?php endif; ?>

    </div>

    <!-- Connected Accounts (OAuth providers) — full-width panel below the grid -->
    <div class="profile-card profile-oauth">
        <h2 class="profile-card-title">
            <span class="material-symbols-outlined">hub</span>
            Connected Accounts
            <?php if (!empty($this->oauthProviders)):
                $total = count($this->oauthProviders);
                $connectedCount = count(array_filter(
                    $this->oauthProviders,
                    fn($pp) => isset($this->oauthConnections[$pp['slug']])
                ));
            ?>
            <span class="count"><?= $connectedCount ?> of <?= $total ?> connected</span>
            <?php endif; ?>
        </h2>
        <?php if (empty($this->oauthProviders)): ?>
        <p class="profile-empty">No sign-in providers are available right now.</p>
        <?php else: ?>
        <p class="oauth-provider-intro">
            Connect the accounts you want to use across the site. Connecting is separate
            from how you signed in — link as many as you like, or remove any at any time.
        </p>
        <ul class="oauth-provider-list">
            <?php foreach ($this->oauthProviders as $p):
                $slug = $p['slug'];
                $conn = $this->oauthConnections[$slug] ?? null;
                $connected = $conn !== null;
            ?>
            <li class="oauth-provider-row<?= $connected ? ' is-connected' : '' ?>">
                <span class="oauth-provider-icon"><?= oauth_provider_icon($slug) ?></span>
                <div class="oauth-provider-meta">
                    <span class="oauth-provider-name"><?= htmlspecialchars($p['name']) ?></span>
                    <?php if ($connected): ?>
                    <span class="oauth-provider-status is-connected">
                        <span class="dot"></span>
                        Connected<?php if (!empty($conn['created_at'])): ?> <span class="since">&middot; since <?= htmlspecialchars(date('M j, Y', strtotime($conn['created_at']))) ?></span><?php endif; ?>
                    </span>
                    <?php else: ?>
                    <span class="oauth-provider-status"><span class="dot"></span>Not connected</span>
                    <?php endif; ?>
                </div>
                <div class="oauth-provider-action">
                    <?php if ($connected): ?>
                    <form method="post" action="/auth/disconnect" class="oauth-disconnect-form">
                        <input type="hidden" name="provider" value="<?= htmlspecialchars($slug) ?>">
                        <button type="submit" class="oauth-disconnect-btn">Disconnect</button>
                    </form>
                    <?php else: ?>
                    <a class="oauth-btn oauth-connect-btn <?= oauth_provider_brand_class($slug) ?>" href="/auth/connect?via=<?= (int) $p['via_code'] ?>">Connect</a>
                    <?php endif; ?>
                </div>
            </li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
    </div>
</div>
