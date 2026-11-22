<div class="content-wrapper">
    <header class="admin-head">
        <div>
            <h1><?= $this->group ? 'Edit group' : 'New group' ?></h1>
            <p class="admin-lede">A group holds capabilities for everyone in it.</p>
        </div>
    </header>

    <div class="admin-panel form-container">
        <form method="post" action="<?= $this->group ? '/admin/groups/save/' . (int) $this->group->group_id : '/admin/groups/save' ?>">
            <div class="admin-form-field">
                <label for="group_name">Group name</label>
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
                <small>Optional. Shown beside the group wherever it is listed.</small>
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
                <a href="/admin/groups" class="btn-secondary">Cancel</a>
                <button type="submit" class="btn-primary">
                    <?= $this->group ? 'Save group' : 'Create group' ?>
                </button>
            </div>
        </form>

        <?php if ($this->group): ?>
            <div class="danger-zone">
                <h3>Delete this group</h3>
                <p>Removes the group and takes everyone out of it. This cannot be undone.</p>
                <form method="post" action="/admin/groups/delete/<?= (int) $this->group->group_id ?>">
                    <input type="hidden" name="hard_delete" value="1">
                    <button type="submit"
                            class="btn-danger btn-confirm-hard-delete">
                        Delete group
                    </button>
                </form>
            </div>
        <?php endif; ?>
    </div>
</div>
