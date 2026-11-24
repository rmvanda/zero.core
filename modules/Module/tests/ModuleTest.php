<?php
namespace Zero\Core\Module\Module\Tests;

use Zero\Tests\Core\ZeroTestCase;

/**
 * Guards the skeleton's own conventions. If this fails, mkmod's
 * anchored substitutions (which assume these exact names) are unsafe.
 */
class ModuleTest extends ZeroTestCase
{
    private const SKEL = __DIR__ . '/..';

    public function testControllerClassMatchesItsFilename(): void
    {
        $src = file_get_contents(self::SKEL . '/Module.php');
        $this->assertMatchesRegularExpression(
            '/^class Module extends \\\\Zero\\\\Core\\\\Module/m',
            $src,
            'mkmod anchors on "^class Module" — do not rename or reformat this line.'
        );
    }

    public function testControllerDeclaresBareModuleNamespace(): void
    {
        $src = file_get_contents(self::SKEL . '/Module.php');
        $this->assertMatchesRegularExpression(
            '/^namespace Zero\\\\Core\\\\Module;$/m',
            $src,
            'mkmod relies on this exact line; do not reformat it.'
        );
    }

    public function testAssetsFollowTheModuleNameConvention(): void
    {
        $this->assertFileExists(self::SKEL . '/assets/css/module.css');
        $this->assertFileExists(self::SKEL . '/assets/js/module.js');
        $this->assertFileExists(self::SKEL . '/assets/css/index.css');
    }

    public function testHelpersPluralDirectoryIsGone(): void
    {
        $this->assertDirectoryDoesNotExist(
            self::SKEL . '/helpers',
            'The convention is helper/ (singular), matching Ttrpg/helper/.'
        );
    }

    public function testNoInlineStylesInViews(): void
    {
        // Covers the module's own views, the Admin views, and every submodule
        // view. Globbing only view/*.php leaves the dirs added later unguarded.
        $views = array_merge(
            glob(self::SKEL . '/view/*.php'),
            glob(self::SKEL . '/Admin/view/*.php'),
            glob(self::SKEL . '/submodule/*/view/*.php')
        );
        $this->assertNotEmpty($views, 'no views found — this test would otherwise pass vacuously');
        foreach ($views as $view) {
            $this->assertDoesNotMatchRegularExpression(
                '/<[^>]+\sstyle=/i',
                file_get_contents($view),
                $view . ' uses an inline style attribute.'
            );
        }
    }

    public function testModelNamespaceIsAutoloadable(): void
    {
        $src = file_get_contents(self::SKEL . '/model/ModuleModel.php');
        $this->assertStringContainsString(
            'namespace Zero\Core\Module\Module\Model;',
            $src,
            'Response.php autoloads only classes whose namespace contains \\Model\\.'
        );
        $this->assertMatchesRegularExpression('/^class ModuleModel\b/m', $src);
    }

    public function testFrameHeadPreservesAutoLoading(): void
    {
        $src = file_get_contents(self::SKEL . '/frame/head.php');
        foreach (['getStylesheets', 'getScripts', 'getComponents'] as $method) {
            $this->assertStringContainsString(
                '$this->' . $method . '()',
                $src,
                "Overriding frame/head.php without calling {$method}() silently breaks asset auto-loading."
            );
        }
    }

    /** Renders the skeleton's head the way a Response would, with the site's frame folder $siteDir. */
    private function renderHead(string $siteDir): string
    {
        $stub = new class($siteDir) {
            public $pageTitle = 'T';
            public function __construct(private string $siteDir) {}
            public function framePiecePath(string $name): ?string
            {
                return \Zero\Tests\Core\Reach::call(\Zero\Core\Response::class, 'framePieceFile', $name, [$this->siteDir]);
            }
            public function getStylesheets() {}
            public function getScripts() {}
            public function getComponents() {}
            public function render(string $file): string
            {
                ob_start();
                include $file;
                return (string) ob_get_clean();
            }
        };
        return $stub->render(self::SKEL . '/frame/head.php');
    }

    public function testHeadTakesItsScriptsFromTheHeadScriptsFramePiece(): void
    {
        $site = sys_get_temp_dir() . '/zerotest-headscripts-' . getmypid() . '/';
        mkdir($site, 0777, true);
        try {
            $bare = $this->renderHead($site);
            $this->assertStringNotContainsString('shadow-component', $bare);
            $this->assertStringNotContainsString('googleapis', $bare);
            $this->assertStringContainsString('/assets/css/main.css', $bare);

            file_put_contents($site . '_head-scripts.php', '<script src="/site/own.js"></script>');
            $this->assertStringContainsString('<script src="/site/own.js"></script>', $this->renderHead($site));
        } finally {
            @unlink($site . '_head-scripts.php');
            rmdir($site);
        }
    }

