/**
 * Manage Users - Expandable table rows, user deletion, and toast notifications
 */
document.addEventListener('DOMContentLoaded', function() {
    // Expandable table rows
    document.querySelectorAll('.expandable-table .main-row').forEach(function(row) {
        row.addEventListener('click', function(e) {
            if (e.target.closest('a') || e.target.closest('button')) return;
            var userId = this.dataset.userId;
            var detailsRow = document.querySelector('.details-row[data-user-id="' + userId + '"]');
            var icon = this.querySelector('.expand-icon');
            if (detailsRow.classList.contains('expanded')) {
                detailsRow.classList.remove('expanded');
                icon.textContent = 'chevron_right';
            } else {
                detailsRow.classList.add('expanded');
                icon.textContent = 'expand_more';
            }
        });
    });

    // Event delegation for delete buttons
    document.addEventListener('click', function(e) {
        var btn = e.target.closest('.btn-delete[data-user-id]');
        if (btn) {
            var userId = btn.dataset.userId;
            var userName = btn.dataset.userName;
            prepareDeleteUser(btn, userId, userName);
        }
    });
});

// Toast Notification System
function showToast(message, type) {
    type = type || 'success';
    var existingToast = document.querySelector('.toast-notification');
    if (existingToast) {
        existingToast.remove();
    }

    var toast = document.createElement('div');
    toast.className = 'toast-notification toast-' + type;

    toast.innerHTML =
        '<div class="toast-indicator"></div>' +
        '<div class="toast-content">' +
            '<span class="material-symbols-outlined toast-icon">' + getToastIcon(type) + '</span>' +
            '<span class="toast-message">' + escapeHtml(message) + '</span>' +
        '</div>';

    document.body.appendChild(toast);

    setTimeout(function() { toast.classList.add('show'); }, 10);

    setTimeout(function() {
        toast.classList.remove('show');
        setTimeout(function() { toast.remove(); }, 300);
    }, 3000);
}

function getToastIcon(type) {
    var icons = {
        'success': 'check_circle',
        'error': 'error',
        'warning': 'warning',
        'info': 'info'
    };
    return icons[type] || icons['info'];
}

// Delete User with Double-Click Confirmation
var deleteConfirmations = {};

function prepareDeleteUser(buttonElement, userId, userName) {
    // Check if already in confirmation state
    if (deleteConfirmations[userId]) {
        // Second click - actually delete
        delete deleteConfirmations[userId];
        deleteUser(userId, userName, buttonElement);
    } else {
        // First click - prepare for deletion
        deleteConfirmations[userId] = true;

        // Change icon and add highlight
        var icon = buttonElement.querySelector('.material-symbols-outlined');
        icon.textContent = 'delete_forever';
        buttonElement.classList.add('delete-confirm');

        // Show toast
        showToast('Click again to confirm deletion', 'warning');

        // Reset after 3 seconds
        setTimeout(function() {
            if (deleteConfirmations[userId]) {
                delete deleteConfirmations[userId];
                icon.textContent = 'delete';
                buttonElement.classList.remove('delete-confirm');
            }
        }, 3000);
    }
}

async function deleteUser(userId, userName, buttonElement) {
    try {
        var formData = new FormData();
        formData.append('user_id', userId);

        var response = await fetch('/admin/delete-user', {
            method: 'POST',
            headers: {
                'Accept': 'application/json'
            },
            body: formData
        });

        var result = await response.json();
        var data = result.data || result;

        if (data.success) {
            // Remove the user row from the table with animation
            var row = buttonElement.closest('tr');
            if (row) {
                row.classList.add('row-removing');
                setTimeout(function() {
                    row.remove();
                }, 300);
            }
            showToast('User ' + userName + ' deleted successfully', 'success');
        } else {
            // Reset button on error
            if (buttonElement) {
                var icon = buttonElement.querySelector('.material-symbols-outlined');
                icon.textContent = 'delete';
                buttonElement.classList.remove('delete-confirm');
            }
            showToast(data.message || 'Failed to delete user', 'error');
        }
    } catch (error) {
        console.error('Error deleting user:', error);
        // Reset button on error
        if (buttonElement) {
            var icon = buttonElement.querySelector('.material-symbols-outlined');
            icon.textContent = 'delete';
            buttonElement.classList.remove('delete-confirm');
        }
        showToast('Failed to delete user. Please try again.', 'error');
    }
}

// Utility function to escape HTML
function escapeHtml(text) {
    var map = {
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;'
    };
    return text.replace(/[&<>"']/g, function(m) { return map[m]; });
}


// --------------------------------------------------------------------------
// Single-user permissions edit page (manage-user.php) logic.
// Also loaded here because /admin/manage/users/<id> renders that view but
// (by the activeEndpoint convention) pulls assets named manage-users.*.
// Kept in sync with manage-user.js, which serves the singular
// /admin/manage/user/<id> URL.
// --------------------------------------------------------------------------
/**
 * Admin Permissions Management JavaScript
 */
document.addEventListener('DOMContentLoaded', function() {
    // Event delegation for permission inputs (checkbox, number, text)
    document.addEventListener('change', function(e) {
        var input = e.target.closest('.permission-input');
        if (input) {
            updatePermission(input);
        }
    });

    // Event delegation for delete permission buttons
    document.addEventListener('click', function(e) {
        var btn = e.target.closest('.btn-delete-permission');
        if (btn) {
            deletePermission(btn.dataset.userId, btn.dataset.settingKey);
        }
    });

    // Add permission form
    var addForm = document.getElementById('add-permission-form');
    if (addForm) {
        addForm.addEventListener('submit', function(e) {
            e.preventDefault();
            addPermission(addForm.dataset.userId);
        });
    }
});

function updatePermission(element) {
    var userId = element.getAttribute('data-user-id');
    var settingKey = element.getAttribute('data-setting-key');
    var settingValue;

    if (element.type === 'checkbox') {
        settingValue = element.checked ? '1' : '0';
    } else {
        settingValue = element.value;
    }

    fetch('/admin/update-permission', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'Accept': 'application/json'
        },
        body: 'user_id=' + userId + '&setting_key=' + encodeURIComponent(settingKey) + '&setting_value=' + encodeURIComponent(settingValue)
    })
    .then(function(response) { return response.json(); })
    .then(function(result) {
        var data = result.data || result;  // Unwrap Zero framework response
        if (data.success) {
            // Update checkbox label if it's a checkbox
            if (element.type === 'checkbox') {
                var label = element.parentElement.querySelector('span');
                if (label) {
                    label.textContent = element.checked ? 'Enabled' : 'Disabled';
                }
            }
            showSuccessMessage('Permission updated');
        } else {
            alert(data.message || 'Failed to update permission');
            location.reload();
        }
    })
    .catch(function(error) {
        console.error('Error:', error);
        alert('Failed to update permission');
        location.reload();
    });
}

