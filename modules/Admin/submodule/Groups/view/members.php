<?php
// Groups::members() loads the group, its members and everyone who is not one,
// and answers 404 when there is no such group.
/** @var \Zero\Core\Access\Group $group */
$group = $this->group;
$groupId = (int) $group->group_id;

$members = $this->members;
$nonMembers = $this->nonMembers;
?>

<div class="content-wrapper">
    <h1>Manage Group: <?= htmlspecialchars($group->group_name) ?></h1>
    <p>
        <a href="/admin/groups/form/<?= $groupId ?>">Edit Group Details</a>
    </p>

    <div class="group-info-box">
        <h3>Group Information</h3>
        <p><strong>Description:</strong> <?= htmlspecialchars($group->description ?? 'No description') ?></p>
        <p><strong>Total Members:</strong> <?= number_format(count($members)) ?></p>
        <p><strong>Created:</strong> <?= date('Y-m-d H:i', strtotime($group->created_at)) ?> by <?= htmlspecialchars($this->createdByName) ?></p>
        <p><strong>Status:</strong>
            <span class="event-badge <?= $group->is_active ? 'active' : 'inactive' ?>">
                <?= $group->is_active ? 'Active' : 'Inactive' ?>
            </span>
        </p>
    </div>

    <!-- Add Members Form -->
    <?php if (!empty($nonMembers)): ?>
        <div class="add-member-section">
            <h2>Add Members</h2>
            <form method="post" action="/admin/groups/add-member" class="add-member-form">
                <input type="hidden" name="group_id" value="<?= $groupId ?>">

                <div class="form-row">
                    <div class="form-field">
                        <label for="user_id">Select User</label>
                        <select name="user_id" id="user_id" required>
                            <option value="">Choose a user...</option>
                            <?php foreach ($nonMembers as $user): ?>
                                <option value="<?= (int) $user['user_id'] ?>">
                                    <?= htmlspecialchars($user['username']) ?>
                                    (<?= htmlspecialchars($user['email']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-field">
                        <label for="role">Role</label>
                        <select name="role" id="role" required>
                            <option value="member">Member</option>
                            <option value="admin">Admin</option>
                            <option value="owner">Owner</option>
                        </select>
                    </div>

                    <div class="form-field">
                        <label>&nbsp;</label>
                        <button type="submit" class="btn-primary">Add User</button>
                    </div>
                </div>
            </form>
        </div>
    <?php else: ?>
        <div class="admin-info-box">
            All users are already members of this group.
        </div>
    <?php endif; ?>

    <!-- Current Members Table -->
    <h2 class="mt-2">Current Members (<?= count($members) ?>)</h2>
    <table class="admin-table">
        <thead>
            <tr>
                <th>User ID</th>
                <th>Username</th>
                <th>Email</th>
                <th>Role</th>
                <th>Added By</th>
                <th>Added At</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($members)): ?>
                <tr>
                    <td colspan="7" class="empty-state-cell">
                        No members in this group yet.
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($members as $member): ?>
                    <tr>
                        <td><?= htmlspecialchars($member['user_id']) ?></td>
                        <td><strong><?= htmlspecialchars($member['username']) ?></strong></td>
                        <td><?= htmlspecialchars($member['email']) ?></td>
                        <td>
                            <form method="post" action="/admin/groups/set-member-role" class="form-inline">
                                <input type="hidden" name="group_id" value="<?= $groupId ?>">
                                <input type="hidden" name="user_id" value="<?= (int) $member['user_id'] ?>">
                                <select name="role" class="role-select role-auto-submit">
                                    <option value="member" <?= $member['role'] === 'member' ? 'selected' : '' ?>>Member</option>
                                    <option value="admin" <?= $member['role'] === 'admin' ? 'selected' : '' ?>>Admin</option>
                                    <option value="owner" <?= $member['role'] === 'owner' ? 'selected' : '' ?>>Owner</option>
                                </select>
                            </form>
                        </td>
                        <td><?= htmlspecialchars($member['added_by_username'] ?? 'System') ?></td>
                        <td><?= date('Y-m-d H:i', strtotime($member['added_at'])) ?></td>
                        <td>
                            <form method="post" action="/admin/groups/remove-member" class="form-inline">
                                <input type="hidden" name="group_id" value="<?= $groupId ?>">
                                <input type="hidden" name="user_id" value="<?= (int) $member['user_id'] ?>">
                                <button type="submit" class="btn-small btn-danger btn-confirm-remove" data-member-name="<?= htmlspecialchars($member['username'], ENT_QUOTES) ?>">
                                    Remove
                                </button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>
