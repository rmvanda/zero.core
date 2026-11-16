/**
 * Admin Module - Module-wide JavaScript
 * Auto-loaded for all admin endpoints
 */
document.addEventListener('DOMContentLoaded', function() {
    // Prevent clicks on disabled pagination buttons
    document.addEventListener('click', function(e) {
        var link = e.target.closest('.pagination-btn.disabled');
        if (link) {
            e.preventDefault();
        }
    });
});
