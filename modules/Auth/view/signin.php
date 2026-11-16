<div class="login-container">
    <div class="login-box register-box">
        <?php if (!empty($this->sent)): ?>
            <h2>Check your email</h2>
            <p class="register-sent">If that address has an account, we've sent it a link.
               Open it to finish signing in. It can only be used once.</p>
        <?php else: ?>
            <h2>Sign in with email</h2>
            <p class="register-sent">We'll email you a link. No password needed.</p>

            <form action="/auth/signin" method="post" class="register-form">
                <label class="register-field" for="signin-email">
                    <span class="register-label">Email Address</span>
                    <input id="signin-email" name="email" type="email" required>
                </label>
                <button type="submit" class="register-submit">Email me a link</button>
            </form>

            <div class="register-alt">
                <p>No account yet? <a href="/auth/register">Create one</a></p>
            </div>
        <?php endif; ?>
    </div>
</div>
