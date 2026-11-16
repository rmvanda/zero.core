/**
 * Manage Entity - Inline editing, bulk operations, and checkbox management
 */
document.addEventListener('DOMContentLoaded', function() {
    // Toggle all checkboxes
    var selectAll = document.getElementById('selectAll');
    if (selectAll) {
        selectAll.addEventListener('change', function() {
            var checkboxes = document.querySelectorAll('.row-checkbox');
            checkboxes.forEach(function(cb) { cb.checked = selectAll.checked; });
        });
    }

    // Bulk delete confirmation
    var bulkDeleteForm = document.getElementById('bulkDeleteForm');
    if (bulkDeleteForm) {
        bulkDeleteForm.addEventListener('submit', function(e) {
            var checked = document.querySelectorAll('.row-checkbox:checked');
            if (checked.length === 0) {
                alert('Please select at least one record to delete');
                e.preventDefault();
                return;
            }
            if (!confirm('Delete ' + checked.length + ' selected record' + (checked.length === 1 ? '' : 's') + '?')) {
                e.preventDefault();
            }
        });
    }

    // Event delegation for editable cells
    document.addEventListener('click', function(e) {
        var cell = e.target.closest('.editable-cell');
        if (cell) {
            makeEditable(cell);
        }
    });

    // Individual delete form confirmation
    document.querySelectorAll('.form-inline').forEach(function(form) {
        form.addEventListener('submit', function(e) {
            if (!confirm('Delete this record?')) {
                e.preventDefault();
            }
        });
    });
});

// Make cell editable on click
function makeEditable(cell) {
    var entityId = cell.dataset.entityId;
    var attribute = cell.dataset.attribute;
    var currentValue = cell.dataset.value;
    var entityType = cell.dataset.entityType;

    // Don't edit if already editing
    if (cell.querySelector('input')) {
        return;
    }

    // Create input
    var input = document.createElement('input');
    input.type = 'text';
    input.value = currentValue;
    input.className = 'inline-edit-input';

    // Save on blur or Enter
    var saveEdit = async function() {
        var newValue = input.value;

        // Restore original if unchanged
        if (newValue === currentValue) {
            cell.textContent = currentValue;
            return;
        }

        // Show loading state
        cell.textContent = 'Saving...';

        try {
            var response = await fetch('/admin/entity/update', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: new URLSearchParams({
                    entity_type: entityType,
                    entity_id: entityId,
                    attribute: attribute,
                    value: newValue
                })
            });

            var result = await response.json();
            var data = result.data || result;

            if (data.success) {
                cell.dataset.value = newValue;
                cell.textContent = newValue;
            } else {
                alert('Failed to save: ' + (data.message || 'Unknown error'));
                cell.textContent = currentValue;
            }
        } catch (error) {
            alert('Failed to save: ' + error.message);
            cell.textContent = currentValue;
        }
    };

    input.addEventListener('blur', saveEdit);
    input.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            input.blur();
        } else if (e.key === 'Escape') {
            e.preventDefault();
            cell.textContent = currentValue;
        }
    });

    // Replace content with input
    cell.textContent = '';
    cell.appendChild(input);
    input.focus();
    input.select();
}
