/**
 * Group Form - Hard delete confirmation
 */
document.addEventListener('DOMContentLoaded', function() {
    var hardDeleteBtn = document.querySelector('.btn-confirm-hard-delete');
    if (hardDeleteBtn) {
        hardDeleteBtn.addEventListener('click', function(e) {
            if (!confirm('PERMANENTLY delete this group? This cannot be undone. All members will be removed from the group.')) {
                e.preventDefault();
            }
        });
    }
});
