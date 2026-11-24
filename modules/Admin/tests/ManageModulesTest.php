<?php

declare(strict_types=1);

namespace Zero\Core\Module\Admin\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Zero\Core\Exception\HTTPError;
use Zero\Core\Modules;
use Zero\Core\ModulesIni;
use Zero\Core\Request;

/**
 * The Admin modules screen: its POST endpoint and its list.
 */
class ManageModulesTest extends TestCase
{
    private string $base;
    private string $ini;
    private mixed $json;
    /** @var array<string,mixed> */
    private array $post;

    protected function setUp(): void
    {
        \Zero\Core\Strings::loadDefaults(dirname(__DIR__) . '/strings/');
        // Loaded by path: the fixture roots configured below hold an empty Admin folder.
        require_once dirname(__DIR__) . '/submodule/Modules/Modules.php';
        $this->base = sys_get_temp_dir() . '/zerotest-managemodules-' . bin2hex(random_bytes(4));
        $this->ini = $this->base . '/app/modules.ini';
        mkdir($this->base . '/app', 0777, true);
        foreach (['bundled' => ['Auth', 'Admin', 'Sitemap', 'Push', 'Test'], 'site' => ['Blog', 'Null']] as $root => $names) {
            foreach ($names as $name) {
                mkdir("{$this->base}/{$root}/{$name}", 0777, true);
            }
        }
        Modules::configure(["{$this->base}/site/", "{$this->base}/bundled/"], $this->ini, "{$this->base}/bundled/");
        $this->json = Request::$acceptsJSON;
        $this->post = $_POST;
        http_response_code(200);
    }

    protected function tearDown(): void
    {
        Request::$acceptsJSON = $this->json;
        $_POST = $this->post;
        @chmod($this->base . '/app', 0777);
        exec('rm -rf ' . escapeshellarg($this->base));
        zeroTestConfigureModules();
    }

    /**
     * An Admin that records what it would have sent instead of sending it.
     */
    private function admin(): object
    {
        $core = "{$this->base}/bundled";
        return new class($core) extends \Zero\Core\Module\Admin\Modules {
            public array $exported = [];
            public ?string $builtView = null;
            public ?string $redirected = null;
            public int $finished = 0;
            public function __construct(private string $core) {}
            public function __destruct() {}
            protected function coreModulesDir(): string { return $this->core . '/'; }
            protected function export($data = null, array $extra = []) { $this->exported[] = [$data, $extra]; }
            protected function build($view = "") { $this->builtView = $view; }
            protected function redirectTo(string $url): void { $this->redirected = $url; }
            protected function finish(): void { $this->finished++; }
            public function rows(): array { return $this->moduleRows(); }
            public function apply(ModulesIni $ini, array $post): void { $this->applyModuleSetting($ini, $post); }
            public function prepare(): void { $this->prepareModulesScreen(); }
            public function open(): ModulesIni { return $this->modulesIni(); }
            public function get(string $prop) { return $this->$prop; }
        };
    }

    private function viewDir(): string
    {
        return dirname(__DIR__) . '/submodule/Modules/view';
    }

    private function iniText(): string
    {
        return (string) @file_get_contents($this->ini);
    }

    // ---- attributes --------------------------------------------------------

    public function testThePostEndpointCarriesItsAttributes(): void
    {
        $method = new \ReflectionMethod(\Zero\Core\Module\Admin\Modules::class, 'set');
        $attrs = [];
        foreach ($method->getAttributes() as $attr) {
            $attrs[$attr->getName()] = $attr->getArguments();
        }

        $this->assertSame(['POST'], $attrs[\Zero\Core\Attribute\AllowedMethods::class]);
        $this->assertSame([['module', 'hash']], $attrs[\Zero\Core\Attribute\RequiredParams::class]);
        $this->assertSame('module_setting_change', $attrs[\Zero\Core\Attribute\AuditLog::class][0]);
        $this->assertTrue($attrs[\Zero\Core\Attribute\AuditLog::class]['includeParams']);
    }

    public function testTheWholeAdminModuleSitsBehindTheAdminCapability(): void
    {
        foreach ([\Zero\Core\Module\Admin::class, \Zero\Core\Module\Admin\Modules::class] as $class) {
            $attrs = (new \ReflectionClass($class))->getAttributes(\Zero\Core\Attribute\RequirePermission::class);
            $this->assertCount(1, $attrs, $class);
            $this->assertSame(['allow.admin'], $attrs[0]->getArguments(), $class);
        }
    }

