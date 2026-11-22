<div class="content-wrapper">
    <h1>User Management</h1>

    <div class="mt-2">
        <table class="admin-table expandable-table users-table">
            <thead>
                <tr>
                    <th class="col-expand"></th>
                    <th>User</th>
                    <th>Email</th>
                    <th class="text-center">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($this->userRows as $user): ?>
                <?php $name = htmlspecialchars($user['name'] ?? 'Unknown', ENT_QUOTES); ?>
                <tr class="main-row<?= $user['disabled'] ? ' user-disabled' : '' ?>" data-user-id="<?= (int) $user['id'] ?>">
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
                            <span><?= $name ?></span>
                            <span class="status-badge status-inactive user-disabled-badge">Disabled</span>
                        </div>
                    </td>
                    <td>
                        <?= htmlspecialchars((string) $user['email']) ?>
                    </td>
                    <td class="text-center actions-cell">
                        <a href="/admin/users/edit/<?= (int) $user['id'] ?>"
                           class="btn-small">
                            Permissions
                        </a>
                        <button type="button"
                                class="btn-icon btn-disable"
                                data-user-id="<?= (int) $user['id'] ?>"
                                data-user-name="<?= $name ?>"
                                data-disabled="<?= $user['disabled'] ? '1' : '0' ?>"
                                title="<?= $user['disabled'] ? 'Enable user' : 'Disable user' ?>">
                            <span class="material-symbols-outlined"><?= $user['disabled'] ? 'person_check' : 'person_off' ?></span>
                        </button>
                    </td>
                </tr>
                <tr class="details-row" data-user-id="<?= (int) $user['id'] ?>">
                    <td colspan="4" class="details-cell">
                        <div class="details-content">
                            <div class="details-grid details-grid-3col">
                                <div class="detail-item">
                                    <span class="detail-label">User ID</span>
                                    <span class="detail-value"><?= (int) $user['id'] ?></span>
                                </div>
                                <div class="detail-item">
                                    <span class="detail-label">Verified</span>
                                    <span class="detail-value"><?= $user['verified'] ? 'Yes' : 'No' ?></span>
                                </div>
                                <div class="detail-item">
                                    <span class="detail-label">Permissions</span>
                                    <span class="detail-value"><?= (int) $user['capabilityCount'] ?> capabilities</span>
                                </div>
                            </div>
                            <?php if (!empty($user['groups'])): ?>
                            <div class="group-members-list">
                                <span class="detail-label">Groups</span>
                                <div class="members-chips">
                                    <?php foreach ($user['groups'] as $groupName): ?>
                                        <span class="member-chip">
                                            <span class="material-symbols-outlined">group</span>
                                            <?= htmlspecialchars((string) $groupName) ?>
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

    <nav class="pagination" aria-label="Pages of users">
        <span class="pagination-info">
            <?= (int) $this->userTotal ?> <?= (int) $this->userTotal === 1 ? 'user' : 'users' ?>
        </span>
        <div class="pagination-controls">
            <?php if ($this->listPage > 1): ?>
                <a class="pagination-btn" rel="prev" href="/admin/users?page=<?= (int) $this->listPage - 1 ?>">Previous</a>
            <?php else: ?>
                <span class="pagination-btn disabled" aria-disabled="true">Previous</span>
            <?php endif; ?>
            <span class="pagination-current">Page <?= (int) $this->listPage ?> of <?= (int) $this->listPages ?></span>
            <?php if ($this->listPage < $this->listPages): ?>
                <a class="pagination-btn" rel="next" href="/admin/users?page=<?= (int) $this->listPage + 1 ?>">Next</a>
            <?php else: ?>
                <span class="pagination-btn disabled" aria-disabled="true">Next</span>
            <?php endif; ?>
        </div>
    </nav>
</div>
