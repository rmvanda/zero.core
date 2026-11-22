<?php
// Groups::index() loads every group and each group's members.
/** @var \Zero\Core\Access\Group[] $groups */
$groups = $this->groups;
/** @var array<int,array> $groupMembers Membership rows by group id */
$groupMembers = $this->groupMembers;
?>

<div class="content-wrapper">
    <h1>Group Management</h1>

    <div class="action-bar">
        <a href="/admin/groups/form" class="btn-primary">Create New Group</a>
    </div>

    <?php if (empty($groups)): ?>
        <div class="admin-info-box text-center">
            No groups found. <a href="/admin/groups/form">Create your first group</a>
        </div>
    <?php else: ?>
        <table class="admin-table expandable-table">
            <thead>
                <tr>
                    <th class="expand-col"></th>
                    <th>Status</th>
                    <th>Group Name</th>
                    <th>Members</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($groups as $group): ?>
                    <tr class="main-row" data-group-id="<?= (int) $group->group_id ?>">
                        <td>
                            <span class="material-symbols-outlined expand-icon">chevron_right</span>
                        </td>
                        <td>
                            <span class="status-badge status-<?= $group->is_active ? 'active' : 'inactive' ?>">
                                <?= $group->is_active ? 'Active' : 'Inactive' ?>
                            </span>
                        </td>
                        <td>
                            <strong><?= htmlspecialchars($group->group_name) ?></strong>
                        </td>
                        <td><?= number_format((int) $group->member_count) ?></td>
                        <td class="actions-cell">
                            <a href="/admin/groups/members/<?= (int) $group->group_id ?>" class="btn-small">Manage</a>
                            <?php if ($group->is_active): ?>
                                <button type="button" class="btn-small btn-danger btn-deactivate-group" data-group-id="<?= (int) $group->group_id ?>">Deactivate</button>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr class="details-row" data-group-id="<?= (int) $group->group_id ?>">
                        <td colspan="5" class="details-cell">
                            <div class="details-content">
                                <!-- Description, Created By, Created At on a single row -->
                                <div class="details-grid details-grid-3col">
                                    <div class="detail-item">
                                        <span class="detail-label">Description</span>
                                        <span class="detail-value"><?= htmlspecialchars($group->description ?? 'No description') ?></span>
                                    </div>
                                    <div class="detail-item">
                                        <span class="detail-label">Created By</span>
                                        <span class="detail-value"><?= htmlspecialchars($group->created_by_username ?? 'System') ?></span>
                                    </div>
                                    <div class="detail-item">
                                        <span class="detail-label">Created At</span>
                                        <span class="detail-value"><?= date('Y-m-d H:i', strtotime($group->created_at)) ?></span>
                                    </div>
                                </div>
                                <!-- Group members list -->
                                <?php $members = $groupMembers[$group->group_id] ?? []; ?>
                                <?php if (!empty($members)): ?>
                                    <div class="group-members-list">
                                        <span class="detail-label">Members</span>
                                        <div class="members-chips">
                                            <?php foreach ($members as $member): ?>
                                                <span class="member-chip">
                                                    <span class="material-symbols-outlined">person</span>
                                                    <?= htmlspecialchars($member['username']) ?>
                                                    <?php if ($member['role'] === 'admin'): ?>
                                                        <span class="member-role">admin</span>
                                                    <?php endif; ?>
                                                </span>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                <?php else: ?>
                                    <div class="group-members-list">
                                        <span class="detail-label">Members</span>
                                        <span class="detail-value">No members</span>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
