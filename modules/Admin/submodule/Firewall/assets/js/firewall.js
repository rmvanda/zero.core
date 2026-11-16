/**
 * Firewall - CloudFlare IP block list management
 */
(function() {
    var currentPage = 1;
    var perPage = 20;
    var searchVal = '';

    var tbody     = document.getElementById('fw-tbody');
    var loading   = document.getElementById('fw-loading');
    var tableWrap = document.getElementById('fw-table-wrap');
    var empty     = document.getElementById('fw-empty');
    var errorEl   = document.getElementById('fw-error');
    var pageInfo  = document.getElementById('fw-page-info');
    var prevBtn   = document.getElementById('fw-prev');
    var nextBtn   = document.getElementById('fw-next');
    var searchInp = document.getElementById('fw-search');
    var searchBtn = document.getElementById('fw-search-btn');
    var statsEl   = document.getElementById('fw-stats');

    function show(el) { el.classList.remove('firewall-hidden'); }
    function hide(el) { el.classList.add('firewall-hidden'); }

    function fetchRules() {
        hide(tableWrap);
        hide(empty);
        hide(errorEl);
        show(loading);

        var params = '?page=' + currentPage + '&per_page=' + perPage;
        if (searchVal) params += '&search=' + encodeURIComponent(searchVal);

        fetch('/admin/firewall/list' + params)
            .then(function(r) { return r.json(); })
            .then(function(resp) {
                hide(loading);

                /* export() wraps in { data: { ... } } */
                var data = resp.data || resp;

                if (!data.success) {
                    errorEl.textContent = data.error || 'Unknown error';
                    show(errorEl);
                    return;
                }

                if (!data.rules || data.rules.length === 0) {
                    show(empty);
                    hide(statsEl);
                    return;
                }

                renderTable(data.rules);
                renderPagination(data.pagination);
                renderStats(data.rules.length, data.pagination);
                show(tableWrap);
            })
            .catch(function(err) {
                hide(loading);
                errorEl.textContent = 'Request failed: ' + err.message;
                show(errorEl);
            });
    }

    function renderTable(rules) {
        tbody.innerHTML = '';

        rules.forEach(function(rule) {
            var tr = document.createElement('tr');

            var ip = rule.configuration ? rule.configuration.value : '\u2014';
            var notes = rule.notes || '\u2014';
            var created = rule.created_on ? formatDate(rule.created_on) : '\u2014';

            tr.innerHTML =
                '<td class="firewall-ip">' + escapeHtml(ip) + '</td>' +
                '<td class="firewall-notes" title="' + escapeAttr(notes) + '">' + escapeHtml(notes) + '</td>' +
                '<td class="firewall-date">' + escapeHtml(created) + '</td>' +
                '<td class="firewall-actions">' +
                    '<button class="btn-icon btn-delete firewall-unblock-btn" data-ip="' + escapeAttr(ip) + '" title="Unblock ' + escapeAttr(ip) + '">' +
                        '<span class="material-symbols-outlined">lock_open</span>' +
                    '</button>' +
                '</td>';

            tbody.appendChild(tr);
        });
    }

    function renderPagination(info) {
        if (!info || !info.total_pages || info.total_pages <= 1) {
            hide(document.getElementById('fw-pagination'));
            return;
        }

        show(document.getElementById('fw-pagination'));
        pageInfo.textContent = 'Page ' + info.page + ' of ' + info.total_pages;
        prevBtn.disabled = info.page <= 1;
        nextBtn.disabled = info.page >= info.total_pages;
    }

    function renderStats(pageCount, info) {
        var total = (info && info.total_count) ? info.total_count : pageCount;
        document.getElementById('fw-stat-total').textContent = total;
        document.getElementById('fw-stat-page').textContent = pageCount;
        show(statsEl);
    }

    function unblockIp(ip, btn) {
        if (!confirm('Unblock ' + ip + '?')) return;

        /* Visual feedback on the row */
        var row = btn.closest('tr');
        if (row) row.classList.add('firewall-removing');
        btn.disabled = true;

        var formData = new FormData();
        formData.append('ip', ip);

        fetch('/admin/firewall/unblock', { method: 'POST', body: formData })
            .then(function(r) { return r.json(); })
            .then(function(resp) {
                var data = resp.data || resp;
                if (data.success) {
                    showToast('Unblocked ' + ip, 'success');
                    fetchRules();
                } else {
                    showToast(data.message || 'Unblock failed', 'error');
                    if (row) row.classList.remove('firewall-removing');
                    btn.disabled = false;
                }
            })
            .catch(function() {
                showToast('Request failed', 'error');
                if (row) row.classList.remove('firewall-removing');
                btn.disabled = false;
            });
    }

    function showToast(message, type) {
        /* Reuse main.css toast-notification system */
        var existing = document.querySelector('.toast-notification.fw-toast');
        if (existing) existing.remove();

        var toast = document.createElement('div');
        toast.className = 'toast-notification toast-' + type + ' fw-toast';
        toast.innerHTML =
            '<div class="toast-indicator"></div>' +
            '<div class="toast-content">' +
                '<span class="material-symbols-outlined toast-icon">' +
                    (type === 'success' ? 'check_circle' : 'error') +
                '</span>' +
                '<span class="toast-message">' + escapeHtml(message) + '</span>' +
            '</div>';

        document.body.appendChild(toast);

        /* Trigger animation */
        requestAnimationFrame(function() {
            toast.classList.add('show');
        });

        setTimeout(function() {
            toast.classList.remove('show');
            setTimeout(function() { toast.remove(); }, 350);
        }, 3000);
    }

    function formatDate(iso) {
        var d = new Date(iso);
        var pad = function(n) { return n < 10 ? '0' + n : n; };
        return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()) +
               ' ' + pad(d.getHours()) + ':' + pad(d.getMinutes());
    }

    function escapeHtml(str) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    }

    function escapeAttr(str) {
        return str.replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }

    /* Event listeners */
    tbody.addEventListener('click', function(e) {
        var btn = e.target.closest('.firewall-unblock-btn');
        if (btn) unblockIp(btn.dataset.ip, btn);
    });

    prevBtn.addEventListener('click', function() { currentPage--; fetchRules(); });
    nextBtn.addEventListener('click', function() { currentPage++; fetchRules(); });

    searchBtn.addEventListener('click', function() {
        searchVal = searchInp.value.trim();
        currentPage = 1;
        fetchRules();
    });

    searchInp.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
            searchVal = searchInp.value.trim();
            currentPage = 1;
            fetchRules();
        }
    });

    /* Initial load */
    fetchRules();
})();
