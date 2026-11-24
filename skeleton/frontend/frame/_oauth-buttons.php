<?php
// Sign-in buttons for the OAuth providers an admin enabled and configured
// (Zero\Core\Module\Auth\OAuth\Provider::available()). Google is rendered by
// _google-signin.php, so it is skipped here. A site replaces this file by having
// its own _oauth-buttons.php in app/frontend/frame/.
//
// Guarded: a registry or database failure hides the buttons instead of
// breaking the page that includes this partial.
if (isset($this) && ($brandPartial = $this->framePiecePath('_oauth-brand'))) {
    require_once $brandPartial;
}

$oauthButtons = [];
if (function_exists('oauth_provider_icon')) {
    try {
        $oauthButtons = array_filter(
            \Zero\Core\Module\Auth\OAuth\Provider::available(),
            fn($p) => $p['slug'] !== 'google'
        );
    } catch (\Throwable $e) {
        $oauthButtons = [];
    }
}
?>
<?php if (!empty($oauthButtons)): ?>
    <div class="oauth-divider"><?= htmlspecialchars(SITE_OR) ?></div>
    <div class="oauth-buttons">
        <?php foreach ($oauthButtons as $p): ?>
            <a class="oauth-btn <?= oauth_provider_brand_class($p['slug']) ?>" href="/auth/user?via=<?= (int) $p['via_code'] ?>">
                <?= oauth_provider_icon($p['slug']) ?>
                <span><?= htmlspecialchars(\Zero\Core\Strings::fill(SITE_SIGN_IN_WITH, $p['name'])) ?></span>
            </a>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
