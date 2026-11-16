<?php

namespace Zero\Tests\Core;

use PHPUnit\Framework\TestCase;
use Zero\Core\Application;
use Zero\Core\Console;
use Zero\Core\HTTPError;
use Zero\Core\Modules;
use Zero\Core\Request;
use Zero\Core\Response;

/**
 * A module is found through Modules, and a module that is switched off is
 * unreachable: every step that looks a module up answers as it does for a
 * module that does not exist. Every test builds throwaway trees and hands them
 * to Modules::configure().
 */
class ModuleRoutingTest extends TestCase
{
    private string $base;
    private string $site;
    private string $bundled;
    private string $ini;
    private string $savedModule;
    private $savedLog;
    private $savedDomain;
    private array $savedRequest = [];
    private ?string $webRoot = null;

    protected function setUp(): void
    {
        if (!defined('ZERO_ROOT')) {
            define('ZERO_ROOT', dirname(__DIR__, 2) . '/');
        }
        $this->savedModule = Request::$Module ?? '';
        $this->savedLog = Console::$defaultLogFile;
        Console::$defaultLogFile = '/dev/null';
        if (!defined('WEB_ROOT')) {
            define('WEB_ROOT', sys_get_temp_dir() . '/zero-asset-version-test-root');
        }
        $this->webRoot = str_starts_with(WEB_ROOT, sys_get_temp_dir()) ? WEB_ROOT : null;
        $this->savedDomain = Request::$domain ?? '';
        foreach (['module', 'moduleOrig', 'endpoint', 'endpointOrig'] as $key) {
            $this->savedRequest[$key] = Request::$$key ?? null;
        }
        $GLOBALS['zerotest_loaded'] = [];
        spl_autoload_register([Application::class, 'autoloader']);
        $this->base = sys_get_temp_dir() . '/zerotest-routing-' . bin2hex(random_bytes(4));
        $this->site = $this->base . '/site/';
        $this->bundled = $this->base . '/bundled/';
        $this->ini = $this->base . '/modules.ini';

        // Lists: on, site. Gone: off, site. Shipped: on, second root only.
        foreach ([$this->site . 'Lists', $this->site . 'Gone', $this->bundled . 'Shipped'] as $dir) {
            mkdir($dir . '/view', 0777, true);
            mkdir($dir . '/frame');
            mkdir($dir . '/aux');
            file_put_contents($dir . '/view/page.php', '<p>page</p>');
            file_put_contents($dir . '/config.ini', "SOME_CONSTANT = 1\n");
            $name = basename($dir);
            file_put_contents($dir . '/' . $name . '.php', "<?php\nnamespace Zero\\Module;\nclass {$name} {}\n");
        }
        file_put_contents($this->bundled . 'Shipped/aux/mark.php', '<?php echo "aux ran";');
        file_put_contents($this->ini, "[enabled]\nGone = off\n");
        Modules::configure([$this->site, $this->bundled], $this->ini);
    }

    protected function tearDown(): void
    {
        // The tree goes whatever else this method does, and a directory a
        // test made read-only is made writable again first.
        try {
            spl_autoload_unregister([Application::class, 'autoloader']);
            unset($GLOBALS['zerotest_loaded']);
            Request::$Module = $this->savedModule;
            Request::$domain = $this->savedDomain;
            foreach ($this->savedRequest as $key => $value) {
                Request::$$key = $value;
            }
            Console::$defaultLogFile = $this->savedLog;
            zeroTestConfigureModules();
        } finally {
            foreach (glob($this->base . '/{,*/,*/*/}', GLOB_BRACE) ?: [] as $dir) {
                @chmod($dir, 0755);
            }
            $this->remove($this->base);
        }
    }

