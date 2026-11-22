/**
 * Admin Users - what every users screen shares: posting an action and toast notifications
 */

// Post an action to /admin/users/... and give back its data.
// A success is {"data": {"success": true, "message": ...}}; anything else is an
// error, {"status": "error", "message": "Not Found", "detail": "User not found", "code": 404},
// and rejects with the detail as the Error's message.
async function postUserAction(url, fields) {
    var formData = new FormData();
    Object.keys(fields).forEach(function(name) {
        formData.append(name, fields[name]);
    });

    var response = await fetch(url, {
        method: 'POST',
        headers: {
            'Accept': 'application/json'
        },
        body: formData
    });

    var result = null;
    try {
        result = await response.json();
    } catch (error) {
        // Not JSON: the status decides
    }

    var data = result && result.data;
    if (response.ok && data && data.success) {
        return data;
    }
    throw new Error((result && (result.detail || result.message)) || 'The request failed. Please try again.');
}

// Toast Notification System
function showToast(message, type) {
    type = type || 'success';
    var existingToast = document.querySelector('.toast-notification');
    if (existingToast) {
        existingToast.remove();
    }

    var toast = document.createElement('div');
    toast.className = 'toast-notification toast-' + type;

    toast.innerHTML =
        '<div class="toast-indicator"></div>' +
        '<div class="toast-content">' +
            '<span class="material-symbols-outlined toast-icon">' + getToastIcon(type) + '</span>' +
            '<span class="toast-message">' + escapeHtml(message) + '</span>' +
        '</div>';

    document.body.appendChild(toast);

    setTimeout(function() { toast.classList.add('show'); }, 10);

    setTimeout(function() {
        toast.classList.remove('show');
        setTimeout(function() { toast.remove(); }, 300);
    }, 3000);
}

function getToastIcon(type) {
    var icons = {
        'success': 'check_circle',
        'error': 'error',
        'warning': 'warning',
        'info': 'info'
    };
    return icons[type] || icons['info'];
}

// Utility function to escape HTML
function escapeHtml(text) {
    var map = {
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;'
    };
    return String(text).replace(/[&<>"']/g, function(m) { return map[m]; });
}
