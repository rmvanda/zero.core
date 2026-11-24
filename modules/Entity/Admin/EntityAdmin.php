<?php

namespace Zero\Core\Module\Entity\Admin;

use Zero\Core\AdminResponse;
use Zero\Core\Attribute\{AllowedMethods, RequiredParams, RequirePermission};
use Zero\Core\Exception\HTTPError;
use Zero\Core\Module\Entity\Model\EntityStore;

/**
 * Entity Admin Controller
 *
 * Admin interface for entity types, their records, and the event log
 * URL: /admin/entity
 */
#[RequirePermission('allow.admin')]
class EntityAdmin extends AdminResponse {

    /**
     * Entity types as cards, a Create entity card and an Event logs card
     * URL: /admin/entity
     */
    public function index() {
        $this->setBreadcrumbs([
            ['label' => 'Entities', 'url' => '/admin/entity']
        ]);

        $store = $this->store();

        $this->tablesInstalled = $store->tablesInstalled();
        $this->entityTypes = array_values(array_filter(
            $store->getAllTypes(),
            fn($type) => !in_array($type['label'], ['user', 'event'], true)
        ));

        $this->build(__DIR__ . '/view/index.php');
    }

    /**
     * Generic entity management handler
     * Displays entity data from the entity table using EAV pattern
     * URL: /admin/entity/manage/{label}
     *
     * @param string|null $entityLabel Entity type label (e.g., "product")
     */
    public function manage(?string $entityLabel = null) {
        if (!$entityLabel) {
            throw new HTTPError(400, 'Entity type required');
        }

        $db = \Zero\Core\Database::getConnection();
        $entityModel = new EntityStore($db);

        // Get entity type
        $entityType = $entityModel->getTypeByLabel($entityLabel);
        if (!$entityType) {
            throw new HTTPError(404, "Entity type '{$entityLabel}' not found");
        }

        // Handle POST - create new entity instance
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            try {
                // Get schema to know which attributes to expect
                $schema = $entityModel->getSchema($entityType['id']);

                // Build data array from POST
                // Only include non-empty values so defaults can apply
                $data = [];
                foreach ($schema as $attrName) {
                    if (isset($_POST[$attrName]) && $_POST[$attrName] !== '') {
                        $data[$attrName] = $_POST[$attrName];
                    }
                }

                // Load the generated entity class and create instance
                // GenericEntity::siteDir() is the site's entity/ folder, where generated classes live
                $className = $this->toStudlyCase($entityLabel);
                $classPath = \Zero\Entity\GenericEntity::siteDir() . "{$className}.php";

                if (file_exists($classPath)) {
                    require_once $classPath;
                    $fullClassName = "\\Zero\\Entity\\{$className}";
                    $instance = new $fullClassName($data);
                } else {
                    // Fallback to GenericEntity if class file doesn't exist
                    $instance = new \Zero\Entity\GenericEntity($entityLabel, $data);
                }

                $this->success("{$entityLabel} created successfully", ['id' => $instance->id]);
                header("Location: /admin/entity/manage/{$entityLabel}");
                exit;
            } catch (\Exception $e) {
                \Zero\Core\Console::error("Error creating {$entityLabel}: " . $e->getMessage());
                $this->error('Failed to create ' . $entityLabel . ': ' . $e->getMessage());
                // Fall through to show form again
            }
        }

        // GET request - show list and form
        // Pagination parameters
        $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
        $perPage = isset($_GET['per_page']) ? max(1, min(100, intval($_GET['per_page']))) : 25;
        $offset = ($page - 1) * $perPage;

        // Get schema and records
        $schema = $entityModel->getSchema($entityType['id']);
        $records = $entityModel->getAllRecords($entityType['id'], $perPage, $offset);
        $totalRecords = $entityModel->getRecordCount($entityType['id']);
        $totalPages = ceil($totalRecords / $perPage);

        // Pass data to view
        $this->entityType = $entityType;
        $this->schema = $schema;
        $this->records = $records;
        $this->totalRecords = $totalRecords;
        $this->page = $page;
        $this->perPage = $perPage;
        $this->totalPages = $totalPages;

