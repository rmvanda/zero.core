<?php
// Permissions::holders() loads who holds the capability and who could be granted it.
$key = $this->heldKey;
/** @var array[] $holdingGroups Rows of group_id, group_name, member_count */
$holdingGroups = $this->holdingGroups;
/** @var array[] $directUsers Rows of id, name, email */
$directUsers = $this->directUsers;
/** @var array[] $groupUsers Rows of id, name, email, groups (names) */
$groupUsers = $this->groupUsers;
/** @var \Zero\Core\Access\Group[] $otherGroups */
$otherGroups = $this->otherGroups;
/** @var array[] $otherUsers Rows of id, name, email */
$otherUsers = $this->otherUsers;
$userLabel = fn(array $user) => trim((string) ($user['name'] ?? '')) !== ''
    ? $user['name'] . ' (' . $user['email'] . ')'
    : (string) $user['email'];
?>

<div class="content-wrapper">
    <h1><code><?= htmlspecialchars($key) ?></code></h1>

    <h2>Holders</h2>
    <?php if (empty($holdingGroups) && empty($directUsers)): ?>
        <div class="admin-info-box">Nobody has been granted this capability.</div>
    <?php else: ?>
        <form method="post" action="/admin/permissions/revoke" class="permission-holders-form">
            <input type="hidden" name="key" value="<?= htmlspecialchars($key) ?>">
            <table class="admin-table permissions-table">
                <thead>
                    <tr>
                        <th class="permission-check-col" aria-label="Select"></th>
                        <th>Holder</th>
                        <th>Kind</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($holdingGroups as $group): ?>
                        <?php $id = 'revoke-group-' . (int) $group['group_id']; ?>
                        <tr>
                            <td><input type="checkbox" name="group_ids[]" value="<?= (int) $group['group_id'] ?>" id="<?= $id ?>"></td>
                            <td><label for="<?= $id ?>"><?= htmlspecialchars((string) $group['group_name']) ?></label></td>
                            <td>Group, <?= (int) $group['member_count'] ?> <?= (int) $group['member_count'] === 1 ? 'member' : 'members' ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php foreach ($directUsers as $user): ?>
                        <?php $id = 'revoke-user-' . (int) $user['id']; ?>
                        <tr>
                            <td><input type="checkbox" name="user_ids[]" value="<?= (int) $user['id'] ?>" id="<?= $id ?>"></td>
                            <td><label for="<?= $id ?>"><?= htmlspecialchars($userLabel($user)) ?></label><?= (int) $user['id'] === $this->selfId ? ' <span class="permission-self">you</span>' : '' ?></td>
                            <td>User</td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <div class="permission-actions">
                <button type="submit" class="btn-small btn-danger">Revoke from the selected</button>
            </div>
        </form>
    <?php endif; ?>

    <?php if (!empty($groupUsers)): ?>
        <h2 class="mt-2">Users who have it through a group</h2>
        <p>These keep it until their group loses it, or they leave the group.</p>
        <table class="admin-table permissions-table">
            <thead>
                <tr>
                    <th>User</th>
                    <th>Through</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($groupUsers as $user): ?>
                    <tr>
                        <td><?= htmlspecialchars($userLabel($user)) ?></td>
                        <td><?= htmlspecialchars(implode(', ', $user['groups'])) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <h2 class="mt-2">Grant</h2>
    <?php if (empty($otherGroups) && empty($otherUsers)): ?>
        <div class="admin-info-box">Every user and every active group has been granted this capability.</div>
    <?php else: ?>
        <form method="post" action="/admin/permissions/grant" class="admin-info-box permission-grant-form">
            <input type="hidden" name="key" value="<?= htmlspecialchars($key) ?>">
            <div class="admin-form-grid">
                <div class="admin-form-field">
                    <label for="grant-groups">Groups</label>
                    <select name="group_ids[]" id="grant-groups" multiple size="6" class="permission-picker">
                        <?php foreach ($otherGroups as $group): ?>
                            <option value="<?= (int) $group->group_id ?>"><?= htmlspecialchars((string) $group->group_name) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="admin-form-field">
                    <label for="grant-users">Users</label>
                    <select name="user_ids[]" id="grant-users" multiple size="6" class="permission-picker">
                        <?php foreach ($otherUsers as $user): ?>
                            <option value="<?= (int) $user['id'] ?>"><?= htmlspecialchars($userLabel($user)) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <small>Hold Ctrl or Cmd to choose several.</small>
                </div>
            </div>
            <div class="permission-actions">
                <button type="submit" class="btn-primary">Grant to the selected</button>
            </div>
        </form>
    <?php endif; ?>
</div>
