/**
 * Manage Groups - Expandable table rows, group deactivation, and toast notifications
 */
document.addEventListener('DOMContentLoaded', function() {
    // Expandable table rows
    var mainRows = document.querySelectorAll('.expandable-table .main-row');

    mainRows.forEach(function(row) {
        row.addEventListener('click', function(e) {
            // Don't expand if clicking on a link or button
            if (e.target.closest('a') || e.target.closest('button')) {
                return;
            }

            var groupId = this.dataset.groupId;
            var detailsRow = document.querySelector('.details-row[data-group-id="' + groupId + '"]');
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

    // Event delegation for deactivate buttons
    document.addEventListener('click', function(e) {
        var btn = e.target.closest('.btn-deactivate-group');
        if (btn) {
            deactivateGroup(btn.dataset.groupId, btn);
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

    var icons = {
        'success': 'check_circle',
        'error': 'error',
        'warning': 'warning',
        'info': 'info'
    };

    toast.innerHTML =
        '<div class="toast-indicator"></div>' +
        '<div class="toast-content">' +
            '<span class="material-symbols-outlined toast-icon">' + (icons[type] || icons['info']) + '</span>' +
            '<span class="toast-message">' + message + '</span>' +
        '</div>';

    document.body.appendChild(toast);
    setTimeout(function() { toast.classList.add('show'); }, 10);
    setTimeout(function() {
        toast.classList.remove('show');
        setTimeout(function() { toast.remove(); }, 300);
    }, 3000);
}

// Deactivate group via fetch
async function deactivateGroup(groupId, buttonElement) {
    if (!confirm('Deactivate this group? Members will be unaffected.')) {
        return;
    }

    buttonElement.disabled = true;
    buttonElement.textContent = 'Deactivating...';

    try {
        var formData = new FormData();

        var response = await fetch('/admin/delete-group/' + groupId, {
            method: 'POST',
            headers: {
                'Accept': 'application/json'
            },
            body: formData
        });

        var result = await response.json();
        var data = result.data || result;

        if (data.success) {
            showToast(data.message || 'Group deactivated', 'success');

            // Update the status badge in the row
            var row = buttonElement.closest('tr');
            var badge = row.querySelector('.status-badge');
            if (badge) {
                badge.className = 'status-badge status-inactive';
                badge.textContent = 'Inactive';
            }

            // Remove the deactivate button
            buttonElement.remove();
        } else {
            showToast(data.error || 'Failed to deactivate group', 'error');
            buttonElement.disabled = false;
            buttonElement.textContent = 'Deactivate';
        }
    } catch (error) {
        console.error('Error deactivating group:', error);
        showToast('Failed to deactivate group. Please try again.', 'error');
        buttonElement.disabled = false;
        buttonElement.textContent = 'Deactivate';
    }
}