        $this->setBreadcrumbs([
            ['label' => 'Entities', 'url' => '/admin/entity'],
            ['label' => ucfirst((string) $entityLabel)],
        ]);
        $this->build(__DIR__ . '/view/manage.php');
    }

    /**
     * The store over the site database
     */
    protected function store(): EntityStore {
        return new EntityStore(\Zero\Core\Database::getConnection());
    }

    /**
     * Convert snake_case to StudlyCase
     */
    private function toStudlyCase(string $value): string {
        return str_replace(' ', '', ucwords(str_replace('_', ' ', $value)));
    }

    /**
     * Delete entity instance(s)
     * POST /admin/entity/delete
     */
    #[AllowedMethods('POST')]
    #[RequiredParams(['entity_type'])]
    public function delete() {
        $entityLabel = $_POST['entity_type'];
        $entityIds = isset($_POST['entity_ids']) ? (array)$_POST['entity_ids'] : [];

        if (empty($entityIds)) {
            $this->error('No entities selected for deletion');
            header("Location: /admin/entity/manage/{$entityLabel}");
            exit;
        }

        $db = \Zero\Core\Database::getConnection();
        $entityModel = new EntityStore($db);

        $entityType = $entityModel->getTypeByLabel($entityLabel);
        if (!$entityType) {
            $this->error("Entity type not found");
            header("Location: /admin/entity");
            exit;
        }

        try {
            // Delete records from entity table
            $placeholders = implode(',', array_fill(0, count($entityIds), '?'));
            $stmt = $db->prepare("DELETE FROM entity WHERE type = ? AND id IN ($placeholders)");
            $params = array_merge([$entityType['id']], $entityIds);
            $stmt->execute($params);

            $count = count($entityIds);
            $this->success("Deleted {$count} " . ($count === 1 ? 'record' : 'records'));
            header("Location: /admin/entity/manage/{$entityLabel}");
            exit;
        } catch (\Exception $e) {
            \Zero\Core\Console::error("Error deleting entity: " . $e->getMessage());
            $this->error('Failed to delete: ' . $e->getMessage());
            header("Location: /admin/entity/manage/{$entityLabel}");
            exit;
        }
    }

    /**
     * Update entity attribute value (inline editing)
     * POST /admin/entity/update
     */
    #[AllowedMethods('POST')]
    #[RequiredParams(['entity_type', 'entity_id', 'attribute', 'value'])]
    public function update() {
        $entityLabel = $_POST['entity_type'];
        $entityId = (int)$_POST['entity_id'];
        $attribute = $_POST['attribute'];
        $value = $_POST['value'];

        $db = \Zero\Core\Database::getConnection();
        $entityModel = new EntityStore($db);

        $entityType = $entityModel->getTypeByLabel($entityLabel);
        if (!$entityType) {
            $this->error("Entity type not found");
            return;
        }

        try {
            // Get schema to find attribute index
            $schema = $entityModel->getSchema($entityType['id']);
            $attrIndex = array_search($attribute, $schema);

            if ($attrIndex === false) {
                $this->error("Attribute not found in schema");
                return;
            }

            // Update the entity attribute
            $stmt = $db->prepare("UPDATE entity SET value = ? WHERE type = ? AND id = ? AND attr = ?");
            $stmt->execute([$value, $entityType['id'], $entityId, $attrIndex]);

            $this->success("Updated successfully", [
                'entity_id' => $entityId,
                'attribute' => $attribute,
                'value' => $value
            ]);
        } catch (\Exception $e) {
            \Zero\Core\Console::error("Error updating entity: " . $e->getMessage());
            $this->error('Failed to update: ' . $e->getMessage());
        }
    }

    /**
     * Create or define a new entity type using GenericEntity
     * GET: Show form, POST: Handle creation
     * URL: /admin/entity/create
     */
    public function create() {
        // Handle POST request - create the entity
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            try {
                $entityName = $_POST['entity_name'] ?? '';
                $attributes = $_POST['attributes'] ?? [];
                $defaults = $_POST['defaults'] ?? [];
                $defaultTypes = $_POST['default_types'] ?? [];
                $uniq = $_POST['uniq'] ?? [];

                // Validate entity name
                if (empty($entityName)) {
                    throw new \Exception("Entity name is required");
                }

                // Build configuration
                $config = [
                    'attributes' => array_filter($attributes), // Remove empty values
                ];

                // Build defaults array with proper prefixes
                $configDefaults = [];
                foreach ($attributes as $index => $attr) {
                    if (!empty($attr) && isset($defaults[$index]) && !empty($defaults[$index])) {
                        $defaultValue = $defaults[$index];
                        $defaultType = $defaultTypes[$index] ?? 'literal';

                        // Add prefix based on type
                        switch ($defaultType) {
                            case 'date':
                                $configDefaults[$attr] = 'date:' . $defaultValue;
                                break;
                            case 'uniqid':
                                $configDefaults[$attr] = 'uniqid:' . $defaultValue;
                                break;
                            case 'server':
                                $configDefaults[$attr] = 'server:' . $defaultValue;
                                break;
                            default:
                                $configDefaults[$attr] = $defaultValue;
                        }
                    }
                }

                if (!empty($configDefaults)) {
                    $config['defaults'] = $configDefaults;
                }

                // Build unique constraints array
                $configUniq = [];
                foreach ($attributes as $index => $attr) {
                    if (!empty($attr) && isset($uniq[$index]) && $uniq[$index] == '1') {
                        $configUniq[] = $attr;
                    }
                }

                if (!empty($configUniq)) {
                    $config['uniq'] = $configUniq;
                }

                // Define the entity using GenericEntity
                $filePath = \Zero\Entity\GenericEntity::define($entityName, $config);

                $this->success("Entity '$entityName' created successfully", [
                    'entity_name' => $entityName,
                    'file_path' => $filePath
                ]);

                header("Location: /admin/entity");
                exit;
            } catch (\Exception $e) {
                \Zero\Core\Console::error("Error creating entity: " . $e->getMessage());
                $this->error('Failed to create entity: ' . $e->getMessage());
                // Fall through to show form again with error
            }
        }

        // GET request - show form
        $this->setBreadcrumbs([
            ['label' => 'Entities', 'url' => '/admin/entity'],
            ['label' => 'New entity type'],
        ]);
        $this->build(__DIR__."/view/create.php");
    }
}
