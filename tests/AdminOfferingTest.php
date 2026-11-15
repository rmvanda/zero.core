<?php

namespace Zero\Tests\Core;

use PHPUnit\Framework\TestCase;
use Zero\Core\AdminResponse;

/**
 * What counts as an admin offering, which cards a user sees, and which methods
 * of an offering a URL can reach.
 */
class AdminOfferingTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/zerotest-offerings-' . getmypid();
        mkdir($this->tmp . '/Ttrpgish/Admin/view', 0777, true);
    }

    protected function tearDown(): void
    {
        @rmdir($this->tmp . '/Ttrpgish/Admin/view');
        @rmdir($this->tmp . '/Ttrpgish/Admin');
        @rmdir($this->tmp . '/Ttrpgish');
        @rmdir($this->tmp);
    }

    private function modulesDir(): string
    {
        return dirname(__DIR__, 2) . '/modules/';
    }

    /** An AdminResponse that skips the session and answers permission checks from $held. */
    private function admin(array $held = ['allow.admin'])
    {
        return new class($held) extends AdminResponse {
            public function __construct(public array $held) {}
            public function __destruct() {}
            protected function userHolds(string $permission): bool { return in_array($permission, $this->held, true); }
            public function discovered(?string $root = null): array { return $this->getAdminModules($root); }
            public static function routable(string $class, string $method): bool { return static::isRoutableMethod($class, $method); }
        };
    }

    public function testAFolderWithoutAnOfferingClassIsNotListed(): void
    {
        $names = array_column($this->admin()->discovered($this->tmp . '/'), 'name');
        $this->assertNotContains('Ttrpgish', $names);
        $this->assertSame([], $names);
    }

    public function testAModuleWithItsOfferingClassIsListed(): void
    {
        $names = array_column($this->admin()->discovered($this->modulesDir()), 'name');
        $this->assertContains('Links', $names);
    }

    public function testCardsNeedEveryPermissionTheyList(): void
    {
        $without = array_column($this->admin(['allow.admin'])->discovered($this->modulesDir()), 'name');
        $this->assertNotContains('Billing', $without);
        $this->assertContains('Links', $without);

        $with = array_column($this->admin(['allow.admin', 'allow.aws.bill.read'])->discovered($this->modulesDir()), 'name');
        $this->assertContains('Billing', $with);
    }

    public function testBillingCardListsWhatGatesTheClass(): void
    {
        $class = new \ReflectionClass(\Zero\Module\Billing\Admin\BillingAdmin::class);
        $gate = $class->getAttributes(\Zero\Core\Attribute\RequirePermission::class)[0]->getArguments();
        $config = include $this->modulesDir() . 'Billing/Admin/admin-config.php';
        $this->assertSame($gate, $config['permissions']);
    }

    public function testOnlyAnOfferingsOwnPublicEndpointsRoute(): void
    {
        $admin = \Zero\Module\Billing\Admin\BillingAdmin::class;
        $this->assertTrue($this->admin()::routable($admin, 'index'));
        $this->assertTrue($this->admin()::routable($admin, 'getCurrentBilling'));

        foreach (['loadConfig', 'loadInlineAsset', '__construct', '__call', 'getAuxiliary', 'renderError', 'noSuchMethod'] as $method) {
            $this->assertFalse($this->admin()::routable($admin, $method), $method);
        }
        $this->assertFalse($this->admin()::routable(\Zero\Module\Entity\Admin\EntityAdmin::class, 'store'));
    }
}
