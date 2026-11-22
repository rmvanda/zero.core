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
    <header class="admin-head">
        <div>
            <h1><?= htmlspecialchars($group->group_name) ?></h1>
            <p class="admin-lede"><?= htmlspecialchars(trim((string) $group->description) !== '' ? $group->description : 'This group has no description.') ?></p>
        </div>
        <div class="admin-head-actions">
            <a href="/admin/groups/form/<?= $groupId ?>" class="btn-secondary">
                <span class="material-symbols-outlined" aria-hidden="true">edit</span>
                Edit group
            </a>
        </div>
    </header>

    <dl class="admin-panel admin-facts">
        <div>
            <dt>Status</dt>
            <dd>
                <span class="status-badge status-<?= $group->is_active ? 'active' : 'inactive' ?>">
                    <?= $group->is_active ? 'Active' : 'Inactive' ?>
                </span>
            </dd>
        </div>
        <div>
            <dt>Members</dt>
            <dd><?= number_format(count($members)) ?></dd>
        </div>
        <div>
            <dt>Created</dt>
            <dd><?= date('Y-m-d H:i', strtotime($group->created_at)) ?> by <?= htmlspecialchars($this->createdByName) ?></dd>
        </div>
    </dl>

    <!-- Add Members Form -->
    <?php if (!empty($nonMembers)): ?>
        <section class="admin-section">
            <h2>Add a member</h2>
            <form method="post" action="/admin/groups/add-member" class="admin-panel admin-form-row add-member-form">
                <input type="hidden" name="group_id" value="<?= $groupId ?>">

                    <div class="admin-form-field">
                        <label for="user_id">User</label>
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

                    <div class="admin-form-field add-member-role">
                        <label for="role">Role</label>
                        <select name="role" id="role" required>
                            <option value="member">Member</option>
                            <option value="admin">Admin</option>
                            <option value="owner">Owner</option>
                        </select>
                    </div>

                    <button type="submit" class="btn-primary admin-form-submit">Add member</button>
            </form>
        </section>
    <?php else: ?>
        <div class="admin-info-box">
            All users are already members of this group.
        </div>
    <?php endif; ?>

    <!-- Current Members Table -->
    <section class="admin-section">
    <h2>Members <span class="admin-count"><?= count($members) ?></span></h2>
    <div class="admin-table-wrap">
    <table class="admin-table">
        <thead>
            <tr>
                <th>User</th>
                <th>Email</th>
                <th>Role</th>
                <th>Added by</th>
                <th>Added</th>
                <th class="text-right">Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($members)): ?>
                <tr>
                    <td colspan="6" class="empty-state-cell">
                        Nobody is in this group yet.
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($members as $member): ?>
                    <tr>
                        <td><a href="/admin/users/edit/<?= (int) $member['user_id'] ?>"><strong><?= htmlspecialchars($member['username']) ?></strong></a></td>
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
                        <td class="dim"><?= htmlspecialchars($member['added_by_username'] ?? 'System') ?></td>
                        <td class="dim nowrap"><?= date('Y-m-d H:i', strtotime($member['added_at'])) ?></td>
                        <td class="actions-cell">
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
    </section>
</div>
