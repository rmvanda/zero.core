<?php
// Google sign-in button: a plain link into the Auth module's OAuth flow
// (/auth/user?via={code}), shown when Google is enabled and configured. It
// loads no script and names no client id. A site that wants Google's own
// sign-in widget replaces this file by having its own _google-signin.php in
// app/frontend/frame/.
//
// Guarded: a registry or database failure hides the button instead of
// breaking the page that includes this partial.
if (isset($this) && ($brandPartial = $this->framePiecePath('_oauth-brand'))) {
    require_once $brandPartial;
}

$googleProvider = null;
if (function_exists('oauth_provider_icon')) {
    try {
        $googleProvider = \Zero\Core\Module\Auth\OAuth\Provider::bySlug('google');
        if ($googleProvider !== null && !($googleProvider['enabled'] && $googleProvider['configured'])) {
            $googleProvider = null;
        }
    } catch (\Throwable $e) {
        $googleProvider = null;
    }
}
?>
<?php if ($googleProvider !== null): ?>
    <div class="google-signin-wrapper">
        <a class="oauth-btn <?= oauth_provider_brand_class('google') ?>" href="/auth/user?via=<?= (int) $googleProvider['via_code'] ?>">
            <?= oauth_provider_icon('google') ?>
            <span><?= htmlspecialchars(\Zero\Core\Strings::fill(SITE_SIGN_IN_WITH, $googleProvider['name'])) ?></span>
        </a>
    </div>
<?php endif; ?>
