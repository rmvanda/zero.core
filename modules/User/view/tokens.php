<?php
$tokens = $this->tokens ?? [];
?>

<div class="profile-page">

    <!-- API Tokens Card -->
    <div class="profile-card">
        <h2 class="profile-card-title">
            <span class="material-symbols-outlined">key</span>
            API Tokens
        </h2>

        <div class="tokens-header">
            <p class="tokens-description">
                API tokens allow IoT devices and external services to access your account endpoints.
            </p>
            <button class="tokens-create-btn" id="createTokenBtn">
                <span class="material-symbols-outlined">add</span>
                Create Token
            </button>
        </div>

        <?php if (empty($tokens)): ?>
        <p class="profile-empty">No API tokens created yet.</p>
        <?php else: ?>
        <div class="tokens-list">
            <?php foreach ($tokens as $token): ?>
            <div class="token-row" data-token-id="<?= (int)$token['id'] ?>">
                <div class="token-info">
                    <span class="token-label">
                        <span class="material-symbols-outlined">token</span>
                        <?= htmlspecialchars($token['label'] ?: 'Unnamed token') ?>
                    </span>
                    <span class="token-meta">
                        Created <?= date('M j, Y', strtotime($token['created_at'])) ?>
                        <?php if ($token['last_used_at']): ?>
                            &middot; Last used <?= date('M j, Y g:i A', strtotime($token['last_used_at'])) ?>
                        <?php else: ?>
                            &middot; Never used
                        <?php endif; ?>
                        <?php if ($token['expires_at']): ?>
                            &middot; Expires <?= date('M j, Y', strtotime($token['expires_at'])) ?>
                        <?php endif; ?>
                    </span>
                </div>
                <button class="token-delete-btn" data-token-id="<?= (int)$token['id'] ?>" title="Revoke token">
                    <span class="material-symbols-outlined">delete</span>
                </button>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

</div>

<!-- Create Token Dialog -->
<dialog id="createTokenDialog" class="token-dialog">
    <div class="token-dialog-content">
        <h3 class="token-dialog-title">
            <span class="material-symbols-outlined">add_circle</span>
            Create API Token
        </h3>
        <form id="createTokenForm">
            <label class="token-form-label" for="tokenLabel">Label</label>
            <input type="text" id="tokenLabel" name="label"
                   class="token-form-input"
                   placeholder="e.g. Garage Sensor, Living Room Hub"
                   maxlength="100">
            <div class="token-dialog-actions">
                <button type="button" class="token-btn token-btn-cancel" id="cancelCreateBtn">Cancel</button>
                <button type="submit" class="token-btn token-btn-primary">Generate Token</button>
            </div>
        </form>
    </div>
</dialog>

<!-- Show Token Dialog -->
<dialog id="showTokenDialog" class="token-dialog">
    <div class="token-dialog-content">
        <h3 class="token-dialog-title">
            <span class="material-symbols-outlined">key</span>
            Your New API Token
        </h3>
        <p class="token-warning">
            <span class="material-symbols-outlined">warning</span>
            Copy this token now. It will not be shown again.
        </p>
        <div class="token-display">
            <code id="tokenValue"></code>
            <button class="token-copy-btn" id="copyTokenBtn" title="Copy to clipboard">
                <span class="material-symbols-outlined">content_copy</span>
            </button>
        </div>
        <div class="token-dialog-actions">
            <button type="button" class="token-btn token-btn-primary" id="doneTokenBtn">Done</button>
        </div>
    </div>
</dialog>
