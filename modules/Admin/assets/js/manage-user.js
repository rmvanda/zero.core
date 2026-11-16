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
            deletePermission(btn.dataset.userId, btn.dataset.settingKey, btn.dataset.kind);
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

    // A capability checkbox says so; anything else is a setting.
    var kind = element.dataset.kind ? '&kind=' + encodeURIComponent(element.dataset.kind) : '';

    fetch('/admin/update-permission', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'Accept': 'application/json'
        },
        body: 'user_id=' + userId + '&setting_key=' + encodeURIComponent(settingKey) + '&setting_value=' + encodeURIComponent(settingValue) + kind
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

function deletePermission(userId, settingKey, kind) {
    if (!confirm('Delete permission "' + settingKey + '"?')) {
        return;
    }

    fetch('/admin/update-permission', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'Accept': 'application/json'
        },
        body: 'user_id=' + userId + '&setting_key=' + encodeURIComponent(settingKey) + '&action=delete' + (kind ? '&kind=' + encodeURIComponent(kind) : '')
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
                       ' data-kind="capability"' +
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
                    (type === 'boolean' ? ' data-kind="capability"' : '') +
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
