/**
 * Admin Permissions Management JavaScript: one user's capabilities and settings
 * (posting and toast notifications are in users.js)
 */
document.addEventListener('DOMContentLoaded', function() {
    var screen = document.getElementById('user-edit');
    if (!screen) {
        return;
    }
    var userId = screen.dataset.userId;

    // Event delegation for the capability checkboxes and the setting inputs (number, text)
    screen.addEventListener('change', function(e) {
        var capability = e.target.closest('.capability-input');
        if (capability) {
            setCapability(userId, capability);
            return;
        }
        var setting = e.target.closest('.setting-input');
        if (setting) {
            setSetting(userId, setting);
        }
    });

    // Event delegation for delete setting buttons
    screen.addEventListener('click', function(e) {
        var btn = e.target.closest('.btn-remove-setting');
        if (btn) {
            removeSetting(userId, btn.dataset.key);
        }
    });

    // Grant capability form
    var capabilityForm = document.getElementById('add-capability-form');
    if (capabilityForm) {
        capabilityForm.addEventListener('submit', function(e) {
            e.preventDefault();
            addFromForm('/admin/users/set-capability', {
                user_id: userId,
                key: document.getElementById('new-capability-key').value.trim(),
                value: '1'
            });
        });
    }

    // Add setting form
    var settingForm = document.getElementById('add-setting-form');
    if (settingForm) {
        settingForm.addEventListener('submit', function(e) {
            e.preventDefault();
            addFromForm('/admin/users/set-setting', {
                user_id: userId,
                key: document.getElementById('new-setting-key').value.trim(),
                value: document.getElementById('new-setting-value').value
            });
        });
    }
});

// Grant or revoke the capability of a checkbox. A refusal puts the checkbox back.
async function setCapability(userId, checkbox) {
    var grant = checkbox.checked;
    try {
        await postUserAction('/admin/users/set-capability', {
            user_id: userId,
            key: checkbox.dataset.key,
            value: grant ? '1' : '0'
        });

        // Update checkbox label
        var label = checkbox.parentElement.querySelector('span');
        if (label) {
            label.textContent = grant ? 'Enabled' : 'Disabled';
        }
        var item = checkbox.closest('.permission-item');
        if (item) {
            item.classList.toggle('permission-item-unset', !grant);
        }
        showToast(grant ? 'Capability granted' : 'Capability revoked', 'success');
    } catch (error) {
        console.error('Error:', error);
        checkbox.checked = !grant;
        showToast(error.message || 'Failed to update the capability', 'error');
    }
}

// Save the value of a setting input. A refusal puts the saved value back.
async function setSetting(userId, input) {
    try {
        await postUserAction('/admin/users/set-setting', {
            user_id: userId,
            key: input.dataset.key,
            value: input.value
        });

        var item = input.closest('.permission-item');
        if (item && item.classList.contains('permission-item-unset')) {
            // A setting this user did not have: draw the row again, with its Delete button
            location.reload();
            return;
        }
        input.dataset.saved = input.value;
        showToast('Setting saved', 'success');
    } catch (error) {
        console.error('Error:', error);
        input.value = input.dataset.saved || '';
        showToast(error.message || 'Failed to save the setting', 'error');
    }
}

async function removeSetting(userId, key) {
    if (!confirm('Delete setting "' + key + '"?')) {
        return;
    }

    try {
        await postUserAction('/admin/users/remove-setting', { user_id: userId, key: key });
        location.reload();
    } catch (error) {
        console.error('Error:', error);
        showToast(error.message || 'Failed to delete the setting', 'error');
    }
}

// Post one of the two forms at the foot of the screen; the server draws the new row.
async function addFromForm(url, fields) {
    try {
        await postUserAction(url, fields);
        location.reload();
    } catch (error) {
        console.error('Error:', error);
        showToast(error.message || 'Failed to save', 'error');
    }
}
