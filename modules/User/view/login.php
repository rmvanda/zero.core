<!-- Login page - main.css is loaded by global frame/head.php.
     The Google widget comes from frame/_google-signin.php, which owns all three
     pieces GSI needs: the loader script, the single #g_id_onload this document
     is allowed, and the .g_id_signin placeholder. That partial is
     frame-independent on purpose — User::__construct() adopts the referring
     module's frame, and a module frame that replaces head.php/footer.php would
     otherwise strip the script and the config out from under this page. The
     sitewide sign-in modal in frame/footer.php includes the same partial and
     gets a second button driven by the one config. Do not add a script tag or
     an #g_id_onload here; the partial is the only place either belongs. -->
<div class="login-container">
    <div class="login-box">
        <?php if (isset($_GET['r']) && $_GET['r'] === 'y'): ?>
            <h2>Login Required</h2>
            <p class="login-required-message">You must be logged in to view this page.</p>
        <?php else: ?>
            <h2><?= htmlspecialchars(\Zero\Core\Strings::fill(USER_LOGIN_HEADING, SITE_SHORT_NAME), ENT_NOQUOTES) ?></h2>
        <?php endif; ?>

        <?php if ($googlePartial = $this->framePiecePath('_google-signin')) { include $googlePartial; } ?>

        <form action="/auth/signin" method="post" class="register-form login-email-form">
            <label class="register-field" for="login-email">
                <span class="register-label">Email Address</span>
                <input id="login-email" name="email" type="email" required>
            </label>
            <button type="submit" class="register-submit">Email me a sign-in link</button>
        </form>

        <?php if ($buttonsPartial = $this->framePiecePath('_oauth-buttons')) { include $buttonsPartial; } ?>

        <div class="login-privacy">
            <p><?= htmlspecialchars(USER_LOGIN_BLURB, ENT_NOQUOTES) ?> <br><?= htmlspecialchars(USER_LOGIN_PRIVACY, ENT_NOQUOTES) ?> <a href="/privacy"><?= htmlspecialchars(USER_LOGIN_PRIVACY_LINK, ENT_NOQUOTES) ?></a></p>
        </div>

        <div class="register-alt">
            <p>No account yet? <a href="/auth/register">Create one</a></p>
        </div>
    </div>
</div>