    // ---- applying a POST ---------------------------------------------------

    public function testApplyWritesTheResolvedRealName(): void
    {
        $ini = new ModulesIni($this->ini);
        $this->admin()->apply($ini, ['module' => 'sitemap', 'enabled' => 'off', 'order' => '12']);
        $ini->save();
        $text = $this->iniText();
        $this->assertStringContainsString("Sitemap = off\n", $text);
        $this->assertStringContainsString("Sitemap = 12\n", $text);
        $this->assertStringNotContainsString('sitemap', $text);
    }

    public function testApplyDefaultMapsToTheTierDefaultAndEmptyOrderClears(): void
    {
        // Test is an example module: it is off by default, so "default" must remove its "on".
        file_put_contents($this->ini, "[enabled]\nTest = on\n\n[order]\nTest = 4\n");
        $ini = new ModulesIni($this->ini);
        $this->admin()->apply($ini, ['module' => 'Test', 'enabled' => 'default', 'order' => '']);
        $ini->save();
        $this->assertStringNotContainsString('Test', $this->iniText());
        $this->configureAgain();
        $this->assertFalse(Modules::enabled('Test'));
    }

    public function testApplyOnForAnExampleModuleWritesIt(): void
    {
        $ini = new ModulesIni($this->ini);
        $this->admin()->apply($ini, ['module' => 'Test', 'enabled' => 'on']);
        $ini->save();
        $this->assertStringContainsString("Test = on\n", $this->iniText());
    }

    public function testApplyWithoutEnabledOrOrderChangesNothing(): void
    {
        file_put_contents($this->ini, "[enabled]\nSitemap = off\n\n[order]\nSitemap = 4\n");
        $ini = new ModulesIni($this->ini);
        $this->admin()->apply($ini, ['module' => 'Sitemap']);
        $ini->save();
        $this->assertStringContainsString("Sitemap = off\n", $this->iniText());
        $this->assertStringContainsString("Sitemap = 4\n", $this->iniText());
    }

    #[DataProvider('badPosts')]
    public function testApplyRefusesBadInput(array $post, int $code): void
    {
        $ini = new ModulesIni($this->ini);
        try {
            $this->admin()->apply($ini, $post);
            $this->fail('expected HTTPError');
        } catch (HTTPError $e) {
            $this->assertSame($code, $e->getCode());
        }
        $ini->save();
        $this->assertStringNotContainsString(' = ', $this->iniText());
    }

    public static function badPosts(): array
    {
        return [
            'module array' => [['module' => ['Sitemap']], 400],
            'module missing' => [[], 400],
            'unknown module' => [['module' => 'Nope', 'enabled' => 'off'], 404],
            'traversal' => [['module' => '../x', 'enabled' => 'off'], 404],
            'keyword name' => [['module' => 'Null', 'enabled' => 'off'], 400],
            'enabled array' => [['module' => 'Sitemap', 'enabled' => ['off']], 400],
            'enabled word' => [['module' => 'Sitemap', 'enabled' => 'maybe'], 400],
            'order array' => [['module' => 'Sitemap', 'order' => ['1']], 400],
            'order text' => [['module' => 'Sitemap', 'order' => '7; x'], 400],
            'order negative' => [['module' => 'Sitemap', 'order' => '-1'], 400],
            'order too big' => [['module' => 'Sitemap', 'order' => '10000'], 400],
            'order float' => [['module' => 'Sitemap', 'order' => '1.5'], 400],
        ];
    }

    private function configureAgain(): void
    {
        Modules::configure(["{$this->base}/site/", "{$this->base}/bundled/"], $this->ini, "{$this->base}/bundled/");
    }

    // ---- the rows ----------------------------------------------------------

