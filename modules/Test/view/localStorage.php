<style>
.localStorage-container {
    padding: 2rem;
    max-width: 1200px;
    margin: 0 auto;
}

.localStorage-table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 1rem;
    background: var(--surface-color, #fff);
    border: 1px solid var(--border-color, #ddd);
}

.localStorage-table th,
.localStorage-table td {
    padding: 0.75rem 1rem;
    text-align: left;
    border-bottom: 1px solid var(--border-color, #ddd);
}

.localStorage-table th {
    background: var(--primary-color, #333);
    color: var(--primary-text-color, #fff);
    font-weight: 600;
}

.localStorage-table tr:hover {
    background: var(--hover-color, #f5f5f5);
}

.localStorage-key {
    font-family: monospace;
    font-weight: 600;
    color: var(--accent-color, #0066cc);
}

.localStorage-value {
    font-family: monospace;
    word-break: break-all;
}

.localStorage-empty {
    text-align: center;
    padding: 2rem;
    color: var(--muted-color, #666);
    font-style: italic;
}

.localStorage-count {
    margin-top: 1rem;
    padding: 0.5rem 1rem;
    background: var(--info-bg, #e3f2fd);
    border-left: 4px solid var(--info-color, #2196f3);
    color: var(--text-color, #333);
}

.localStorage-actions {
    margin-top: 1rem;
}

.localStorage-actions button {
    padding: 0.5rem 1rem;
    margin-right: 0.5rem;
    background: var(--primary-color, #333);
    color: var(--primary-text-color, #fff);
    border: none;
    border-radius: 4px;
    cursor: pointer;
}

.localStorage-actions button:hover {
    opacity: 0.9;
}
</style>

<div class="localStorage-container">
    <h1>localStorage Contents</h1>

    <div class="localStorage-actions">
        <button onclick="refreshStorage()">Refresh</button>
        <button onclick="clearStorage()" style="background: var(--danger-color, #d32f2f);">Clear All</button>
    </div>

    <div class="localStorage-count" id="storageCount"></div>

    <table class="localStorage-table" id="storageTable">
        <thead>
            <tr>
                <th>Key</th>
                <th>Value</th>
            </tr>
        </thead>
        <tbody id="storageTableBody">
            <!-- Populated by JavaScript -->
        </tbody>
    </table>
</div>

<script>
function displayLocalStorage() {
    const tbody = document.getElementById('storageTableBody');
    const countDiv = document.getElementById('storageCount');

    // Clear existing rows
    tbody.innerHTML = '';

    // Check if localStorage is available
    if (typeof(Storage) === "undefined") {
        tbody.innerHTML = '<tr><td colspan="2" class="localStorage-empty">localStorage is not supported in this browser</td></tr>';
        countDiv.textContent = 'localStorage not supported';
        return;
    }

    // Get all keys
    const keys = Object.keys(localStorage);

    if (keys.length === 0) {
        tbody.innerHTML = '<tr><td colspan="2" class="localStorage-empty">localStorage is empty</td></tr>';
        countDiv.textContent = 'No items in localStorage';
        return;
    }

    // Sort keys alphabetically
    keys.sort();

    // Display each key-value pair
    keys.forEach(key => {
        const value = localStorage.getItem(key);
        const row = document.createElement('tr');

        const keyCell = document.createElement('td');
        keyCell.className = 'localStorage-key';
        keyCell.textContent = key;

        const valueCell = document.createElement('td');
        valueCell.className = 'localStorage-value';

        // Try to pretty-print JSON values
        try {
            const parsed = JSON.parse(value);
            valueCell.textContent = JSON.stringify(parsed, null, 2);
        } catch (e) {
            valueCell.textContent = value;
        }

        row.appendChild(keyCell);
        row.appendChild(valueCell);
        tbody.appendChild(row);
    });

    countDiv.textContent = `Total items: ${keys.length}`;
}

function refreshStorage() {
    displayLocalStorage();
}

function clearStorage() {
    if (confirm('Are you sure you want to clear all localStorage data?')) {
        localStorage.clear();
        displayLocalStorage();
    }
}

// Display on page load
displayLocalStorage();
</script>
