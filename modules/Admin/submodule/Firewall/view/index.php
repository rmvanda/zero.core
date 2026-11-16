<div class="firewall-container">

    <!-- Header -->
    <div class="firewall-header">
        <h1>
            <span class="material-symbols-outlined">shield</span>
            Firewall
        </h1>
        <p class="firewall-subtitle">CloudFlare IP access rules</p>
    </div>

    <!-- Stats -->
    <div class="firewall-stats firewall-hidden" id="fw-stats">
        <div class="firewall-stat">
            <span class="material-symbols-outlined">block</span>
            <div>
                <div class="firewall-stat-value" id="fw-stat-total">&mdash;</div>
                <div class="firewall-stat-label">Blocked</div>
            </div>
        </div>
        <div class="firewall-stat">
            <span class="material-symbols-outlined">description</span>
            <div>
                <div class="firewall-stat-value" id="fw-stat-page">&mdash;</div>
                <div class="firewall-stat-label">This Page</div>
            </div>
        </div>
    </div>

    <!-- Search -->
    <div class="firewall-search">
        <input type="text" id="fw-search" placeholder="Search by IP address...">
        <button id="fw-search-btn" class="btn-icon" title="Search">
            <span class="material-symbols-outlined">search</span>
        </button>
    </div>

    <!-- Loading state -->
    <div id="fw-loading" class="firewall-loading">
        <span class="material-symbols-outlined">progress_activity</span>
        <p>Loading blocked IPs...</p>
    </div>

    <!-- Error state -->
    <div id="fw-error" class="firewall-error firewall-hidden"></div>

    <!-- Table -->
    <div id="fw-table-wrap" class="firewall-hidden">
        <table class="firewall-table">
            <thead>
                <tr>
                    <th>IP Address</th>
                    <th>Reason</th>
                    <th>Blocked</th>
                    <th></th>
                </tr>
            </thead>
            <tbody id="fw-tbody"></tbody>
        </table>

        <!-- Pagination -->
        <div class="firewall-pagination" id="fw-pagination">
            <button id="fw-prev" disabled>
                <span class="material-symbols-outlined fw-pagination-icon">chevron_left</span>
                Prev
            </button>
            <span id="fw-page-info"></span>
            <button id="fw-next" disabled>
                Next
                <span class="material-symbols-outlined fw-pagination-icon">chevron_right</span>
            </button>
        </div>
    </div>

    <!-- Empty state -->
    <div id="fw-empty" class="firewall-empty firewall-hidden">
        <span class="material-symbols-outlined">verified_user</span>
        <p>No blocked IPs found</p>
    </div>
</div>
