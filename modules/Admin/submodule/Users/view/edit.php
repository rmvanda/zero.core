<?php $userId = (int) $this->account->id; ?>
<div class="content-wrapper" id="user-edit" data-user-id="<?= $userId ?>">
    <h1>Manage Permissions: <?= htmlspecialchars((string) ($this->account->name ?: $this->account->email)) ?></h1>

    <div class="admin-info-box">
        <strong>User:</strong> <?= htmlspecialchars((string) $this->account->email) ?><br>
        <strong>ID:</strong> <?= $userId ?>
        <?php if ($this->account->isDisabled()): ?>
            <br><span class="status-badge status-inactive">Disabled</span>
        <?php endif; ?>
    </div>

    <h2>Capabilities</h2>
    <div id="capabilities-list" class="mt-1">
        <?php if (empty($this->capabilityRows)): ?>
            <p>No capabilities available in the system.</p>
        <?php else: ?>
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
                                   class="capability-input">
                            <span><?= $capability['own'] ? 'Enabled' : 'Disabled' ?></span>
                        </label>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <h2 class="mt-2">Settings</h2>
    <div id="settings-list" class="mt-1">
        <?php if (empty($this->settingRows)): ?>
            <p>No settings in the system.</p>
        <?php else: ?>
            <?php foreach ($this->settingRows as $setting): ?>
                <div class="<?= $setting['isSet'] ? 'permission-item' : 'permission-item permission-item-unset' ?>">
                    <div class="permission-item-info">
                        <strong><?= htmlspecialchars($setting['key']) ?></strong>
                        <?php if (!$setting['isSet']): ?>
                            <span class="permission-badge-unset">Not Set</span>
                        <?php endif; ?>
                        <br>
                        <small class="permission-meta">
                            Type: <?= htmlspecialchars($setting['type']) ?>
                            <?php if ($setting['updated']): ?>
                                | Updated: <?= htmlspecialchars($setting['updated']) ?>
                            <?php endif; ?>
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
                                    class="admin-btn-delete btn-remove-setting">
                                Delete
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <h2 class="mt-2">Grant a Capability</h2>
    <div class="admin-info-box mt-1">
        <form id="add-capability-form" method="post" action="/admin/users/set-capability">
            <input type="hidden" name="user_id" value="<?= $userId ?>">
            <input type="hidden" name="value" value="1">
            <div class="admin-form-grid">
                <div class="admin-form-field">
                    <label for="new-capability-key">Key</label>
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
                <div>
                    <button type="submit" class="btn-primary px-1 py-05">
                        Grant Capability
                    </button>
                </div>
            </div>
        </form>
    </div>

    <h2 class="mt-2">Add a Setting</h2>
    <div class="admin-info-box mt-1">
        <form id="add-setting-form" method="post" action="/admin/users/set-setting">
            <input type="hidden" name="user_id" value="<?= $userId ?>">
            <div class="admin-form-grid">
                <div class="admin-form-field">
                    <label for="new-setting-key">Key</label>
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
                <div>
                    <button type="submit" class="btn-primary px-1 py-05">
                        Add Setting
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>