    public function testTheSitesOwnHeadScriptsPieceEmitsTheComponentScript(): void
    {
        $site = dirname(__DIR__, 4) . '/app/frontend/frame/';
        if (!is_file($site . '_head-scripts.php')) {
            $this->markTestSkipped('this site has no _head-scripts.php');
        }
        $this->assertStringContainsString('<script src="/shadow-component/shadow-component.js"></script>', $this->renderHead($site));
    }

    public function testComponentRegistersItsCustomElement(): void
    {
        $src = file_get_contents(self::SKEL . '/assets/component/module-panel.html');
        $this->assertStringContainsString('<template id="module-panel">', $src);
        $this->assertStringContainsString('class ModulePanel extends ShadowComponent', $src);
        $this->assertStringContainsString('customElements.define("module-panel", ModulePanel)', $src);
    }

    public function testComponentUsesTheRealLifecycleHook(): void
    {
        // ShadowComponent defines connectedCallback/disconnectedCallback/
        // attributeChangedCallback/onError. There is NO afterRender hook — a
        // component defining one gets silent dead code. The skeleton must not
        // teach a hook that does not exist.
        $src = file_get_contents(self::SKEL . '/assets/component/module-panel.html');
        $this->assertStringContainsString('super.connectedCallback()', $src);
        $this->assertStringNotContainsString('afterRender', $src);
    }

    public function testConfigExamplesExistInBothForms(): void
    {
        $this->assertFileExists(self::SKEL . '/config.ini');
        $this->assertFileExists(self::SKEL . '/config/example.ini');
    }

    public function testConfigConstantsUseTheModulePrefix(): void
    {
        // mkmod rewrites ^MODULE_ in .ini files only, never in .php
        // (MODULE_PATH is a real framework constant).
        $this->assertMatchesRegularExpression(
            '/^MODULE_/m',
            file_get_contents(self::SKEL . '/config.ini')
        );
    }

    public function testAuxFragmentExists(): void
    {
        $this->assertFileExists(self::SKEL . '/aux/nav.php');
    }

    public function testAdminControllerExtendsAdminResponse(): void
    {
        $src = file_get_contents(self::SKEL . '/Admin/ModuleAdmin.php');
        $this->assertStringContainsString('namespace Zero\Core\Module\Module\Admin;', $src);
        $this->assertMatchesRegularExpression('/^class ModuleAdmin\b/m', $src);
        $this->assertStringContainsString('AdminResponse', $src);
    }

    public function testAdminConfigReturnsDiscoveryMetadata(): void
    {
        $meta = require self::SKEL . '/Admin/admin-config.php';
        $this->assertIsArray($meta);
        foreach (['label', 'icon', 'description', 'permissions'] as $key) {
            $this->assertArrayHasKey($key, $meta);
        }
        foreach (['order', 'enabled'] as $key) {
            $this->assertArrayNotHasKey($key, $meta, 'app/modules.ini decides this');
        }
    }

    public function testSubmoduleUsesSemicolonNamespaceForm(): void
    {
        $src = file_get_contents(self::SKEL . '/submodule/Example/Example.php');
        // mkmod needs a rule for this form; the "Zero\Core\Module\Module\" rule
        // does not match a namespace that terminates in a semicolon.
        $this->assertMatchesRegularExpression(
            '/^namespace Zero\\\\Core\\\\Module\\\\Module;/m',
            $src
        );
    }

    public function testHelperIsNotAutoloadedAndSaysSo(): void
    {
        $path = self::SKEL . '/helper/ExampleHelper.php';
        $this->assertFileExists($path);
        // helper/ is not in Response::$paths, so nothing autoloads it.
        $this->assertStringContainsString('require', file_get_contents($path));
    }