    private function remove(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);
            return;
        }
        if (is_dir($path)) {
            foreach (scandir($path) as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    $this->remove($path . '/' . $entry);
                }
            }
            rmdir($path);
        }
    }

    /** A Response with its constructor and destructor disarmed. */
    private function probe(): object
    {
        return new class extends Response {
            public function __construct()
            {
                foreach ($this->paths as $path) {
                    $this->{$path . 'Path'} = null;
                }
            }
            public function __destruct() {}
            public function bases(): void { $this->defineBasePaths(); }
            public function paths(): array { return ['frame' => $this->framePath, 'view' => $this->viewPath]; }
        };
    }

    /** A Module with its constructor and destructor disarmed. */
    private function moduleProbe(): object
    {
        return new class extends \Zero\Core\Module {
            public function __construct() {}
            public function __destruct() {}
            public function aux(string $file): void { $this->getAuxiliary($file); }
        };
    }

    public function testADisabledModuleHasNoControllerFile(): void
    {
        $this->assertNull(Application::controllerFile('Gone'));
        $this->assertNull(Application::controllerFile('gone'));
        $this->assertNull(Application::controllerFile('Nope'));
        $this->assertSame($this->site . 'Lists/Lists.php', Application::controllerFile('Lists'));
    }

    public function testAModuleOnlyInTheSecondRootRoutes(): void
    {
        $this->assertTrue(Modules::routable('shipped'));
        $this->assertSame($this->bundled . 'Shipped/Shipped.php', Application::controllerFile('Shipped'));
    }

    public function testTheViewFallbackFindsNoViewInADisabledModule(): void
    {
        $this->assertNull(Response::findModuleView('Gone', 'page'));
        $this->assertNull(Response::findModuleView('gone', 'page'));
        $this->assertNull(Response::findModuleView('Nope', 'page'));
        $this->assertSame($this->site . 'Lists/view/page.php', Response::findModuleView('lists', 'page'));
        $this->assertNull(Response::findModuleView('Lists', 'missing'));
        $this->assertNull(Response::findModuleView('Lists', '../../Gone/view/page'));
    }

    public function testAModuleInTheSecondRootResolvesViewsFrameAuxAndConfig(): void
    {
        $this->assertSame($this->bundled . 'Shipped/view/page.php', Response::findModuleView('Shipped', 'page'));

        Request::$Module = 'Shipped';
        $probe = $this->probe();
        $probe->bases();
        $this->assertSame($this->bundled . 'Shipped/frame/', $probe->paths()['frame']);
        $this->assertSame($this->bundled . 'Shipped/view/', $probe->paths()['view']);

        ob_start();
        $this->moduleProbe()->aux('mark');
        $this->assertSame('aux ran', ob_get_clean());

        $this->assertSame($this->bundled . 'Shipped/', Application::moduleConfigDir('Shipped'));
    }

    public function testADisabledModuleLoadsNoFrameAuxOrConfig(): void
    {
        Request::$Module = 'Gone';
        $probe = $this->probe();
        $probe->bases();
        $this->assertSame(ZERO_ROOT . 'app/frontend/frame/', $probe->paths()['frame']);
        $this->assertSame(ZERO_ROOT . 'app/frontend/view/', $probe->paths()['view']);

        file_put_contents($this->site . 'Gone/aux/mark.php', '<?php echo "aux ran";');
        ob_start();
        $this->moduleProbe()->aux('mark');
        $this->assertSame('', ob_get_clean());

        $this->assertNull(Application::moduleConfigDir('Gone'));
        $this->assertNull(Application::moduleConfigDir('Nope'));
        $this->assertSame($this->site . 'Lists/', Application::moduleConfigDir('Lists'));
    }

    /** An Admin whose constructor and destructor do nothing: the real __call and routing run. */
    private function adminController(): object
    {
        return new class extends \Zero\Module\Admin {
            public function __construct() {}
            public function __destruct() {}
        };
    }

    private function writeOffering(string $root, string $module): void
    {
        mkdir("{$root}{$module}/Admin", 0777, true);
        file_put_contents("{$root}{$module}/{$module}.php", "<?php\nnamespace Zero\\Module;\nclass {$module} {}\n");
        file_put_contents(
            "{$root}{$module}/Admin/{$module}Admin.php",
            "<?php\nnamespace Zero\\Module\\{$module}\\Admin;\n"
            . "\$GLOBALS['zerotest_loaded'][] = '{$module}Admin';\n"
            . "class {$module}Admin extends \\Zero\\Core\\AdminResponse {\n"
            . "    public \$framePath; public \$viewPath; public \$componentPath; public \$modelPath;\n"
            . "    public function __construct() {}\n    public function __destruct() {}\n"
            . "    public function index() { return 'ran'; }\n}\n"
        );
    }

    public function testAdminOfAnEnabledModuleIsLoadedAndRuns(): void
    {
        $this->writeOffering($this->site, 'Zctl');

        $this->assertSame('ran', $this->adminController()->zctl());
        $this->assertSame(['ZctlAdmin'], $GLOBALS['zerotest_loaded'], 'the control: the file is loaded when the module is on');
    }

    public function testAdminOfADisabledModuleIsNeverLoaded(): void
    {
        $this->writeOffering($this->site, 'Zoff');
        file_put_contents($this->ini, "[enabled]\nGone = off\nZoff = off\n");
        Modules::configure([$this->site, $this->bundled], $this->ini);

        try {
            $this->adminController()->zoff();
            $this->fail('expected a 404');
        } catch (HTTPError $e) {
            $this->assertSame(404, $e->getCode());
        }
        $this->assertSame([], $GLOBALS['zerotest_loaded'], 'the admin file was never included');
    }

    public function testAdminRejectsANameThatIsNotAClassName(): void
    {
        // Modules::all() lists only folders named ^[A-Z][A-Za-z0-9]*$, so Modules
        // does not route this name; the isModuleName() check refuses it too, and
        // PHP's own class_exists() would also refuse to autoload it, so the
        // helper is asserted directly.
        $this->writeOffering($this->site, 'Zerotest.Odd');
        $this->assertFalse(Modules::routable('Zerotest.Odd'));
        $this->assertFalse(Application::isModuleName('Zerotest.Odd'));

        try {
            $this->adminController()->{'Zerotest.Odd'}();
            $this->fail('expected a 404');
        } catch (HTTPError $e) {
            $this->assertSame(404, $e->getCode());
        }
        $this->assertSame([], $GLOBALS['zerotest_loaded'], 'the admin file was never included');
    }

    public function testAModuleThatIsOffGetsNoPublicAssetLink(): void
    {
        mkdir($this->site . 'Gone/assets');
        mkdir($this->site . 'Lists/assets');
        mkdir($this->base . '/www/assets', 0777, true);
        $web = $this->base . '/www/assets/';

        $this->assertFalse(\Zero\Core\Module::linkAssets('Gone', $this->site . 'Gone/assets', $web . 'gone'));
        $this->assertFalse(is_link($web . 'gone'));
        $this->assertTrue(\Zero\Core\Module::linkAssets('Lists', $this->site . 'Lists/assets', $web . 'lists'));
        $this->assertTrue(is_link($web . 'lists'));
    }

    public function testASubmoduleOfAModuleInTheSecondRootInheritsItsParentsFrame(): void
    {
        $dir = $this->bundled . 'Shipped/submodule/Sub';
        mkdir($dir, 0777, true);
        file_put_contents(
            $dir . '/Sub.php',
            "<?php\nnamespace Zero\\Module\\Shipped;\n"
            . "class Sub extends \\Zero\\Core\\Module {\n"
            . "    public \$framePath; public \$viewPath; public \$componentPath; public \$modelPath;\n"
            . "    public function __construct() {}\n    public function __destruct() {}\n"
            . "    public function paths(): array { \$this->defineBasePaths(); return [\$this->framePath, \$this->viewPath]; }\n"
            . "}\n"
        );
        require_once $dir . '/Sub.php';

        $paths = (new \Zero\Module\Shipped\Sub())->paths();

        $this->assertSame($this->bundled . 'Shipped/frame/', $paths[0], "the parent's frame, found in the second root");
        $this->assertSame(ZERO_ROOT . 'app/frontend/view/', $paths[1], 'a view the submodule lacks falls to the site default');
    }

    public function testRoutingToADisabledModulesAdminIs404BeforeAnythingIsBuilt(): void
    {
        $this->writeOffering($this->site, 'Zoff');
        file_put_contents($this->ini, "[enabled]\nGone = off\nZoff = off\n");
        Modules::configure([$this->site, $this->bundled], $this->ini);
        $admin = new class extends \Zero\Core\AdminResponse {
            public function __construct() {}
            public function __destruct() {}
            public function route(string $module, string $method)
            {
                return $this->routeToModuleAdmin($module, $method);
            }
        };

        try {
            $admin->route('Zoff', 'index');
            $this->fail('expected a 404');
        } catch (HTTPError $e) {
            $this->assertSame(404, $e->getCode());
        }
        $this->assertSame([], $GLOBALS['zerotest_loaded']);
    }

    /** The __call a visitor's request really reaches, with build() recorded instead of rendered. */
    private function callProbe(): object
    {
        return new class extends Response {
            public ?string $viewBuilt = null;
            public function __construct() {}
            public function __destruct() {}
            protected function build($view = "") { $this->viewBuilt = $view; }
        };
    }

    public function testResponseCallBuildsAViewOfAnEnabledModuleOnly(): void
    {
        Request::$module = Request::$moduleOrig = 'lists';
        Request::$endpoint = Request::$endpointOrig = 'page';
        $probe = $this->callProbe();
        $probe->nothingHere();
        $this->assertSame($this->site . 'Lists/view/page.php', $probe->viewBuilt);

        Request::$module = Request::$moduleOrig = 'gone';
        $probe = $this->callProbe();
        try {
            $probe->nothingHere();
            $this->fail('expected a 404');
        } catch (HTTPError $e) {
            $this->assertSame(404, $e->getCode());
        }
        $this->assertNull($probe->viewBuilt);
    }

    public function testCheckModulesDropsLinksAndFailsOnAClashWithoutThrowing(): void
    {
        mkdir($this->site . 'Gone/assets');
        $web = $this->base . '/www';
        mkdir($web . '/assets', 0777, true);
        symlink($this->site . 'Gone/assets', $web . '/assets/goneAlias');

        $this->assertTrue(Application::checkModules($web, $this->base . '/marker'));
        $this->assertFalse(is_link($web . '/assets/goneAlias'));
        $this->assertTrue(Application::checkModules(null, $this->base . '/marker'));

        mkdir($this->bundled . 'Lists');
        Modules::configure([$this->site, $this->bundled], $this->ini);
        $this->assertFalse(Application::checkModules($web, $this->base . '/marker'), 'a name held by two roots');
    }

    private function useWebRoot(): string
    {
        if ($this->webRoot === null) {
            $this->markTestSkipped('WEB_ROOT is a real document root');
        }
        return $this->webRoot . '/assets/';
    }

    private function printAssets(string $module): string
    {
        $probe = new class extends Response {
            public function __construct() {}
            public function __destruct() {}
            public function emit(): void { $this->getScripts(); $this->getStylesheets(); }
        };
        Request::$module = Request::$moduleOrig = $module;
        Request::$endpoint = Request::$endpointOrig = 'index';
        ob_start();
        $probe->emit();
        return ob_get_clean();
    }

    public function testTheNotFoundPageOfADisabledModuleEmitsNoOfItsAssets(): void
    {
        $assets = $this->useWebRoot();
        @mkdir($assets, 0777, true);
        foreach (['NetGone', 'NetOn'] as $name) {
            mkdir($this->site . $name . '/assets/js', 0777, true);
            mkdir($this->site . $name . '/assets/css', 0777, true);
            $url = lcfirst($name);
            file_put_contents($this->site . $name . "/assets/js/{$url}.js", '//');
            file_put_contents($this->site . $name . "/assets/css/{$url}.css", '/**/');
            symlink($this->site . $name . '/assets', $assets . $url);
        }
        file_put_contents($this->ini, "[enabled]\nNetGone = off\n");
        Modules::configure([$this->site, $this->bundled], $this->ini);

        try {
            $this->assertStringContainsString('/assets/netOn/js/netOn.js', $this->printAssets('netOn'), 'the control');

            $this->assertTrue(Application::checkModules($this->webRoot, $this->base . '/marker'));
            $this->assertSame('', $this->printAssets('netGone'));
            $this->assertStringContainsString('/assets/netOn/js/netOn.js', $this->printAssets('netOn'));
        } finally {
            foreach (['netGone', 'netOn'] as $url) {
                if (is_link($assets . $url)) {
                    unlink($assets . $url);
                }
            }
        }
    }

    public function testAdoptFrameLendsNothingFromADisabledModule(): void
    {
        Request::$domain = 'unisolu.com';
        $probe = $this->probe();
        $adopt = new \ReflectionMethod($probe, 'adoptFrameFrom');

        $this->assertFalse($adopt->invoke($probe, 'https://unisolu.com/gone/page'));
        $this->assertNull($probe->paths()['frame']);
        $this->assertTrue($adopt->invoke($probe, 'https://unisolu.com/lists/page'));
        $this->assertSame($this->site . 'Lists/frame/', $probe->paths()['frame']);
    }

    public function testComponentsOfADisabledModuleAreNotLoaded(): void
    {
        foreach (['Lists', 'Gone'] as $name) {
            mkdir($this->site . $name . '/assets/component', 0777, true);
            file_put_contents($this->site . $name . '/assets/component/mark.html', "<!-- {$name}-component -->");
        }
        $probe = new class extends Response {
            public function __construct() {}
            public function __destruct() {}
            public function components(): void { $this->getComponents(); }
        };

        Request::$Module = 'Gone';
        ob_start();
        $probe->components();
        $this->assertStringNotContainsString('Gone-component', ob_get_clean());

        Request::$Module = 'Lists';
        ob_start();
        $probe->components();
        $this->assertStringContainsString('Lists-component', ob_get_clean());
    }
}