    public function testRowsAreOrderedByTierThenNameWithWhereAndState(): void
    {
        file_put_contents($this->ini, "[enabled]\nPush = off\nTest = on\n\n[order]\nBlog = 5\n");
        $rows = $this->admin()->rows();

        $this->assertSame(
            ['Admin', 'Auth', 'Push', 'Test', 'Blog', 'Null', 'Sitemap'],
            array_column($rows, 'name')
        );
        $by = array_column($rows, null, 'name');
        $this->assertSame(['core', 'core', true, false], [$by['Admin']['tier'], $by['Admin']['where'], $by['Admin']['enabled'], $by['Admin']['overridden']]);
        $this->assertSame(['core', false, true], [$by['Push']['tier'], $by['Push']['enabled'], $by['Push']['departure']]);
        $this->assertFalse($by['Sitemap']['departure']);
        $this->assertTrue($by['Test']['enabled']);
        $this->assertTrue($by['Test']['departure']);
        $this->assertSame(['site', 'site', 5], [$by['Blog']['tier'], $by['Blog']['where'], $by['Blog']['order']]);
        $this->assertNull($by['Push']['order']);
        $this->assertFalse($by['Null']['settable']);
        $this->assertTrue($by['Blog']['settable']);
        $this->assertFalse($by['Blog']['overridden']);
    }

    public function testARowSaysWhenTheSiteOverridesAModuleOfCore(): void
    {
        mkdir("{$this->base}/site/Auth", 0777, true);
        Modules::configure(["{$this->base}/site/", "{$this->base}/bundled/"], $this->ini, "{$this->base}/bundled/");

        $by = array_column($this->admin()->rows(), null, 'name');
        $this->assertSame(['core', 'site', true], [$by['Auth']['tier'], $by['Auth']['where'], $by['Auth']['overridden']]);
        $this->assertFalse($by['Admin']['overridden']);

        $html = $this->render($this->admin()->rows());
        $this->assertSame(1, substr_count($html, 'class="badge module-overridden"'));
        $this->assertMatchesRegularExpression('#data-module="Auth".*?module-overridden#s', $html);
    }

    public function testAnyModuleCanBeTurnedOffAndAdminSaysHowToComeBack(): void
    {
        $ini = new ModulesIni($this->ini);
        $this->admin()->apply($ini, ['module' => 'Admin', 'enabled' => 'off']);
        $this->admin()->apply($ini, ['module' => 'Auth', 'enabled' => 'off']);
        $ini->save();
        $this->assertStringContainsString("Admin = off\n", $this->iniText());
        $this->assertStringContainsString("Auth = off\n", $this->iniText());
        Modules::configure(["{$this->base}/site/", "{$this->base}/bundled/"], $this->ini, "{$this->base}/bundled/");
        $this->assertFalse(Modules::enabled('Admin'));
    }

    // ---- the view ----------------------------------------------------------

    private function render(array $rows, ?string $error = null, ?string $hash = 'h4sh', bool $saved = false): string
    {
        $admin = $this->admin();
        $admin->moduleRows = $rows;
        $admin->saveError = $error;
        $admin->modulesHash = $hash;
        $admin->saved = $saved;
        ob_start();
        \Closure::bind(function ($view) { include $view; }, $admin, \Zero\Core\Response::class)(
            $this->viewDir() . '/index.php'
        );

        return ob_get_clean();
    }

    private function row(string $name, array $over = []): array
    {
        return $over + [
            'name' => $name, 'tier' => 'site', 'where' => 'site', 'enabled' => true, 'default' => true,
            'departure' => false, 'order' => null, 'overridden' => false, 'settable' => true,
        ];
    }

    public function testViewGivesEveryModuleASwitchAndAdminItsWarning(): void
    {
        $html = $this->render($this->admin()->rows());

        // every row holds an enabled field, except a module whose name is an INI keyword
        preg_match_all('#<tr[^>]*data-module="([^"]*)"[^>]*>(.*?)</tr>#s', $html, $rows, PREG_SET_ORDER);
        $this->assertNotEmpty($rows);
        foreach ($rows as [, $name, $body]) {
            $this->assertSame($name !== 'Null', str_contains($body, 'name="enabled"'), "{$name} switch");
            // Only Admin's row says how to turn it back on, and asks before it goes
            $this->assertSame($name === 'Admin', str_contains($body, 'module-warning'), "{$name} warning");
            $this->assertSame($name === 'Admin', str_contains($body, 'data-confirm-off='), "{$name} confirmation");
        }
        $this->assertStringNotContainsString('style=', $html);
    }