    public function testSlugifyProducesAssetSafeNames(): void
    {
        require_once self::SKEL . '/helper/ExampleHelper.php';
        $slugify = [\Zero\Core\Module\Module\Helper\ExampleHelper::class, 'slugify'];

        // The four real module-name shapes in this codebase.
        $this->assertSame('hello-world', $slugify('HelloWorld'));
        $this->assertSame('module', $slugify('Module'));
        $this->assertSame('api-demo', $slugify('ApiDemo'));
        $this->assertSame('s', $slugify('S'));

        // Consecutive capitals. mkmod must reproduce these byte for byte in Bash.
        // A naive sed -E 's/(.)([A-Z])/\1-\2/g' yields a-btest / a-pi-demo,
        // because sed's global match is non-overlapping and eats the char
        // before each capital. Insert-before-every-capital-then-strip-leading
        // is the equivalent of the PHP lookbehind.
        $this->assertSame('a-b-test', $slugify('ABTest'));
        $this->assertSame('a-p-i-demo', $slugify('APIDemo'));
    }

    public function testReadmeDistinguishesFrameworkAwareDirs(): void
    {
        $readme = file_get_contents(self::SKEL . '/README');

        $frameworkHeading  = strpos($readme, 'Framework-aware');
        $conventionHeading = strpos($readme, 'Convention only');
        $this->assertNotFalse($frameworkHeading, 'README lost its Framework-aware section');
        $this->assertNotFalse($conventionHeading, 'README lost its Convention only section');
        $this->assertLessThan($conventionHeading, $frameworkHeading);

        // Directories core/ actually reads must sit in the framework-aware section.
        foreach (['view/', 'frame/', 'model/', 'assets/component/', 'aux/', 'submodule/', 'Admin/'] as $dir) {
            $at = strpos($readme, $dir);
            $this->assertNotFalse($at, "README no longer documents $dir");
            $this->assertGreaterThan($frameworkHeading, $at, "$dir must be documented as framework-aware");
            $this->assertLessThan($conventionHeading, $at, "$dir must be documented as framework-aware");
        }

        // Directories nothing in core/ reads must sit in the convention-only section.
        foreach (['helper/', 'docs/', 'template/', 'data/'] as $dir) {
            $at = strpos($readme, $dir);
            $this->assertNotFalse($at, "README no longer documents $dir");
            $this->assertGreaterThan($conventionHeading, $at, "$dir must be documented as convention-only");
        }

        $this->assertStringContainsString('mkmod', $readme);
    }

    public function testConventionalDirsExist(): void
    {
        foreach (['docs', 'template', 'data'] as $dir) {
            $this->assertDirectoryExists(self::SKEL . '/' . $dir);
        }
    }

    public function testSmokeTestDerivesTheModuleNameFromItsLocation(): void
    {
        // mkmod --tests copies ONLY this file. It must not hardcode "Module",
        // or every generated module ships a failing test.
        $src = file_get_contents(self::SKEL . '/tests/ModuleSmokeTest.php');
        $this->assertStringContainsString('basename($moduleDir)', $src);
        $this->assertStringNotContainsString("/Module.php", $src);
    }

    public function testSkeletonOnlyMarkersAreBalanced(): void
    {
        // An unbalanced marker makes mkmod's rule 0 delete to end-of-file.
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(self::SKEL, \FilesystemIterator::SKIP_DOTS)
        );
        $checked = 0;
        foreach ($files as $file) {
            if (!$file->isFile()) { continue; }
            $src = file_get_contents($file->getPathname());
            $starts = substr_count($src, 'MKMOD:SKELETON-ONLY-START');
            $ends   = substr_count($src, 'MKMOD:SKELETON-ONLY-END');
            $this->assertSame($starts, $ends, $file->getPathname() . ' has unbalanced skeleton-only markers');
            $checked++;
        }
        $this->assertGreaterThan(0, $checked);
    }

    public function testSkeletonSpecificProseIsMarkerWrapped(): void
    {
        // These phrases are true of core/modules/Module/ and false of any copy.
        // Each must live inside a MKMOD:SKELETON-ONLY block so rule 0 strips it.
        $phrases = [
            'the reference module',
            'mkmod copies this file',
        ];
        foreach ($phrases as $phrase) {
            foreach ([self::SKEL . '/Module.php', self::SKEL . '/view/index.php'] as $path) {
                $src = file_get_contents($path);
                if (!str_contains($src, $phrase)) { continue; }
                $before = substr($src, 0, strpos($src, $phrase));
                $this->assertGreaterThan(
                    substr_count($before, 'MKMOD:SKELETON-ONLY-END'),
                    substr_count($before, 'MKMOD:SKELETON-ONLY-START'),
                    "\"$phrase\" in $path is not inside a skeleton-only block"
                );
            }
        }
    }
}
