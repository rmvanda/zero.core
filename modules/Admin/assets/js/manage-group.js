/**
 * Manage Group Members - Auto-submit role changes and confirm removals
 */
document.addEventListener('DOMContentLoaded', function() {
    // Auto-submit role select changes
    document.querySelectorAll('.role-auto-submit').forEach(function(select) {
        select.addEventListener('change', function() {
            this.form.submit();
        });
    });

    // Confirm member removal
    document.querySelectorAll('.btn-confirm-remove').forEach(function(btn) {
        btn.addEventListener('click', function(e) {
            var memberName = this.dataset.memberName;
            if (!confirm('Remove ' + memberName + ' from this group?')) {
                e.preventDefault();
            }
        });
    });
});
