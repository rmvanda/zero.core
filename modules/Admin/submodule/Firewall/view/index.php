<div class="content-wrapper firewall-container">

    <!-- Header -->
    <header class="admin-head">
        <div>
            <h1>Firewall</h1>
            <p class="admin-lede">IP addresses blocked at Cloudflare. Unblocking one lets its requests reach the site again.</p>
        </div>
    </header>

    <!-- Stats -->
    <div class="firewall-stats firewall-hidden" id="fw-stats">
        <div class="firewall-stat">
            <span class="material-symbols-outlined" aria-hidden="true">block</span>
            <div>
                <div class="firewall-stat-value" id="fw-stat-total">&mdash;</div>
                <div class="firewall-stat-label">Blocked</div>
            </div>
        </div>
        <div class="firewall-stat">
            <span class="material-symbols-outlined" aria-hidden="true">description</span>
            <div>
                <div class="firewall-stat-value" id="fw-stat-page">&mdash;</div>
                <div class="firewall-stat-label">On this page</div>
            </div>
        </div>
    </div>

    <!-- Search -->
    <div class="firewall-search">
        <input type="text" id="fw-search" class="admin-input" placeholder="Find an IP address, e.g., 203.0.113" aria-label="Find an IP address">
        <button id="fw-search-btn" class="btn-secondary" title="Search">
            <span class="material-symbols-outlined" aria-hidden="true">search</span>
            Search
        </button>
    </div>

    <!-- Loading state -->
    <div id="fw-loading" class="firewall-loading">
        <span class="material-symbols-outlined">progress_activity</span>
        <p>Loading the blocked addresses</p>
    </div>

    <!-- Error state -->
    <div id="fw-error" class="firewall-error firewall-hidden"></div>

    <!-- Table -->
    <div id="fw-table-wrap" class="firewall-hidden">
        <div class="admin-table-wrap">
        <table class="admin-table firewall-table">
            <thead>
                <tr>
                    <th>IP address</th>
                    <th>Reason</th>
                    <th>Blocked</th>
                    <th></th>
                </tr>
            </thead>
            <tbody id="fw-tbody"></tbody>
        </table>
        </div>

        <!-- Pagination -->
        <div class="firewall-pagination" id="fw-pagination">
            <button id="fw-prev" class="pagination-btn" disabled>
                <span class="material-symbols-outlined fw-pagination-icon">chevron_left</span>
                Prev
            </button>
            <span id="fw-page-info"></span>
            <button id="fw-next" class="pagination-btn" disabled>
                Next
                <span class="material-symbols-outlined fw-pagination-icon">chevron_right</span>
            </button>
        </div>
    </div>

    <!-- Empty state -->
    <div id="fw-empty" class="admin-empty firewall-empty firewall-hidden">
        <span class="material-symbols-outlined" aria-hidden="true">verified_user</span>
        <p>No blocked address matches. Clear the search to see every one.</p>
    </div>
</div>
