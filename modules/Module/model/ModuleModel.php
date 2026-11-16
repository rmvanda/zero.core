<?php
/**
 * Models in model/ are autoloaded — no require needed. The only requirement
 * is that the namespace contains \Model\ (see the spl_autoload_register call
 * in core/Response.php). The framework does not inject models; instantiate
 * them yourself, typically in the controller's constructor.
 */
namespace Zero\Module\Module\Model;

class ModuleModel {

    public function __construct(private \PDO $db) {}

    public function all(): array {
        return $this->db
            ->query("SELECT 1 AS example")
            ->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function find(int $id): ?array {
        $stmt = $this->db->prepare("SELECT 1 AS example WHERE ? > 0");
        $stmt->execute([$id]);
        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
    }
}