    public function testViewEscapesAQuoteInEveryAttributeAndTheText(): void
    {
        $html = $this->render([$this->row('Q"x<b>')], 'err "q" <i>', 'ha"sh');

        $this->assertStringNotContainsString('Q"x', $html);
        $this->assertStringNotContainsString('<b>', $html);
        $this->assertStringContainsString('data-module="Q&quot;x&lt;b&gt;"', $html);
        $this->assertStringContainsString('name="module" value="Q&quot;x&lt;b&gt;"', $html);
        $this->assertStringContainsString('id="module-form-Q&quot;x&lt;b&gt;"', $html);
        $this->assertStringContainsString('form="module-form-Q&quot;x&lt;b&gt;"', $html);
        $this->assertStringContainsString('aria-label="Order of Q&quot;x&lt;b&gt;"', $html);
        $this->assertStringContainsString('name="hash" value="ha&quot;sh"', $html);
        $this->assertStringContainsString('err &quot;q&quot; &lt;i&gt;', $html);
    }

    public function testViewShowsTheSaveErrorPlainly(): void
    {
        $html = $this->render([], 'Cannot write /x/app/modules.ini: the folder /x/app is not writable');
        $this->assertStringContainsString('Cannot write /x/app/modules.ini: the folder /x/app is not writable', $html);
    }

    public function testEveryFormCarriesTheHash(): void
    {
        $html = $this->render([$this->row('Blog'), $this->row('Zeta')], null, 'abc123');
        $this->assertSame(2, substr_count($html, '<input type="hidden" name="hash" value="abc123">'));
    }

    public function testViewShowsASavedLineOnlyWhenSaved(): void
    {
        $this->assertStringContainsString('Saved.', $this->render([], null, 'h', true));
        $this->assertStringNotContainsString('Saved.', $this->render([], null, 'h', false));
    }

    public function testAKeywordNamedModuleHasNoControlsAndANote(): void
    {
        $html = $this->render($this->admin()->rows());
        preg_match('#<tr[^>]*data-module="Null"[^>]*>(.*?)</tr>#s', $html, $m);
        $this->assertStringNotContainsString('<form', $m[1]);
        $this->assertStringNotContainsString('<select', $m[1]);
        $this->assertStringContainsString('cannot be set from app/modules.ini', $m[1]);
    }

    public function testWithoutAHashThereAreNoForms(): void
    {
        $html = $this->render([$this->row('Blog')], 'the file is broken', null);
        $this->assertStringNotContainsString('<form', $html);
        $this->assertStringContainsString('the file is broken', $html);
    }

    // ---- preparing the screen ---------------------------------------------

    public function testTheScreenIsDrawnWithTheHashOfTheFile(): void
    {
        file_put_contents($this->ini, "[enabled]\nSitemap = off\n");
        $admin = $this->admin();
        $admin->prepare();
        $this->assertSame((new ModulesIni($this->ini))->hash(), $admin->get('modulesHash'));
        $this->assertNull($admin->get('saveError'));
    }

    public function testTheRowsAndTheHashOfTheScreenDescribeTheSameContent(): void
    {
        $before = "[enabled]\nSitemap = off\n";
        file_put_contents($this->ini, $before);
        $this->configureAgain();
        $admin = $this->admin();
        $this->assertFalse(array_column($admin->rows(), null, 'name')['Sitemap']['enabled']);

        // A save lands while the screen is being drawn.
        file_put_contents($this->ini, "[enabled]\nSitemap = on\n");
        $admin->prepare();

        $this->assertFalse(array_column($admin->get('moduleRows'), null, 'name')['Sitemap']['enabled'], 'the rows show the old state');
        $this->assertSame(sha1($before), $admin->get('modulesHash'), 'and carry the hash of that state');

        // so a post from this screen is refused as stale
        $post = $this->post(['module' => 'Sitemap', 'order' => '3', 'hash' => $admin->get('modulesHash')], true);
        $this->assertSame(409, $post->exported[0][1]['code']);
    }

    public function testAFileTheWriterRefusesShowsTheErrorAndNoHash(): void
    {
        file_put_contents($this->ini, "[enabled]\nSitemap[] = on\n");
        $admin = $this->admin();
        $admin->prepare();
        $this->assertNull($admin->get('modulesHash'));
        $this->assertStringContainsString($this->ini, $admin->get('saveError'));
    }

    // ---- set() -------------------------------------------------------------

    private function post(array $post, bool $json): object
    {
        $_POST = $post;
        Request::$acceptsJSON = $json;
        $admin = $this->admin();
        $admin->set();

        return $admin;
    }

