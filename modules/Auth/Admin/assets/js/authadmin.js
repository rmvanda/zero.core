/**
 * OAuth Providers admin — enable/disable toggle with optimistic UI + toast.
 * Mirrors the shared admin toast pattern (llmadmin.js).
 */
(function () {
    function showToast(message, type) {
        var existing = document.querySelector('.toast-notification.oauth-admin-toast');
        if (existing) existing.remove();

        var toast = document.createElement('div');
        toast.className = 'toast-notification toast-' + (type || 'success') + ' oauth-admin-toast';
        toast.textContent = message;
        document.body.appendChild(toast);

        requestAnimationFrame(function () { toast.classList.add('show'); });
        setTimeout(function () {
            toast.classList.remove('show');
            setTimeout(function () { toast.remove(); }, 350);
        }, 3000);
    }

    document.addEventListener('change', function (e) {
        var input = e.target.closest('.oauth-admin-switch');
        if (!input) return;

        var slug = input.dataset.slug;
        var enabled = input.checked;
        input.disabled = true;

        fetch('/admin/auth/toggle', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: 'slug=' + encodeURIComponent(slug) + '&enabled=' + (enabled ? '1' : '0')
        })
            .then(function (r) { return r.json(); })
            .then(function (resp) {
                var data = resp.data || resp;
                if (data.success || data.status === 'success') {
                    input.disabled = false;
                    var row = input.closest('tr');
                    if (row) row.classList.toggle('is-disabled', !enabled);
                    showToast(data.message || 'Saved', 'success');
                } else {
                    input.checked = !enabled;   // revert
                    input.disabled = false;
                    showToast(data.error || 'Update failed', 'error');
                }
            })
            .catch(function () {
                input.checked = !enabled;       // revert
                input.disabled = false;
                showToast('Request failed', 'error');
            });
    });
})();
