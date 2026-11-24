<?php
namespace Zero\Core\Module\Entity\Model;

use Zero\Core\Console;

/**
 * Entity Store
 * Manages entities using EAV (Entity-Attribute-Value) pattern
 *
 * Entity structure:
 * - entity_type table: defines entity types (id, label)
 * - entity table: stores entity data (type, id, attr, value)
 *   - id=0: schema definition (attr values are column names)
 *   - id>0: actual entity records
 */
class EntityStore {

    private $db;

    public function __construct($db) {
        $this->db = $db;
    }

    /**
     * Whether the entity tables exist on this site
     *
     * @return bool False when the entity_type table is missing (SQLSTATE 42S02)
     */
    public function tablesInstalled(): bool {
        try {
            $this->db->query("SELECT 1 FROM entity_type LIMIT 0");
            return true;
        } catch (\PDOException $e) {
            if (($e->errorInfo[0] ?? $e->getCode()) === '42S02') {
                return false;
            }
            throw $e;
        }
    }

    /**
     * Get entity type by label
     *
     * @param string $label Entity type label
     * @return array|null Entity type data or null if not found
     */
    public function getTypeByLabel(string $label): ?array {
        try {
            $stmt = $this->db->prepare("
                SELECT id, label
                FROM entity_type
                WHERE label = ?
            ");
            $stmt->execute([$label]);
            $result = $stmt->fetch(\PDO::FETCH_ASSOC);

            return $result ?: null;
        } catch (\PDOException $e) {
            Console::error("Failed to get entity type: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Get entity type by ID
     *
     * @param int $typeId Entity type ID
     * @return array|null Entity type data or null if not found
     */
    public function getTypeById(int $typeId): ?array {
        try {
            $stmt = $this->db->prepare("
                SELECT id, label
                FROM entity_type
                WHERE id = ?
            ");
            $stmt->execute([$typeId]);
            $result = $stmt->fetch(\PDO::FETCH_ASSOC);

            return $result ?: null;
        } catch (\PDOException $e) {
            Console::error("Failed to get entity type: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Get all entity types
     *
     * @return array Array of entity types
     */
    public function getAllTypes(): array {
        try {
            $stmt = $this->db->query("
                SELECT id, label
                FROM entity_type
                ORDER BY label ASC
            ");
            return $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            // Missing tables are an expected state on a site without entities
            if (($e->errorInfo[0] ?? $e->getCode()) === '42S02') {
                return [];
            }
            Console::error("Failed to get entity types: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get schema (column names) for an entity type
     * Schema is stored in records where id=0
     *
     * @param int $typeId Entity type ID
     * @return array Array of column names indexed by attr number
     */
    public function getSchema(int $typeId): array {
        try {
            $stmt = $this->db->prepare("
                SELECT attr, value
                FROM entity
                WHERE type = ? AND id = 0
                ORDER BY attr ASC
            ");
            $stmt->execute([$typeId]);

            $schema = [];
            while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                $schema[$row['attr']] = $row['value'];
            }

            return $schema;
        } catch (\PDOException $e) {
            Console::error("Failed to get entity schema: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get all records for an entity type
     * Returns data formatted as rows with column names
     *
     * @param int $typeId Entity type ID
     * @param int $limit Maximum number of records to return
     * @param int $offset Offset for pagination
     * @return array Array of entity records
     */
    public function getAllRecords(int $typeId, int $limit = 100, int $offset = 0): array {
        try {
            // First get the schema to know column names
            $schema = $this->getSchema($typeId);

            if (empty($schema)) {
                Console::warn("No schema found for entity type $typeId");
                return [];
            }

            // Get all entity IDs (excluding schema id=0)
            $stmt = $this->db->prepare("
                SELECT DISTINCT id
                FROM entity
                WHERE type = ? AND id > 0
                ORDER BY id DESC
                LIMIT ? OFFSET ?
            ");
            $stmt->execute([$typeId, $limit, $offset]);
            $entityIds = $stmt->fetchAll(\PDO::FETCH_COLUMN);

            if (empty($entityIds)) {
                return [];
            }

            // Get all data for these entities
            $placeholders = implode(',', array_fill(0, count($entityIds), '?'));
            $stmt = $this->db->prepare("
                SELECT id, attr, value
                FROM entity
                WHERE type = ? AND id IN ($placeholders)
                ORDER BY id DESC, attr ASC
            ");
            $params = array_merge([$typeId], $entityIds);
            $stmt->execute($params);

            // Build records array
            $records = [];
            while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                $entityId = $row['id'];
                $attr = $row['attr'];
                $value = $row['value'];

                if (!isset($records[$entityId])) {
                    $records[$entityId] = ['_id' => $entityId];
                }

                // Map attr number to column name
                if (isset($schema[$attr])) {
                    $columnName = $schema[$attr];
                    $records[$entityId][$columnName] = $value;
                }
            }

            return array_values($records);
        } catch (\PDOException $e) {
            Console::error("Failed to get entity records: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get a single entity record by ID
     *
     * @param int $typeId Entity type ID
     * @param int $entityId Entity instance ID
     * @return array|null Entity record or null if not found
     */
    public function getRecord(int $typeId, int $entityId): ?array {
        try {
            $schema = $this->getSchema($typeId);

            if (empty($schema)) {
                return null;
            }

            $stmt = $this->db->prepare("
                SELECT attr, value
                FROM entity
                WHERE type = ? AND id = ?
                ORDER BY attr ASC
            ");
            $stmt->execute([$typeId, $entityId]);

            $record = ['_id' => $entityId];
            while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                $attr = $row['attr'];
                $value = $row['value'];

                if (isset($schema[$attr])) {
                    $columnName = $schema[$attr];
                    $record[$columnName] = $value;
                }
            }

            // Return null if no data found (only _id in array)
            return count($record) > 1 ? $record : null;
        } catch (\PDOException $e) {
            Console::error("Failed to get entity record: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Get total count of records for an entity type
     *
     * @param int $typeId Entity type ID
     * @return int Total count
     */
    public function getRecordCount(int $typeId): int {
        try {
            $stmt = $this->db->prepare("
                SELECT COUNT(DISTINCT id) as count
                FROM entity
                WHERE type = ? AND id > 0
            ");
            $stmt->execute([$typeId]);
            $result = $stmt->fetch(\PDO::FETCH_ASSOC);

            return (int)($result['count'] ?? 0);
        } catch (\PDOException $e) {
            Console::error("Failed to get entity count: " . $e->getMessage());
            return 0;
        }
    }
}
