<div class="content-wrapper">
    <h1>User Management</h1>
    <p><a href="/admin">&larr; Back to Admin Panel</a></p>

    <?php
    $db = \Zero\Core\Database::getConnection();
    $stmt = $db->query("SELECT id, email, name, pic, verified FROM users ORDER BY id asc");
    $users = $stmt->fetchAll();

    // Pre-fetch permission counts and group memberships per user
    $userMeta = [];
    foreach ($users as $u) {
        $sid = $u['id'];
        $account = \Zero\Core\User::find((int) $sid);
        $permCount = count(\Zero\Core\Permission::capabilities($account));

        // Inactive groups too: this screen manages them.
        $groups = array_map(fn(\Zero\Core\Group $group) => $group->group_name, $account->groups(false));

        $userMeta[$sid] = ['perm_count' => $permCount, 'groups' => $groups];
    }
    ?>

    <div class="mt-2">
        <table class="admin-table expandable-table">
            <thead>
                <tr>
                    <th width="30"></th>
                    <th>User</th>
                    <th>Email</th>
                    <th class="text-center">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($users as $user): ?>
                <tr class="main-row" data-user-id="<?= $user['id'] ?>">
                    <td>
                        <span class="material-symbols-outlined expand-icon">chevron_right</span>
                    </td>
                    <td>
                        <div class="user-info">
                            <?php if ($user['pic']): ?>
                                <img src="<?= htmlspecialchars($user['pic']) ?>"
                                     alt="Profile"
                                     class="avatar-md">
                            <?php else: ?>
                                <span class="material-symbols-outlined icon-md">account_circle</span>
                            <?php endif; ?>
                            <span><?= htmlspecialchars($user['name'] ?? 'Unknown') ?></span>
                        </div>
                    </td>
                    <td>
                        <?= htmlspecialchars($user['email']) ?>
                    </td>
                    <td class="text-center actions-cell">
                        <a href="/admin/manage/users/<?= $user['id'] ?>"
                           class="btn-small">
                            Permissions
                        </a>
                        <button class="btn-icon btn-delete"
                                data-user-id="<?= $user['id'] ?>"
                                data-user-name="<?= htmlspecialchars($user['name'] ?? 'Unknown', ENT_QUOTES) ?>"
                                title="Delete user">
                            <span class="material-symbols-outlined">delete</span>
                        </button>
                    </td>
                </tr>
                <tr class="details-row" data-user-id="<?= $user['id'] ?>">
                    <td colspan="4" class="details-cell">
                        <div class="details-content">
                            <div class="details-grid details-grid-3col">
                                <div class="detail-item">
                                    <span class="detail-label">User ID</span>
                                    <span class="detail-value"><?= $user['id'] ?></span>
                                </div>
                                <div class="detail-item">
                                    <span class="detail-label">Verified</span>
                                    <span class="detail-value"><?= $user['verified'] ? 'Yes' : 'No' ?></span>
                                </div>
                                <div class="detail-item">
                                    <span class="detail-label">Permissions</span>
                                    <span class="detail-value"><?= $userMeta[$user['id']]['perm_count'] ?> capabilities</span>
                                </div>
                            </div>
                            <?php $groups = $userMeta[$user['id']]['groups']; ?>
                            <?php if (!empty($groups)): ?>
                            <div class="group-members-list">
                                <span class="detail-label">Groups</span>
                                <div class="members-chips">
                                    <?php foreach ($groups as $groupName): ?>
                                        <span class="member-chip">
                                            <span class="material-symbols-outlined">group</span>
                                            <?= htmlspecialchars($groupName) ?>
                                        </span>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
