/**
 * Manage Modules - saves a row through fetch and shows the answer in the status line.
 * Without script the forms post normally and the server redraws the screen.
 */
document.addEventListener('DOMContentLoaded', function() {
    var status = document.querySelector('.modules-status');

    function show(message, isError) {
        if (!status) {
            return;
        }
        status.textContent = message;
        status.classList.toggle('modules-error', isError);
        status.hidden = false;
    }

    document.querySelectorAll('.modules-table .module-form').forEach(function(form) {
        form.addEventListener('submit', async function(e) {
            e.preventDefault();
            var body = new FormData(form);
            // The state select sits in another cell and belongs to the form by its form attribute
            try {
                var response = await fetch('/admin/set-module', {
                    method: 'POST',
                    headers: { 'Accept': 'application/json' },
                    body: body
                });
                var result = await response.json();
                var data = result.data || result;
                if (response.ok && data.success) {
                    window.location.href = '/admin/manage/modules?saved=1';
                } else {
                    show(data.error || data.detail || result.message || 'Could not save', true);
                }
            } catch (error) {
                show('Could not save. Please try again.', true);
            }
        });
    });
});
