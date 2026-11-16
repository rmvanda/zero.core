/**
 * Create Entity - Attribute management and form validation
 */
let attributeCounter = 1;

function addAttribute() {
    const wrapper = document.getElementById('attributesWrapper');
    const newRow = document.createElement('div');
    newRow.className = 'attribute-row';
    newRow.innerHTML = `
        <div>
            <input type="text"
                   name="attributes[]"
                   placeholder="e.g., name, price, status"
                   required>
        </div>
        <div>
            <input type="text"
                   name="defaults[]"
                   placeholder="Optional">
        </div>
        <div>
            <select name="default_types[]">
                <option value="literal">Literal</option>
                <option value="date">Date</option>
                <option value="uniqid">Uniqid</option>
                <option value="server">Server</option>
            </select>
        </div>
        <div class="unique-checkbox">
            <input type="checkbox"
                   name="uniq[${attributeCounter}]"
                   value="1"
                   id="uniq_${attributeCounter}">
            <label for="uniq_${attributeCounter}">Unique</label>
        </div>
        <div>
            <button type="button" class="btn-remove" title="Remove attribute">&times;</button>
        </div>
    `;
    wrapper.appendChild(newRow);
    attributeCounter++;
}

function removeAttribute(button) {
    const wrapper = document.getElementById('attributesWrapper');
    const rows = wrapper.getElementsByClassName('attribute-row');

    // Don't allow removing the last row
    if (rows.length > 1) {
        button.closest('.attribute-row').remove();
    } else {
        alert('You must have at least one attribute');
    }
}

document.addEventListener('DOMContentLoaded', function() {
    // Event delegation for remove buttons
    document.getElementById('attributesWrapper').addEventListener('click', function(e) {
        const removeBtn = e.target.closest('.btn-remove');
        if (removeBtn) {
            removeAttribute(removeBtn);
        }
    });

    // Event delegation for add attribute button
    const addBtn = document.querySelector('.btn-add-attribute');
    if (addBtn) {
        addBtn.addEventListener('click', addAttribute);
    }

    // Form validation
    document.getElementById('entityForm').addEventListener('submit', function(e) {
        const entityName = document.getElementById('entity_name').value;
        const attributes = document.querySelectorAll('input[name="attributes[]"]');

        // Validate entity name
        if (!/^[a-z_][a-z0-9_]*$/.test(entityName)) {
            alert('Entity name must start with a letter or underscore and contain only lowercase letters, numbers, and underscores');
            e.preventDefault();
            return;
        }

        // Validate at least one attribute
        let hasValidAttribute = false;
        attributes.forEach(attr => {
            if (attr.value.trim()) {
                hasValidAttribute = true;
            }
        });

        if (!hasValidAttribute) {
            alert('You must define at least one attribute');
            e.preventDefault();
            return;
        }
    });
});
