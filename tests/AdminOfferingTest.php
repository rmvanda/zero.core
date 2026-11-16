<?php

namespace Zero\Tests\Core;

use PHPUnit\Framework\TestCase;
use Zero\Core\AdminResponse;
use Zero\Core\Modules;

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
        zeroTestConfigureModules();
        @unlink($this->tmp . '/modules.ini');
        @rmdir($this->tmp . '/Ttrpgish/Admin/view');
        @rmdir($this->tmp . '/Ttrpgish/Admin');
        @rmdir($this->tmp . '/Ttrpgish');
        @rmdir($this->tmp);
    }

    private function modulesDir(): string
    {
        return dirname(__DIR__, 2) . '/modules/';
    }

    /** Modules over the given root, with an ini written from $ini. */
    private function modules(string $root, string $ini = ''): void
    {
        $file = $this->tmp . '/modules.ini';
        file_put_contents($file, $ini);
        Modules::configure([$root], $file);
    }

    /** An AdminResponse that skips the session and answers permission checks from $held. */
    private function admin(array $held = ['allow.admin'])
    {
        return new class($held) extends AdminResponse {
            public function __construct(public array $held) {}
            public function __destruct() {}
            protected function userHolds(string $permission): bool { return in_array($permission, $this->held, true); }
            public function discovered(): array { return $this->getAdminModules(); }
            public static function routable(string $class, string $method): bool { return static::isRoutableMethod($class, $method); }
        };
    }

    public function testAFolderWithoutAnOfferingClassIsNotListed(): void
    {
        $this->modules($this->tmp . '/');
        $names = array_column($this->admin()->discovered(), 'name');
        $this->assertNotContains('Ttrpgish', $names);
        $this->assertSame([], $names);
    }

    public function testAModuleWithItsOfferingClassIsListed(): void
    {
        $this->modules($this->modulesDir());
        $names = array_column($this->admin()->discovered(), 'name');
        $this->assertContains('Links', $names);
    }

    public function testAnOfferingThatThrowsOnLoadIsSkippedNotFatal(): void
    {
        mkdir($this->tmp . '/Boom/Admin', 0777, true);
        $file = $this->tmp . '/Boom/Admin/BoomAdmin.php';
        file_put_contents($file, "<?php\nthrow new \\RuntimeException('parse failure');\n");
        $loader = function ($class) use ($file) {
            if ($class === 'Zero\\Module\\Boom\\Admin\\BoomAdmin') {
                require $file;
            }
        };
        spl_autoload_register($loader);
        try {
            $this->modules($this->tmp . '/');
            $names = array_column($this->admin()->discovered(), 'name');
            $this->assertNotContains('Boom', $names);
            $this->modules($this->modulesDir());
            $links = array_column($this->admin()->discovered(), 'name');
            $this->assertContains('Links', $links);
        } finally {
            spl_autoload_unregister($loader);
            @unlink($file);
            @rmdir($this->tmp . '/Boom/Admin');
            @rmdir($this->tmp . '/Boom');
        }
    }

    public function testCardsNeedEveryPermissionTheyList(): void
    {
        $this->modules($this->modulesDir());
        $without = array_column($this->admin(['allow.admin'])->discovered(), 'name');
        $this->assertNotContains('Billing', $without);
        $this->assertContains('Links', $without);

        $with = array_column($this->admin(['allow.admin', 'allow.aws.bill.read'])->discovered(), 'name');
        $this->assertContains('Billing', $with);
    }

    public function testADisabledModuleHasNoCard(): void
    {
        $this->modules($this->modulesDir(), "[enabled]\nLinks = off\n");
        $names = array_column($this->admin()->discovered(), 'name');
        $this->assertNotContains('Links', $names);
        $this->assertContains('Issue', $names);
    }

    public function testCardsFollowTheOrderSectionThenTheName(): void
    {
        // Links and Issue tie on 2, and Links is found first when the roots
        // are listed with a folder of its own ahead of the real tree.
        $first = $this->tmp . '/first/';
        mkdir($first . 'Links/Admin', 0777, true);
        $this->modules($this->modulesDir(), "[order]\nS = 1\nLinks = 2\nIssue = 2\n");
        Modules::configure([$first, $this->modulesDir()], $this->tmp . '/modules.ini');
        $names = array_column($this->admin()->discovered(), 'name');
        $this->assertSame(['S', 'Issue', 'Links'], array_slice($names, 0, 3));
        @rmdir($first . 'Links/Admin');
        @rmdir($first . 'Links');
        @rmdir($first);

        $rest = array_slice($names, 3);
        $sorted = $rest;
        sort($sorted);
        $this->assertSame($sorted, $rest, 'modules without an order come last, by name');
    }

    public function testCardsNoLongerReadEnabledOrOrderFromAdminConfig(): void
    {
        foreach (glob($this->modulesDir() . '*/Admin/admin-config.php') as $file) {
            $config = include $file;
            $this->assertArrayNotHasKey('enabled', $config, $file);
            $this->assertArrayNotHasKey('order', $config, $file);
        }
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