function deletePermission(userId, settingKey) {
    if (!confirm('Delete permission "' + settingKey + '"?')) {
        return;
    }

    fetch('/admin/update-permission', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'Accept': 'application/json'
        },
        body: 'user_id=' + userId + '&setting_key=' + encodeURIComponent(settingKey) + '&action=delete'
    })
    .then(function(response) { return response.json(); })
    .then(function(result) {
        var data = result.data || result;  // Unwrap Zero framework response
        if (data.success) {
            location.reload();
        } else {
            alert('Failed to delete permission');
        }
    })
    .catch(function(error) {
        console.error('Error:', error);
        alert('Failed to delete permission');
    });
}

function addPermission(userId) {
    var settingKey = document.getElementById('new-setting-key').value;
    var settingValue = document.getElementById('new-setting-value').value;

    fetch('/admin/update-permission', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'Accept': 'application/json'
        },
        body: 'user_id=' + userId + '&setting_key=' + encodeURIComponent(settingKey) + '&setting_value=' + encodeURIComponent(settingValue)
    })
    .then(function(response) { return response.json(); })
    .then(function(result) {
        var data = result.data || result;  // Unwrap Zero framework response
        if (data.success) {
            // Clear the form
            document.getElementById('new-setting-key').value = '';
            document.getElementById('new-setting-value').value = '';

            // Add the new permission to the list dynamically
            addPermissionToList(userId, settingKey, settingValue);

            // Show success message
            showSuccessMessage('Permission added successfully');
        } else {
            alert(data.message || 'Failed to add permission');
        }
    })
    .catch(function(error) {
        console.error('Error:', error);
        alert('Failed to add permission');
    });
}

function addPermissionToList(userId, settingKey, settingValue) {
    // Determine type based on key prefix
    var type = 'text';
    if (settingKey.startsWith('allow.')) type = 'boolean';
    else if (settingKey.startsWith('limit.') || settingKey.startsWith('auth.')) type = 'integer';

    // Create the permission item HTML
    var permissionsList = document.getElementById('permissions-list');

    // Remove "No permissions" message if it exists
    var noPermsMsg = permissionsList.querySelector('p');
    if (noPermsMsg && noPermsMsg.textContent.includes('No permissions')) {
        noPermsMsg.remove();
    }

    var permDiv = document.createElement('div');
    permDiv.className = 'permission-item';

    var now = new Date().toISOString().slice(0, 16).replace('T', ' ');

    // Build controls using DOM instead of innerHTML with event handlers
    var controlsHtml = '';
    if (type === 'boolean') {
        var checked = settingValue == '1' ? 'checked' : '';
        var labelText = settingValue == '1' ? 'Enabled' : 'Disabled';
        controlsHtml =
            '<label class="admin-checkbox-label">' +
                '<input type="checkbox"' +
                       ' data-user-id="' + userId + '"' +
                       ' data-setting-key="' + settingKey + '"' +
                       ' ' + checked +
                       ' class="permission-input">' +
                '<span>' + labelText + '</span>' +
            '</label>';
    } else if (type === 'integer') {
        controlsHtml =
            '<input type="number"' +
                   ' value="' + settingValue + '"' +
                   ' data-user-id="' + userId + '"' +
                   ' data-setting-key="' + settingKey + '"' +
                   ' class="admin-input admin-input-narrow permission-input">';
    } else {
        controlsHtml =
            '<input type="text"' +
                   ' value="' + settingValue + '"' +
                   ' data-user-id="' + userId + '"' +
                   ' data-setting-key="' + settingKey + '"' +
                   ' class="admin-input admin-input-medium permission-input">';
    }

    permDiv.innerHTML =
        '<div class="permission-item-info">' +
            '<strong>' + settingKey + '</strong>' +
            '<br>' +
            '<small class="permission-meta">Type: ' + type + ' | Updated: ' + now + '</small>' +
        '</div>' +
        '<div class="permission-item-controls">' +
            controlsHtml +
            '<button data-user-id="' + userId + '"' +
                    ' data-setting-key="' + settingKey + '"' +
                    ' class="admin-btn-delete btn-delete-permission">' +
                'Delete' +
            '</button>' +
        '</div>';

    permissionsList.appendChild(permDiv);
}

function showSuccessMessage(message) {
    // Create a temporary success message
    var msgDiv = document.createElement('div');
    msgDiv.className = 'success-toast';
    msgDiv.textContent = message;
    document.body.appendChild(msgDiv);

    setTimeout(function() {
        msgDiv.classList.add('fading');
        setTimeout(function() { msgDiv.remove(); }, 300);
    }, 3000);
}
