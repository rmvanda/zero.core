<?php

namespace Zero\Core\Module\Entity\Tests;

use PHPUnit\Framework\TestCase;
use Zero\Core\AdminResponse;
use Zero\Core\Attribute\{AllowedMethods, RequiredParams};
use Zero\Core\Module\Entity\Admin\EntityAdmin;
use Zero\Core\Module\Entity\Model\EntityStore;

/**
 * The entity screens are the Entity module's admin offering: /admin/entity/...
 */
class EntityAdminTest extends TestCase
{
    public function testOfferingIsDiscoverable(): void
    {
        $this->assertTrue(class_exists(EntityAdmin::class));
        $this->assertTrue(is_subclass_of(EntityAdmin::class, AdminResponse::class));

        foreach (['index', 'manage', 'create', 'update', 'delete'] as $method) {
            $this->assertTrue(method_exists(EntityAdmin::class, $method), "EntityAdmin::{$method}() exists");
        }

        $config = include dirname(__DIR__) . '/Admin/admin-config.php';
        $this->assertSame('Entities', $config['label']);
        $this->assertSame('database', $config['icon']);
        $this->assertSame(['allow.admin'], $config['permissions']);
        $this->assertArrayNotHasKey('enabled', $config);
        $this->assertArrayNotHasKey('order', $config);
    }

    public function testClassRequiresAdminPermission(): void
    {
        $attribute = (new \ReflectionClass(EntityAdmin::class))->getAttributes(\Zero\Core\Attribute\RequirePermission::class)[0] ?? null;

        $this->assertNotNull($attribute);
        $this->assertSame(['allow.admin'], $attribute->getArguments());
    }

    public function testViewsLoadAssetsUnderTheModuleName(): void
    {
        foreach (glob(dirname(__DIR__) . '/Admin/view/*.php') as $view) {
            $this->assertStringNotContainsString('loadInlineAsset("entity"', file_get_contents($view), basename($view));
        }
    }

    public function testWritesKeepTheirGates(): void
    {
        $update = new \ReflectionMethod(EntityAdmin::class, 'update');
        $delete = new \ReflectionMethod(EntityAdmin::class, 'delete');

        $this->assertSame(['POST'], $this->allowedMethods($update));
        $this->assertSame(['POST'], $this->allowedMethods($delete));
        $this->assertSame(
            ['entity_type', 'entity_id', 'attribute', 'value'],
            $update->getAttributes(RequiredParams::class)[0]->getArguments()[0]
        );
        $this->assertSame(
            ['entity_type'],
            $delete->getAttributes(RequiredParams::class)[0]->getArguments()[0]
        );
    }

    public function testAdminNoLongerHasEntityEndpoints(): void
    {
        foreach (['deleteEntity', 'updateEntity', 'createEntity', 'manageEntity'] as $method) {
            $this->assertFalse(method_exists(\Zero\Core\Module\Admin::class, $method), "Admin::{$method}() is gone");
        }
    }

    public function testMissingEntityTablesIsAMessageNotAFatal(): void
    {
        $missing = new \PDOException("Table 'entity_type' doesn't exist");
        $missing->errorInfo = ['42S02', 1146, "Table 'entity_type' doesn't exist"];

        $pdo = $this->createStub(\PDO::class);
        $pdo->method('query')->willThrowException($missing);
        $pdo->method('prepare')->willThrowException($missing);

        $store = new EntityStore($pdo);

        $this->assertSame([], $store->getAllTypes());
        $this->assertFalse($store->tablesInstalled());
    }

    private function allowedMethods(\ReflectionMethod $method): array
    {
        $args = $method->getAttributes(AllowedMethods::class)[0]->getArguments();
        return array_map('strtoupper', (array) $args[0]);
    }
}