    private function hash(): string
    {
        return (new ModulesIni($this->ini))->hash();
    }

    public function testAMatchingHashIsSavedAndRedirectsWithTheSavedFlag(): void
    {
        $admin = $this->post(['module' => 'Sitemap', 'enabled' => 'off', 'hash' => $this->hash()], false);
        $this->assertStringContainsString("Sitemap = off\n", $this->iniText());
        $this->assertSame('/admin/modules?saved=1', $admin->redirected);
        $this->assertNull($admin->builtView);
    }

    public function testAMatchingHashIsSavedAsJson(): void
    {
        $admin = $this->post(['module' => 'Sitemap', 'enabled' => 'off', 'hash' => $this->hash()], true);
        $this->assertStringContainsString("Sitemap = off\n", $this->iniText());
        $this->assertTrue($admin->exported[0][0]['success']);
        $this->assertSame(200, $admin->exported[0][1]['code']);
        $this->assertNull($admin->redirected);
        $this->assertSame(1, $admin->finished);
    }

    public function testAScreenLeftOpenCannotOverwriteAnotherAdminsChange(): void
    {
        $drawn = $this->hash();
        // Admin A turns Sitemap off.
        $a = new ModulesIni($this->ini);
        $a->setEnabled('Sitemap', false);
        $a->save();
        $after = $this->iniText();

        // Admin B, from the page drawn earlier, changes only the order.
        $b = $this->post(['module' => 'Sitemap', 'enabled' => 'default', 'order' => '7', 'hash' => $drawn], true);

        $this->assertFalse($b->exported[0][0]['success']);
        $this->assertSame(409, $b->exported[0][1]['code']);
        $this->assertSame(409, http_response_code());
        $this->assertStringContainsString('Reload the page', $b->exported[0][0]['error']);
        $this->assertSame($after, $this->iniText(), "A's change survives and nothing of B's is written");
    }

    public function testAStaleFormPostShowsTheScreenAgainWithTheErrorAndAFreshHash(): void
    {
        $drawn = $this->hash();
        $a = new ModulesIni($this->ini);
        $a->setEnabled('Sitemap', false);
        $a->save();

        $b = $this->post(['module' => 'Sitemap', 'order' => '7', 'hash' => $drawn], false);

        $this->assertStringEndsWith('/view/index.php', (string) $b->builtView);
        $this->assertStringContainsString('Reload the page', $b->get('saveError'));
        $this->assertSame($this->hash(), $b->get('modulesHash'));
        $this->assertSame(409, http_response_code());
        $this->assertNull($b->redirected);
        $this->assertStringNotContainsString('Sitemap = 7', $this->iniText());
    }

    public function testAHashThatIsNotAStringIsABadRequest(): void
    {
        $this->expectException(HTTPError::class);
        $this->post(['module' => 'Sitemap', 'enabled' => 'off', 'hash' => ['x']], true);
    }

    public function testAnUnwritableFolderIsA500WithThePath(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('root ignores folder permissions');
        }
        file_put_contents($this->ini, "[enabled]\nPush = off\n");
        $hash = $this->hash();
        chmod($this->base . '/app', 0555);

        $json = $this->post(['module' => 'Sitemap', 'enabled' => 'off', 'hash' => $hash], true);
        $this->assertSame(500, $json->exported[0][1]['code']);
        $this->assertSame(500, http_response_code());
        $this->assertStringContainsString($this->ini, $json->exported[0][0]['error']);

        http_response_code(200);
        $html = $this->post(['module' => 'Sitemap', 'enabled' => 'off', 'hash' => $hash], false);
        $this->assertSame(500, http_response_code());
        $this->assertStringContainsString($this->ini, $html->get('saveError'));
        $this->assertStringEndsWith('/view/index.php', (string) $html->builtView);

        chmod($this->base . '/app', 0777);
        $this->assertSame("[enabled]\nPush = off\n", $this->iniText());
    }

    // ---- no ini configured -------------------------------------------------

    public function testNoIniConfiguredIsA500(): void
    {
        Modules::configure(["{$this->base}/site/"], null);
        try {
            $this->admin()->open();
            $this->fail('expected HTTPError');
        } catch (HTTPError $e) {
            $this->assertSame(500, $e->getCode());
            $this->assertStringContainsString('Modules are not configured', (string) $e->detail);
        }
    }
}
