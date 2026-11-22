<div class="content-wrapper">
    <header class="admin-head">
        <div>
            <h1>Modules</h1>
            <p class="admin-lede"><?= htmlspecialchars(ADMIN_MODULES_INTRO) ?></p>
        </div>
    </header>

    <?php if (!empty($this->saveError)): ?>
        <div class="admin-info-box is-error modules-error" role="alert"><?= htmlspecialchars($this->saveError) ?></div>
    <?php endif; ?>
    <?php if (!empty($this->saved)): ?>
        <div class="admin-info-box is-success modules-saved" role="status"><?= htmlspecialchars(ADMIN_MODULES_SAVED) ?></div>
    <?php endif; ?>
    <div class="admin-info-box modules-status" role="status" hidden></div>

    <?php $editable = $this->modulesHash !== null; ?>
    <div class="admin-table-wrap">
    <table class="admin-table modules-table">
        <thead>
            <tr>
                <th>Module</th>
                <th>Tier</th>
                <th>Lives in</th>
                <th>State</th>
                <th>Order</th>
                <th class="text-right">Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($this->moduleRows as $row): ?>
                <?php $formId = 'module-form-' . htmlspecialchars($row['name']); ?>
                <tr class="module-row" data-module="<?= htmlspecialchars($row['name']) ?>">
                    <td><strong><?= htmlspecialchars($row['name']) ?></strong></td>
                    <td class="dim"><?= htmlspecialchars($row['tier']) ?></td>
                    <td class="dim"><?= htmlspecialchars($row['where']) ?></td>
                    <td>
                        <span class="status-badge status-<?= $row['enabled'] ? 'active' : 'inactive' ?>"><?= $row['enabled'] ? 'On' : 'Off' ?></span>
                        <span class="module-state"><?= $row['departure'] ? 'changed from default' : 'default' ?></span>
                    </td>
                    <?php if (!$row['settable']): ?>
                        <td><?= $row['order'] === null ? '' : (int) $row['order'] ?></td>
                        <td class="actions-cell"><span class="module-note"><?= htmlspecialchars(ADMIN_MODULES_UNSETTABLE) ?></span></td>
                    <?php elseif (!$editable): ?>
                        <td><?= $row['order'] === null ? '' : (int) $row['order'] ?></td>
                        <td class="actions-cell"></td>
                    <?php else: ?>
                    <td>
                        <form id="<?= $formId ?>" class="module-form" method="post" action="/admin/modules/set">
                            <input type="hidden" name="module" value="<?= htmlspecialchars($row['name']) ?>">
                            <input type="hidden" name="hash" value="<?= htmlspecialchars($this->modulesHash) ?>">
                            <input type="number" name="order" class="admin-input admin-input-narrow" min="0" max="9999" step="1"
                                   value="<?= $row['order'] === null ? '' : (int) $row['order'] ?>" aria-label="Order of <?= htmlspecialchars($row['name']) ?>">
                        </form>
                    </td>
                    <td class="actions-cell">
                        <?php if ($row['canSwitch']): ?>
                            <select name="enabled" form="<?= $formId ?>" class="admin-select" aria-label="State of <?= htmlspecialchars($row['name']) ?>">
                                <option value="default"<?= $row['departure'] ? '' : ' selected' ?>>Default (<?= $row['default'] ? 'on' : 'off' ?>)</option>
                                <option value="on"<?= ($row['departure'] && $row['enabled']) ? ' selected' : '' ?>>On</option>
                                <option value="off"<?= ($row['departure'] && !$row['enabled']) ? ' selected' : '' ?>>Off</option>
                            </select>
                        <?php else: ?>
                            <span class="module-always-on"><?= htmlspecialchars(ADMIN_MODULES_ALWAYS_ON) ?></span>
                        <?php endif; ?>
                        <button type="submit" form="<?= $formId ?>" class="btn-small">Save</button>
                    </td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>
