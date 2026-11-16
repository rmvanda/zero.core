<?php $this->loadInlineAsset("Entity", "css", "create.css"); ?>
<div class="content-wrapper entity-form">
    <h1>Create New Entity</h1>
    <p><a href="/admin/entity">&larr; Back to Entities</a></p>

    <div class="entity-help-text">
        <strong>Creating a New Entity Type</strong>
        <ul>
            <li><strong>Entity Name:</strong> Alphanumeric with underscores (e.g., "product", "customer_order")</li>
            <li><strong>Attributes:</strong> Define the fields your entity will have</li>
            <li><strong>Default Values:</strong>
                <ul>
                    <li><em>Literal:</em> Static value (e.g., "active", "0")</li>
                    <li><em>Date:</em> Date format (e.g., "Y-m-d H:i:s")</li>
                    <li><em>Uniqid:</em> Unique ID prefix (e.g., "PROD-")</li>
                    <li><em>Server:</em> Server variable (e.g., "REMOTE_ADDR")</li>
                </ul>
            </li>
            <li><strong>Unique:</strong> Check if attribute must be unique (like email or SKU)</li>
        </ul>
    </div>

    <form method="POST" action="/admin/entity/create" id="entityForm">
        <!-- Entity Name -->
        <div class="admin-form-field">
            <label for="entity_name">Entity Name *</label>
            <input type="text"
                   name="entity_name"
                   id="entity_name"
                   required
                   pattern="[a-z_][a-z0-9_]*"
                   placeholder="e.g., product, customer, inventory_item">
            <small>Use lowercase letters, numbers, and underscores only</small>
        </div>

        <!-- Attributes Section -->
        <div class="admin-form-field">
            <label>Entity Attributes *</label>
            <div class="attributes-container">
                <!-- Column Headers -->
                <div class="attribute-headers">
                    <div>Attribute Name</div>
                    <div>Default Value</div>
                    <div>Default Type</div>
                    <div>Unique</div>
                    <div></div>
                </div>

                <!-- Attribute Rows -->
                <div id="attributesWrapper">
                    <!-- Initial attribute row -->
                    <div class="attribute-row">
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
                                   name="uniq[0]"
                                   value="1"
                                   id="uniq_0">
                            <label for="uniq_0">Unique</label>
                        </div>
                        <div>
                            <button type="button" class="btn-remove" title="Remove attribute">&times;</button>
                        </div>
                    </div>
                </div>
                <button type="button" class="btn-add-attribute">+ Add Attribute</button>
            </div>
        </div>

        <!-- Form Actions -->
        <div class="form-actions">
            <button type="submit" class="btn-primary">Create Entity</button>
            <a href="/admin/entity" class="btn-secondary btn-cancel">Cancel</a>
        </div>
    </form>
</div>

<?php $this->loadInlineAsset("Entity", "js", "create.js"); ?>
