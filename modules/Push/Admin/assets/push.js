/**
 * Push Notifications - User targeting, checkbox management, and bulk operations
 */
document.addEventListener('DOMContentLoaded', function() {
    // Target audience radio buttons
    var targetRadios = document.querySelectorAll('input[name="target_type"]');
    targetRadios.forEach(function(radio) {
        radio.addEventListener('change', toggleUserSelect);
    });

    // Select all checkbox
    var selectAll = document.getElementById('select-all');
    if (selectAll) {
        selectAll.addEventListener('change', function() {
            toggleAllCheckboxes();
        });
    }

    // Toggle all button
    var toggleAllBtn = document.getElementById('toggle-all-btn');
    if (toggleAllBtn) {
        toggleAllBtn.addEventListener('click', function() {
            // Toggle the select-all checkbox first
            var selectAll = document.getElementById('select-all');
            selectAll.checked = !selectAll.checked;
            toggleAllCheckboxes();
        });
    }

    // Bulk delete form
    var bulkDeleteForm = document.getElementById('bulk-delete-form');
    if (bulkDeleteForm) {
        bulkDeleteForm.addEventListener('submit', function(e) {
            var actionValue = e.submitter ? e.submitter.value : '';

            if (actionValue === 'delete_all') {
                if (!confirm('Are you sure you want to delete ALL notification history? This cannot be undone.')) {
                    e.preventDefault();
                }
                return;
            }

            if (actionValue === 'delete_single') {
                if (!confirm('Delete this notification?')) {
                    e.preventDefault();
                }
                return;
            }

            if (actionValue === 'delete_selected') {
                if (!confirmBulkDelete()) {
                    e.preventDefault();
                }
            }
        });
    }

    // Single delete buttons - set the hidden single-delete-id
    document.querySelectorAll('.btn-single-delete').forEach(function(btn) {
        btn.addEventListener('click', function() {
            document.getElementById('single-delete-id').value = this.dataset.notificationId;
        });
    });

    // Convert multi-select to comma-separated value on submit
    var pushForm = document.querySelector('.push-notification-form');
    if (pushForm) {
        pushForm.addEventListener('submit', function() {
            var userSelect = document.getElementById('user_ids');
            var selectedOptions = Array.from(userSelect.selectedOptions);
            var userIds = selectedOptions.map(function(option) { return option.value; }).join(',');

            // Create a hidden input with comma-separated values
            var hiddenInput = document.createElement('input');
            hiddenInput.type = 'hidden';
            hiddenInput.name = 'user_ids';
            hiddenInput.value = userIds;
            this.appendChild(hiddenInput);

            // Clear the select name to avoid sending array
            userSelect.name = '';
        });
    }

    // Filter form auto-submit on change
    document.querySelectorAll('#search, #target_filter, #sort').forEach(function(field) {
        field.addEventListener('change', function() {
            this.form.submit();
        });
    });
});

function toggleUserSelect() {
    var specificRadio = document.querySelector('input[name="target_type"][value="specific"]');
    var userSelectGroup = document.getElementById('user-select-group');
    var userSelect = document.getElementById('user_ids');

    if (specificRadio.checked) {
        userSelectGroup.classList.remove('hidden');
        userSelect.required = true;
    } else {
        userSelectGroup.classList.add('hidden');
        userSelect.required = false;
    }
}

function toggleAllCheckboxes() {
    var checkboxes = document.querySelectorAll('.notification-checkbox');
    var selectAll = document.getElementById('select-all');
    checkboxes.forEach(function(cb) { cb.checked = selectAll.checked; });
}

function confirmBulkDelete() {
    var checked = document.querySelectorAll('.notification-checkbox:checked');
    if (checked.length === 0) {
        alert('Please select at least one notification to delete.');
        return false;
    }
    return confirm('Delete ' + checked.length + ' notification(s)? This cannot be undone.');
}
