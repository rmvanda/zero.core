<div class="login-container">
    <div class="login-box register-box">
        <h2>Confirm sign-in</h2>
        <p class="register-sent"><?= htmlspecialchars(sprintf(AUTH_TOKEN_CONFIRM_LEAD, SITE_SHORT_NAME), ENT_NOQUOTES) ?></p>

        <?php if (!empty($this->account)): ?>
            <p class="register-sent">You are about to sign in as
               <strong><?= htmlspecialchars($this->account) ?></strong>.
               If that is not you, close this page.</p>
        <?php endif; ?>

        <form action="/auth/token" method="post" class="register-form">
            <input type="hidden" name="t" value="<?= htmlspecialchars($this->token ?? '') ?>">
            <input type="hidden" name="n" value="<?= htmlspecialchars($this->nonce ?? '') ?>">
            <button type="submit" class="register-submit">Sign me in</button>
        </form>
    </div>
</div>
