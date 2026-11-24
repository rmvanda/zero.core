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
        exec('rm -rf ' . escapeshellarg($this->tmp));
    }

    /**
     * A throwaway module root with five offerings: FixA..FixE, each an
     * AdminResponse in its own namespace. FixC needs allow.fix.read besides
     * allow.admin. Returns the root with a trailing slash.
     */
    private function modulesDir(): string
    {
        $root = $this->tmp . '/fixtures/';
        foreach (['FixA', 'FixB', 'FixC', 'FixD', 'FixE'] as $name) {
            $dir = $root . $name . '/Admin/';
            if (!is_dir($dir)) {
                mkdir($dir, 0777, true);
            }
            $permissions = $name === 'FixC' ? "['allow.admin', 'allow.fix.read']" : "['allow.admin']";
            file_put_contents($dir . "{$name}Admin.php", "<?php\nnamespace Zero\\Module\\{$name}\\Admin;\n"
                . "#[\\Zero\\Core\\Attribute\\RequirePermission('allow.admin')]\n"
                . "class {$name}Admin extends \\Zero\\Core\\AdminResponse {\n"
                . "    public function index() {}\n    public function getThing() {}\n}\n");
            file_put_contents($dir . 'admin-config.php', "<?php\nreturn ['label' => '$name', 'permissions' => $permissions];\n");
            require_once $dir . "{$name}Admin.php";
        }
        return $root;
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
        $this->assertContains('FixA', $names);
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
            $listed = array_column($this->admin()->discovered(), 'name');
            $this->assertContains('FixA', $listed);
        } finally {
            spl_autoload_unregister($loader);
        }
    }

    public function testCardsNeedEveryPermissionTheyList(): void
    {
        $this->modules($this->modulesDir());
        $without = array_column($this->admin(['allow.admin'])->discovered(), 'name');
        $this->assertNotContains('FixC', $without);
        $this->assertContains('FixA', $without);

        $with = array_column($this->admin(['allow.admin', 'allow.fix.read'])->discovered(), 'name');
        $this->assertContains('FixC', $with);
    }

    public function testADisabledModuleHasNoCard(): void
    {
        $this->modules($this->modulesDir(), "[enabled]\nFixA = off\n");
        $names = array_column($this->admin()->discovered(), 'name');
        $this->assertNotContains('FixA', $names);
        $this->assertContains('FixB', $names);
    }

    public function testCardsFollowTheOrderSectionThenTheName(): void
    {
        $this->modules($this->modulesDir(), "[order]\nFixE = 1\nFixB = 2\nFixA = 2\n");
        $names = array_column($this->admin(['allow.admin', 'allow.fix.read'])->discovered(), 'name');
        $this->assertSame(['FixE', 'FixA', 'FixB'], array_slice($names, 0, 3));

        $rest = array_slice($names, 3);
        $sorted = $rest;
        sort($sorted);
        $this->assertSame($sorted, $rest, 'modules without an order come last, by name');
        $this->assertContains('FixC', $rest);
        $this->assertContains('FixD', $rest);
    }

    public function testCardsNoLongerReadEnabledOrOrderFromAdminConfig(): void
    {
        zeroTestConfigureModules();
        $files = [];
        foreach (Modules::all() as $dir) {
            if (is_file($dir . 'Admin/admin-config.php')) {
                $files[] = $dir . 'Admin/admin-config.php';
            }
        }
        $this->assertNotEmpty($files);
        foreach ($files as $file) {
            $config = include $file;
            $this->assertArrayNotHasKey('enabled', $config, $file);
            $this->assertArrayNotHasKey('order', $config, $file);
        }
    }

    public function testOnlyAnOfferingsOwnPublicEndpointsRoute(): void
    {
        $this->modulesDir();
        $admin = \Zero\Module\FixA\Admin\FixAAdmin::class;
        $this->assertTrue($this->admin()::routable($admin, 'index'));
        $this->assertTrue($this->admin()::routable($admin, 'getThing'));

        foreach (['loadConfig', 'loadInlineAsset', '__construct', '__call', 'getAuxiliary', 'renderError', 'noSuchMethod'] as $method) {
            $this->assertFalse($this->admin()::routable($admin, $method), $method);
        }
        $this->assertFalse($this->admin()::routable(\Zero\Core\Module\Entity\Admin\EntityAdmin::class, 'store'));
    }
}
