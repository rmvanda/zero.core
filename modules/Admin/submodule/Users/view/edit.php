<?php $userId = (int) $this->account->id; ?>
<div class="content-wrapper" id="user-edit" data-user-id="<?= $userId ?>">
    <header class="admin-head">
        <div>
            <h1><?= htmlspecialchars((string) ($this->account->name ?: $this->account->email)) ?></h1>
            <p class="admin-lede">What this account may do, and the settings stored for it.</p>
        </div>
    </header>

    <dl class="admin-panel admin-facts">
        <div>
            <dt>Email</dt>
            <dd><?= htmlspecialchars((string) $this->account->email) ?></dd>
        </div>
        <div>
            <dt>User ID</dt>
            <dd><?= $userId ?></dd>
        </div>
        <div>
            <dt>Account</dt>
            <dd>
                <?php if ($this->account->isDisabled()): ?>
                    <span class="status-badge status-inactive">Disabled</span>
                <?php else: ?>
                    <span class="status-badge status-active">Active</span>
                <?php endif; ?>
            </dd>
        </div>
    </dl>

    <section class="admin-section">
        <div class="admin-section-head">
            <h2>Capabilities</h2>
            <p class="admin-note">A switch takes effect as soon as it changes.</p>
        </div>
        <div id="capabilities-list">
            <?php if (empty($this->capabilityRows)): ?>
                <div class="admin-empty"><p>No capability is declared or held yet. Grant the first one below.</p></div>
            <?php else: ?>
            <div class="permission-list">
                <?php foreach ($this->capabilityRows as $capability): ?>
                    <div class="<?= $capability['own'] ? 'permission-item' : 'permission-item permission-item-unset' ?>">
                        <div class="permission-item-info">
                            <strong><?= htmlspecialchars($capability['key']) ?></strong>
                            <?php if ($capability['throughGroup']): ?>
                                <span class="permission-badge-unset">Held through a group</span>
                            <?php endif; ?>
                        </div>
                        <div class="permission-item-controls">
                            <label class="admin-checkbox-label">
                                <input type="checkbox"
                                       data-key="<?= htmlspecialchars($capability['key']) ?>"
                                       <?= $capability['own'] ? 'checked' : '' ?>
                                       class="capability-input admin-switch">
                                <span class="admin-switch-state"><?= $capability['own'] ? 'Granted' : 'Not granted' ?></span>
                            </label>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

        <form id="add-capability-form" method="post" action="/admin/users/set-capability" class="admin-panel admin-form-row user-add-form">
            <input type="hidden" name="user_id" value="<?= $userId ?>">
            <input type="hidden" name="value" value="1">
            <div class="admin-form-field">
                <label for="new-capability-key">Grant another capability</label>
                <input type="text"
                       id="new-capability-key"
                       name="key"
                       placeholder="e.g., allow.api.usage"
                       pattern="allow(\.[a-z0-9][a-z0-9_\-]*)+"
                       maxlength="100"
                       required
                       class="admin-input">
                <small>allow. and then lowercase words joined by dots.</small>
            </div>
            <button type="submit" class="btn-primary admin-form-submit">
                Grant Capability
            </button>
        </form>
    </section>

    <section class="admin-section">
        <div class="admin-section-head">
            <h2>Settings</h2>
            <p class="admin-note">A value is saved when you leave its field.</p>
        </div>
        <div id="settings-list">
            <?php if (empty($this->settingRows)): ?>
                <div class="admin-empty"><p>No setting is stored on this site yet. Add the first one below.</p></div>
            <?php else: ?>
            <div class="permission-list">
                <?php foreach ($this->settingRows as $setting): ?>
                    <div class="<?= $setting['isSet'] ? 'permission-item' : 'permission-item permission-item-unset' ?>">
                        <div class="permission-item-info">
                            <strong><?= htmlspecialchars($setting['key']) ?></strong>
                            <small class="permission-meta">
                                <?= htmlspecialchars(ucfirst($setting['type'])) ?><?php if ($setting['updated']): ?>, updated <?= htmlspecialchars($setting['updated']) ?><?php endif; ?>
                            </small>
                        </div>
                        <div class="permission-item-controls">
                            <?php if ($setting['type'] === 'integer'): ?>
                                <input type="number"
                                       step="1"
                                       value="<?= htmlspecialchars($setting['value']) ?>"
                                       placeholder="<?= $setting['isSet'] ? '' : 'Not set' ?>"
                                       data-key="<?= htmlspecialchars($setting['key']) ?>"
                                       data-saved="<?= htmlspecialchars($setting['value']) ?>"
                                       aria-label="Value of <?= htmlspecialchars($setting['key']) ?>"
                                       class="admin-input admin-input-narrow setting-input">
                            <?php else: ?>
                                <input type="text"
                                       value="<?= htmlspecialchars($setting['value']) ?>"
                                       placeholder="<?= $setting['isSet'] ? '' : 'Not set' ?>"
                                       data-key="<?= htmlspecialchars($setting['key']) ?>"
                                       data-saved="<?= htmlspecialchars($setting['value']) ?>"
                                       aria-label="Value of <?= htmlspecialchars($setting['key']) ?>"
                                       class="admin-input admin-input-medium setting-input">
                            <?php endif; ?>
                            <?php if ($setting['isSet']): ?>
                                <button type="button"
                                        data-key="<?= htmlspecialchars($setting['key']) ?>"
                                        title="Delete this setting"
                                        aria-label="Delete <?= htmlspecialchars($setting['key']) ?>"
                                        class="btn-icon btn-remove-setting">
                                    <span class="material-symbols-outlined" aria-hidden="true">delete</span>
                                </button>
                            <?php else: ?>
                                <span class="setting-no-delete" aria-hidden="true"></span>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

        <form id="add-setting-form" method="post" action="/admin/users/set-setting" class="admin-panel admin-form-row user-add-form">
            <input type="hidden" name="user_id" value="<?= $userId ?>">
            <div class="admin-form-field">
                <label for="new-setting-key">Add a setting</label>
                <input type="text"
                       id="new-setting-key"
                       name="key"
                       placeholder="e.g., limit.api.requests"
                       maxlength="255"
                       required
                       class="admin-input">
                <small>limit.* and auth.* are whole numbers. An allow.* key is a capability, not a setting.</small>
            </div>
            <div class="admin-form-field">
                <label for="new-setting-value">Value</label>
                <input type="text"
                       id="new-setting-value"
                       name="value"
                       placeholder="e.g., 100"
                       required
                       class="admin-input">
            </div>
            <button type="submit" class="btn-primary admin-form-submit">
                Add Setting
            </button>
        </form>
    </section>
</div>
