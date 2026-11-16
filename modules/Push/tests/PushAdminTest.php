<?php

namespace Zero\Module\Push\Tests;

use PHPUnit\Framework\TestCase;
use Zero\Core\AdminResponse;
use Zero\Core\Attribute\{AllowedMethods, AuditLog, RequirePermission};
use Zero\Module\Push\Admin\PushAdmin;

/**
 * The push notification screen is the Push module's admin offering: /admin/push/...
 */
class PushAdminTest extends TestCase
{
    private function moduleDir(): string
    {
        return dirname(__DIR__);
    }

    public function testOfferingIsDiscoverable(): void
    {
        $this->assertTrue(class_exists(PushAdmin::class));
        $this->assertTrue(is_subclass_of(PushAdmin::class, AdminResponse::class));

        $config = include $this->moduleDir() . '/Admin/admin-config.php';
        $this->assertSame('Push Notifications', $config['label']);
        $this->assertSame('notifications', $config['icon']);
        $this->assertNotEmpty($config['description']);
        $this->assertSame(['allow.admin'], $config['permissions']);
        $this->assertArrayNotHasKey('enabled', $config);
        $this->assertArrayNotHasKey('order', $config);
    }

    public function testClassStaysBehindAdminPermission(): void
    {
        $class = new \ReflectionClass(PushAdmin::class);
        $attribute = $class->getAttributes(RequirePermission::class)[0] ?? null;

        $this->assertNotNull($attribute);
        $this->assertSame(['allow.admin'], $attribute->getArguments());
    }

    public function testIndexIsOpenToEveryMethod(): void
    {
        $index = new \ReflectionMethod(PushAdmin::class, 'index');
        $this->assertTrue($index->isPublic());
        $this->assertEmpty($index->getAttributes(AllowedMethods::class), 'index() takes GET and POST');
    }

    public function testDeleteIsPostOnlyAndAuditLogged(): void
    {
        $delete = new \ReflectionMethod(PushAdmin::class, 'delete');
        $this->assertTrue($delete->isPublic());

        $allowed = $delete->getAttributes(AllowedMethods::class)[0] ?? null;
        $this->assertNotNull($allowed);
        $this->assertSame(['POST'], $allowed->getArguments());

        $audit = $delete->getAttributes(AuditLog::class)[0] ?? null;
        $this->assertNotNull($audit);
        $this->assertSame('push_notification_delete', $audit->getArguments()[0]);
        $this->assertTrue($audit->getArguments()['includeParams']);
    }

    public function testAdminNoLongerHasThePushScreen(): void
    {
        foreach (['pushNotifications', 'deletePushNotifications'] as $method) {
            $this->assertFalse(method_exists(\Zero\Module\Admin::class, $method), "Admin::{$method}() is gone");
        }

        $admin = dirname($this->moduleDir()) . '/Admin';
        $this->assertFileDoesNotExist($admin . '/view/push-notifications.php');
        $this->assertFileDoesNotExist($admin . '/assets/js/push-notifications.js');

        $dashboard = file_get_contents($admin . '/view/index.php');
        $this->assertStringNotContainsString('System Monitoring', $dashboard);
        $this->assertStringNotContainsString('/admin/push-notifications', $dashboard);
        $this->assertStringNotContainsString('/admin/web-socket', $dashboard);
    }

    public function testViewPointsAtTheOfferingUrls(): void
    {
        $source = file_get_contents($this->moduleDir() . '/Admin/view/index.php');

        $this->assertStringNotContainsString('/admin/push-notifications', $source);
        $this->assertStringNotContainsString('/admin/delete-push-notifications', $source);
        $this->assertStringContainsString('action="/admin/push/delete"', $source);
        $this->assertStringContainsString('action="/admin/push"', $source);
    }

    public function testViewInlinesItsOwnScript(): void
    {
        if (!defined('MODULE_PATH')) {
            // MODULE_PATH is the site's modules/ folder: core/modules/Push -> three levels up
            define('MODULE_PATH', dirname($this->moduleDir(), 3) . '/modules/');
        }

        $admin = new class extends PushAdmin {
            public function __construct() {}
            public function __destruct() {}
        };
        $admin->subscriptionCount = 0;
        $admin->totalUsers = 0;
        $admin->totalCount = 0;
        $admin->users = [];
        $admin->notifications = [];

        // Views are included from Response::build()'s scope, so render them from there.
        ob_start();
        \Closure::bind(function ($view) { include $view; }, $admin, \Zero\Core\Response::class)(
            $this->moduleDir() . '/Admin/view/index.php'
        );
        $html = ob_get_clean();

        $js = trim(file_get_contents($this->moduleDir() . '/Admin/assets/push.js'));
        $this->assertStringContainsString($js, $html);

        $css = trim(file_get_contents($this->moduleDir() . '/Admin/assets/push.css'));
        $this->assertStringContainsString($css, $html);
        $this->assertStringContainsString('.push-notification-form {', $html);
        $this->assertStringNotContainsString('Admin asset not found', $html);
    }
}
