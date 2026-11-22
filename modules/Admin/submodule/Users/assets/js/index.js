/**
 * Manage Users - Expandable table rows, and disabling and enabling a user
 * (posting and toast notifications are in users.js)
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

    // Event delegation for the disable / enable buttons
    document.addEventListener('click', function(e) {
        var btn = e.target.closest('.btn-disable[data-user-id]');
        if (btn) {
            setUserDisabled(btn, btn.dataset.userId, btn.dataset.userName, btn.dataset.disabled !== '1');
        }
    });
});

// Disable or enable a user. Nothing is deleted, so one click is enough: the same button undoes it.
async function setUserDisabled(buttonElement, userId, userName, disable) {
    try {
        await postUserAction(disable ? '/admin/users/disable' : '/admin/users/enable', { user_id: userId });

        // Show the new state on the row and on the button
        var row = buttonElement.closest('tr');
        if (row) {
            row.classList.toggle('user-disabled', disable);
        }
        buttonElement.dataset.disabled = disable ? '1' : '0';
        buttonElement.title = disable ? 'Enable user' : 'Disable user';
        buttonElement.querySelector('.material-symbols-outlined').textContent = disable ? 'person_check' : 'person_off';
        showToast('User ' + userName + (disable ? ' disabled' : ' enabled'), 'success');
    } catch (error) {
        console.error('Error changing user:', error);
        showToast(error.message || 'Failed to change the user', 'error');
    }
}
