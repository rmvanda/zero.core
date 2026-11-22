<?php

namespace Zero\Module\Admin\Tests;

use PHPUnit\Framework\TestCase;
use Zero\Core\AdminResponse;

/**
 * The Admin dashboard lists what discovery finds. Users, Groups, Modules and Firewall are the
 * only links written into the view; every feature screen is another module's offering.
 */
class AdminDashboardTest extends TestCase
{
    private function adminDir(): string
    {
        return dirname(__DIR__);
    }

    private function zeroRoot(): string
    {
        // core/modules/Admin -> the site root is three levels up
        return dirname($this->adminDir(), 3);
    }

    public function testNoHardCodedFeatureLinks(): void
    {
        $source = file_get_contents($this->adminDir() . '/view/index.php');

        foreach (['/admin/manage/event', 'api-transactions', 'push-notifications', 'web-socket', 'create-entity', 'entity_type'] as $needle) {
            $this->assertStringNotContainsString($needle, $source, "the dashboard does not link {$needle}");
        }

        preg_match_all('#href="([^"]*)"#', $source, $links);
        $hardCoded = array_values(array_filter($links[1], fn($href) => !str_contains($href, '<?')));
        $this->assertSame([], $hardCoded, 'the dashboard writes no link itself: every card is discovered');
    }

    public function testAdminsOwnScreensAreDiscoveredInTheirOrder(): void
    {
        $admin = new class extends \Zero\Module\Admin {
            public function __construct() {}
            public function __destruct() {}
            public function screens(): array { return $this->ownScreens(); }
            public function own(string $segment): bool { return $this->isOwnScreen($segment); }
        };

        $screens = $admin->screens();
        $this->assertSame(['Users', 'Groups', 'Permissions', 'Modules', 'Firewall'], array_column($screens, 'name'));
        $this->assertSame(
            ['/admin/users', '/admin/groups', '/admin/permissions', '/admin/modules', '/admin/firewall'],
            array_column($screens, 'url')
        );
        foreach ($screens as $screen) {
            $this->assertNotSame('', $screen['label'], $screen['name']);
            $this->assertNotSame('', $screen['icon'], $screen['name']);
        }

        $this->assertTrue($admin->own('users'));
        $this->assertTrue($admin->own('firewall'));
        $this->assertFalse($admin->own('links'));
        $this->assertFalse($admin->own('../Admin'));
    }

    public function testDiscoveryFindsTheOfferingsOfFixtureModules(): void
    {
        $tmp = sys_get_temp_dir() . '/zerotest-offerings-' . getmypid();
        exec('rm -rf ' . escapeshellarg($tmp));
        try {
            // two site modules with an offering, one folder that is not an offering
            foreach (['Zerotestone', 'Zerotesttwo'] as $name) {
                mkdir("$tmp/modules/$name/Admin", 0777, true);
                file_put_contents("$tmp/modules/$name/Admin/{$name}Admin.php",
                    "<?php\nnamespace Zero\\Module\\$name\\Admin;\nclass {$name}Admin {}\n");
                file_put_contents("$tmp/modules/$name/Admin/admin-config.php",
                    "<?php\nreturn ['label' => 'Fixture $name', 'icon' => 'bolt'];\n");
                require_once "$tmp/modules/$name/Admin/{$name}Admin.php";
            }
            mkdir("$tmp/modules/Zerotestnone/Admin", 0777, true);
            $root = dirname($this->adminDir(), 3) . '/';
            \Zero\Core\Modules::configure(["$tmp/modules/", $root . 'core/modules/'], null);

            $admin = new class extends \Zero\Module\Admin {
                public function __construct() {}
                public function __destruct() {}
                protected function userHolds(string $permission): bool { return true; }
                public function discovered(): array { return $this->getAdminModules(); }
            };

            $found = $admin->discovered();
            $names = array_column($found, 'name');
            foreach (['Zerotestone', 'Zerotesttwo', 'Entity', 'Push'] as $name) {
                $this->assertContains($name, $names, "{$name} is discovered");
            }
            $this->assertNotContains('Zerotestnone', $names, 'a folder without an offering class is not listed');
            $this->assertSame('Fixture Zerotestone', $found[array_search('Zerotestone', $names, true)]['label']);
        } finally {
            zeroTestConfigureModules();
            exec('rm -rf ' . escapeshellarg($tmp));
        }
    }

    public function testDashboardHasUsersAndModulesSections(): void
    {
        $source = file_get_contents($this->adminDir() . '/view/index.php');

        $this->assertSame(2, preg_match_all('#<h2\b#', $source), 'two sections');
        $this->assertStringContainsString('Users and access', $source);
        $this->assertStringContainsString('Modules', $source);
        $this->assertStringNotContainsString('style=', $source);
    }

    public function testDashboardRendersDiscoveredCardsEscaped(): void
    {
        $admin = new class extends \Zero\Module\Admin {
            public function __construct() {}
            public function __destruct() {}
        };
        $admin->adminModules = [[
            'name' => 'X', 'label' => '<b>Label</b>', 'icon' => 'bolt',
            'description' => '<i>desc</i>', 'url' => '/admin/x',
        ]];
        $admin->ownScreens = [[
            'name' => 'Y', 'label' => '<b>Own</b>', 'icon' => 'bolt',
            'description' => '', 'order' => 1, 'url' => '/admin/y',
        ]];

        ob_start();
        \Closure::bind(function ($view) { include $view; }, $admin, \Zero\Core\Response::class)(
            $this->adminDir() . '/view/index.php'
        );
        $html = ob_get_clean();

        $this->assertStringContainsString('&lt;b&gt;Label&lt;/b&gt;', $html);
        $this->assertStringNotContainsString('<b>Label</b>', $html);
        $this->assertStringContainsString('href="/admin/x"', $html);
        $this->assertStringContainsString('&lt;b&gt;Own&lt;/b&gt;', $html);
        $this->assertStringContainsString('href="/admin/y"', $html);
    }
}
