<div class="content-wrapper">
    <h1><?= $this->group ? 'Edit Group' : 'Create New Group' ?></h1>
    <p><a href="/admin/manage/groups">&larr; Back to Groups</a></p>

    <div class="form-container">
        <form method="post" action="<?= $this->group ? '/admin/group-form/' . $this->group->group_id : '/admin/group-form' ?>">
            <div class="admin-form-field">
                <label for="group_name">Group Name *</label>
                <input type="text"
                       name="group_name"
                       id="group_name"
                       value="<?= htmlspecialchars($this->group?->group_name ?? '') ?>"
                       required
                       maxlength="100"
                       placeholder="e.g., Marketing Team, Developers">
                <small>A unique name for this group</small>
            </div>

            <div class="admin-form-field">
                <label for="description">Description</label>
                <textarea name="description"
                          id="description"
                          rows="4"
                          placeholder="Describe the purpose of this group..."><?= htmlspecialchars($this->group?->description ?? '') ?></textarea>
                <small>Optional description of the group's purpose</small>
            </div>

            <div class="admin-form-field">
                <label class="checkbox-label">
                    <input type="checkbox"
                           name="is_active"
                           id="is_active"
                           <?= ($this->group?->is_active ?? 1) ? 'checked' : '' ?>>
                    Active
                </label>
                <small>Inactive groups cannot be used for new assignments</small>
            </div>

            <div class="form-actions">
                <a href="/admin/manage/groups" class="btn-secondary">Cancel</a>
                <button type="submit" class="btn-primary">
                    <?= $this->group ? 'Update Group' : 'Create Group' ?>
                </button>
            </div>
        </form>

        <?php if ($this->group): ?>
            <div class="danger-zone">
                <h3>Danger Zone</h3>
                <p>Permanently delete this group and all member associations.</p>
                <form method="post" action="/admin/delete-group/<?= $this->group->group_id ?>">
                    <input type="hidden" name="hard_delete" value="1">
                    <button type="submit"
                            class="btn-danger btn-confirm-hard-delete">
                        Permanently Delete Group
                    </button>
                </form>
            </div>
        <?php endif; ?>
    </div>
</div>
