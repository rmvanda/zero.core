<div class="content-wrapper">
    <?php
    use \Zero\Core\Request;
    $userId = Request::$args[1];
    if (!$userId) {
        echo "<p>Invalid user ID</p>";
        return;
    }

    $db = \Zero\Core\Database::getConnection();

    // Get user info
    $stmt = $db->prepare("SELECT id, email, name, pic FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();

    if (!$user) {
        echo "<p>User not found</p>";
        return;
    }

    // Capabilities: every key that can be granted, the ones this user holds
    // directly, and the ones they hold at all (their groups' included)
    $capabilityKeys = \Zero\Module\Admin\Model\CapabilityKeys::all();
    $holder = \Zero\Core\Access\User::find((int) $userId);
    $ownCapabilities = \Zero\Core\Access\Permission::capabilities($holder, true);
    $heldCapabilities = \Zero\Core\Access\Permission::capabilities($holder);

    // Get all available settings in the system
    $stmt = $db->query("SELECT DISTINCT setting_key FROM user_settings ORDER BY setting_key");
    $allPermissionKeys = $stmt->fetchAll(\PDO::FETCH_COLUMN);

    // Get user's current permissions
    $stmt = $db->prepare("SELECT setting_key, setting_value, updated_on FROM user_settings WHERE user_id = ? ORDER BY setting_key");
    $stmt->execute([$userId]);
    $userPermissions = $stmt->fetchAll(\PDO::FETCH_ASSOC);

    // Create a map of user's current permissions for easy lookup
    $userPermMap = [];
    foreach ($userPermissions as $perm) {
        $userPermMap[$perm['setting_key']] = $perm;
    }

    // Build complete permissions list with all available permissions
    $permissions = [];
    foreach ($allPermissionKeys as $key) {
        if (isset($userPermMap[$key])) {
            // User has this permission set
            $permissions[] = $userPermMap[$key];
        } else {
            // User doesn't have this permission yet - show with default value
            $permissions[] = [
                'setting_key' => $key,
                'setting_value' => null,
                'updated_on' => null
            ];
        }
    }

    // Type determination function based on naming convention
    function getSettingType($key) {
        if (strpos($key, 'allow.') === 0) return 'boolean';
        if (strpos($key, 'limit.') === 0) return 'integer';
        if (strpos($key, 'auth.') === 0) return 'integer';
        return 'text';
    }
    ?>

    <h1>Manage Permissions: <?= htmlspecialchars($user['name'] ?? $user['email']) ?></h1>
    <p><a href="/admin/manage/users">&larr; Back to user list</a></p>

    <div class="admin-info-box">
        <strong>User:</strong> <?= htmlspecialchars($user['email']) ?><br>
        <strong>ID:</strong> <?= $user['id'] ?>
    </div>

    <h2>Capabilities</h2>
    <div id="capabilities-list" class="mt-1">
        <?php if (empty($capabilityKeys)): ?>
            <p>No capabilities available in the system.</p>
        <?php else: ?>
            <?php foreach ($capabilityKeys as $key): ?>
                <?php
                $own = in_array($key, $ownCapabilities, true);
                $throughGroup = !$own && in_array($key, $heldCapabilities, true);
                ?>
                <div class="<?= $own ? 'permission-item' : 'permission-item permission-item-unset' ?>">
                    <div class="permission-item-info">
                        <strong><?= htmlspecialchars($key) ?></strong>
                        <?php if ($throughGroup): ?>
                            <span class="permission-badge-unset">Held through a group</span>
                        <?php endif; ?>
                    </div>
                    <div class="permission-item-controls">
                        <label class="admin-checkbox-label">
                            <input type="checkbox"
                                   data-user-id="<?= $user['id'] ?>"
                                   data-setting-key="<?= htmlspecialchars($key) ?>"
                                   data-kind="capability"
                                   <?= $own ? 'checked' : '' ?>
                                   class="permission-input">
                            <span><?= $own ? 'Enabled' : 'Disabled' ?></span>
                        </label>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <h2 class="mt-2">Settings</h2>
    <div id="permissions-list" class="mt-1">
        <?php if (empty($permissions)): ?>
            <p>No settings in the system.</p>
        <?php else: ?>
            <?php foreach ($permissions as $perm): ?>
                <?php
                $type = getSettingType($perm['setting_key']);
                $isSet = $perm['setting_value'] !== null;
                $itemClass = $isSet ? 'permission-item' : 'permission-item permission-item-unset';
                ?>
                <div class="<?= $itemClass ?>">
                    <div class="permission-item-info">
                        <strong><?= htmlspecialchars($perm['setting_key']) ?></strong>
                        <?php if (!$isSet): ?>
                            <span class="permission-badge-unset">Not Set</span>
                        <?php endif; ?>
                        <br>
                        <small class="permission-meta">
                            Type: <?= $type ?>
                            <?php if ($isSet && $perm['updated_on']): ?>
                                | Updated: <?= date('Y-m-d H:i', strtotime($perm['updated_on'])) ?>
                            <?php endif; ?>
                        </small>
                    </div>
                    <div class="permission-item-controls">
                        <?php if ($type === 'boolean'): ?>
                            <label class="admin-checkbox-label">
                                <input type="checkbox"
                                       data-user-id="<?= $user['id'] ?>"
                                       data-setting-key="<?= htmlspecialchars($perm['setting_key']) ?>"
                                       <?= $isSet && $perm['setting_value'] == '1' ? 'checked' : '' ?>
                                       class="permission-input">
                                <span><?= $isSet && $perm['setting_value'] == '1' ? 'Enabled' : 'Disabled' ?></span>
                            </label>
                        <?php elseif ($type === 'integer'): ?>
                            <input type="number"
                                   value="<?= $isSet ? htmlspecialchars($perm['setting_value']) : '' ?>"
                                   placeholder="<?= $isSet ? '' : 'Not set' ?>"
                                   data-user-id="<?= $user['id'] ?>"
                                   data-setting-key="<?= htmlspecialchars($perm['setting_key']) ?>"
                                   class="admin-input admin-input-narrow permission-input">
                        <?php else: ?>
                            <input type="text"
                                   value="<?= $isSet ? htmlspecialchars($perm['setting_value']) : '' ?>"
                                   placeholder="<?= $isSet ? '' : 'Not set' ?>"
                                   data-user-id="<?= $user['id'] ?>"
                                   data-setting-key="<?= htmlspecialchars($perm['setting_key']) ?>"
                                   class="admin-input admin-input-medium permission-input">
                        <?php endif; ?>
                        <?php if ($isSet): ?>
                            <button data-user-id="<?= $user['id'] ?>"
                                    data-setting-key="<?= htmlspecialchars($perm['setting_key']) ?>"
                                    class="admin-btn-delete btn-delete-permission">
                                Delete
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <h2 class="mt-2">Add a Capability or Setting</h2>
    <div class="admin-info-box mt-1">
        <form id="add-permission-form" data-user-id="<?= $user['id'] ?>">
            <div class="admin-form-grid">
                <div class="admin-form-field">
                    <label>Key</label>
                    <input type="text"
                           id="new-setting-key"
                           placeholder="e.g., allow.api.usage or limit.api.requests"
                           required
                           class="admin-input">
                    <small>allow.* is a capability (value 1). limit.* and auth.* are number settings.</small>
                </div>
                <div class="admin-form-field">
                    <label>Value</label>
                    <input type="text"
                           id="new-setting-value"
                           placeholder="e.g., 1 or 100"
                           required
                           class="admin-input">
                </div>
                <div>
                    <button type="submit" class="btn-primary px-1 py-05">
                        Add Permission
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>
