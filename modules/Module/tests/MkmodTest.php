<?php
namespace Zero\Core\Module\Module\Tests;

use Zero\Tests\Core\ZeroTestCase;

class MkmodTest extends ZeroTestCase
{
    private const MKMOD = __DIR__ . '/../mkmod';

    /** @return array{0:int,1:string} exit code and combined output */
    private function mkmod(string $args = ''): array
    {
        $cmd = escapeshellcmd(self::MKMOD) . ' ' . $args . ' 2>&1';
        exec($cmd, $lines, $code);
        return [$code, implode("\n", $lines)];
    }

    public function testScriptIsExecutable(): void
    {
        $this->assertFileExists(self::MKMOD);
        $this->assertTrue(is_executable(self::MKMOD), 'chmod +x core/modules/Module/mkmod');
    }

    public function testHelpExitsZeroAndListsFlags(): void
    {
        [$code, $out] = $this->mkmod('--help');
        $this->assertSame(0, $code);
        foreach (['--model', '--admin', '--all', '--dry-run', '--selftest'] as $flag) {
            $this->assertStringContainsString($flag, $out);
        }
    }

    public function testMissingNameIsUsageError(): void
    {
        [$code, $out] = $this->mkmod('--dry-run');
        $this->assertSame(2, $code);
        $this->assertStringContainsStringIgnoringCase('usage', $out);
    }

    public function testNonStudlyCapsNameIsRejected(): void
    {
        foreach (['weather', 'my-module', '9Lives', 'has space'] as $bad) {
            [$code, $out] = $this->mkmod(escapeshellarg($bad) . ' --dry-run');
            $this->assertSame(2, $code, "'$bad' should be rejected");
            $this->assertStringContainsStringIgnoringCase('StudlyCaps', $out);
        }
    }

    public function testRefusesToClobberTheSkeletonItself(): void
    {
        [$code, $out] = $this->mkmod('Module --dry-run');
        $this->assertSame(2, $code);
        $this->assertStringContainsStringIgnoringCase('skeleton', $out);
    }

    public function testDryRunPrintsTheSiteModulesFolderAsTheDefaultDestination(): void
    {
        // core/modules/Module/tests/ -> the site is four levels up
        $site = dirname(__DIR__, 4);
        [$code, $out] = $this->mkmod('Zerotestmod --dry-run');
        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString("Destination: $site/modules/Zerotestmod", $out);
    }

    public function testDryRunPrintsAnExplicitDestination(): void
    {
        [$code, $out] = $this->mkmod('Zerotestmod --dry-run --dest /tmp/zerotest-mkmod-nowhere');
        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('Destination: /tmp/zerotest-mkmod-nowhere/Zerotestmod', $out);
    }

    public function testDestWritesIntoTheGivenFolderOnly(): void
    {
        $dest = $this->generate('Zerotestmod');
        try {
            $this->assertFileExists("$dest/Zerotestmod/Zerotestmod.php");
            $this->assertDirectoryDoesNotExist(dirname(__DIR__, 4) . '/modules/Zerotestmod');
            $this->assertDirectoryDoesNotExist(dirname(__DIR__, 2) . '/Zerotestmod');
        } finally {
            $this->rmrf($dest);
        }
    }

    public function testNothingIsWrittenInsideCoreByDefault(): void
    {
        [$code, $out] = $this->mkmod('Zerotestmod --dry-run');
        $this->assertSame(0, $code, $out);
        $this->assertStringNotContainsString('/core/modules/Zerotestmod', $out);
        $this->assertDirectoryDoesNotExist(dirname(__DIR__, 2) . '/Zerotestmod');
    }

    public function testACopyOutsideTheCoreLayoutHasNoDefaultDestination(): void
    {
        $copy = sys_get_temp_dir() . '/mkmod-copy-' . getmypid();
        mkdir($copy);
        try {
            copy(self::MKMOD, "$copy/mkmod");
            chmod("$copy/mkmod", 0755);
            exec(escapeshellarg("$copy/mkmod") . ' Zerotestmod --dry-run --skel ' . escapeshellarg(dirname(__DIR__)) . ' 2>&1', $lines, $code);
            $this->assertSame(2, $code, implode("\n", $lines));
            $this->assertStringContainsString('--dest', implode("\n", $lines));
        } finally {
            $this->rmrf($copy);
        }
    }

    public function testDestInsideCoreIsHonouredOnlyWhenGivenExplicitly(): void
    {
        $core = dirname(__DIR__, 3);
        [$code, $out] = $this->mkmod('Zerotestmod --dry-run --dest ' . escapeshellarg($core . '/modules'));
        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString("Destination: $core/modules/Zerotestmod", $out);
    }

    public function testANewModuleMayNotReuseABundledName(): void
    {
        $dest = sys_get_temp_dir() . '/mkmod-clash-' . getmypid();
        mkdir($dest);
        try {
            foreach (['Auth', 'HelloWorld', 'Push'] as $name) {
                [$code, $out] = $this->mkmod($name . ' --dry-run --dest ' . escapeshellarg($dest));
                $this->assertSame(1, $code, "$name: $out");
                $this->assertStringContainsStringIgnoringCase('bundled', $out);
            }
        } finally {
            rmdir($dest);
        }
    }

    public function testRefusesExistingModuleWithoutForce(): void
    {
        $dest = sys_get_temp_dir() . '/mkmod-exists-' . getmypid();
        mkdir($dest . '/Zerotestmod', 0777, true);
        try {
            [$code, $out] = $this->mkmod('Zerotestmod --dry-run --dest ' . escapeshellarg($dest));
            $this->assertSame(1, $code, $out);
            $this->assertStringContainsString('use --force', $out);
            $this->assertStringContainsStringIgnoringCase('already exists', $out);
        } finally {
            $this->rmrf($dest);
        }
    }

    public function testBundledFolderGivenAsDestIsRefusedEvenWithForce(): void
    {
        $bundled = dirname(__DIR__, 2);
        foreach ([$bundled, $bundled . '/'] as $dest) {
            [$code, $out] = $this->mkmod('Auth --dry-run --force --dest ' . escapeshellarg($dest));
            $this->assertSame(1, $code, "$dest: $out");
            $this->assertStringContainsStringIgnoringCase('bundled', $out);
        }
    }

    /**
     * A site whose core/ is a symlink to a shared core: a temp site with
     * modules/ and core -> the real core.
     * @return string the site folder
     */
    private function symlinkedSite(): string
    {
        $site = sys_get_temp_dir() . '/mkmod-link-' . getmypid();
        mkdir($site . '/modules', 0777, true);
        symlink(dirname(__DIR__, 3), $site . '/core');
        return $site;
    }

    public function testDefaultDestinationIsTheSiteThatCoreWasReachedThrough(): void
    {
        $site = $this->symlinkedSite();
        try {
            exec(escapeshellarg("$site/core/modules/Module/mkmod") . ' Zerotestmod --dry-run 2>&1', $lines, $code);
            $out = implode("\n", $lines);
            $this->assertSame(0, $code, $out);
            $this->assertStringContainsString("Destination: $site/modules/Zerotestmod", $out);

            $lines = [];
            exec('cd ' . escapeshellarg("$site/core/modules/Module") . ' && ./mkmod Zerotestmod --dry-run 2>&1', $lines, $code);
            $out = implode("\n", $lines);
            $this->assertSame(0, $code, $out);
            $this->assertStringContainsString("Destination: $site/modules/Zerotestmod", $out);
        } finally {
            $this->rmrf($site);
        }
    }

    public function testSelftestPassesThroughASymlinkedCore(): void
    {
        $site = $this->symlinkedSite();
        try {
            exec(escapeshellarg("$site/core/modules/Module/mkmod") . ' --selftest 2>&1', $lines, $code);
            $this->assertSame(0, $code, implode("\n", $lines));
        } finally {
            $this->rmrf($site);
        }
    }

    public function testDryRunWritesNothing(): void
    {
        $dest = sys_get_temp_dir() . '/mkmod-dry-' . getmypid();
        mkdir($dest);
        try {
            [$code, $out] = $this->mkmod('Weather --all --dry-run --dest ' . escapeshellarg($dest));
            $this->assertSame(0, $code);
            $this->assertStringContainsString('Weather.php', $out);
            $this->assertSame(['.', '..'], scandir($dest), 'dry-run must not write');
        } finally {
            rmdir($dest);
        }
    }

    public function testDryRunShowsOnlyRequestedDirsByDefault(): void
    {
        $dest = sys_get_temp_dir() . '/mkmod-min-' . getmypid();
        mkdir($dest);
        try {
            [$code, $out] = $this->mkmod('Weather --dry-run --dest ' . escapeshellarg($dest));
            $this->assertSame(0, $code);
            $this->assertStringContainsString('Weather.php', $out);
            $this->assertStringContainsString('assets/css/weather.css', $out);
            $this->assertStringNotContainsString('Admin/', $out);
            $this->assertStringNotContainsString('model/', $out);
        } finally {
            rmdir($dest);
        }
    }

    public function testKebabConversionMatchesTheHelper(): void
    {
        $dest = sys_get_temp_dir() . '/mkmod-kebab-' . getmypid();
        mkdir($dest);
        try {
            [, $out] = $this->mkmod('HelloThere --dry-run --dest ' . escapeshellarg($dest));
            $this->assertStringContainsString('assets/css/hello-there.css', $out);
        } finally {
            rmdir($dest);
        }
    }

    private function generate(string $args): string
    {
        $dest = sys_get_temp_dir() . '/mkmod-gen-' . getmypid() . '-' . substr(md5($args), 0, 8);
        @mkdir($dest, 0777, true);
        [$code, $out] = $this->mkmod($args . ' --dest ' . escapeshellarg($dest));
        $this->assertSame(0, $code, "mkmod failed:\n$out");
        return $dest;
    }

    private function rmrf(string $dir): void
    {
        exec('rm -rf ' . escapeshellarg($dir));
    }

    public function testControllerIsRewrittenWithoutCorruptingTheParent(): void
    {
        $dest = $this->generate('Weather');
        try {
            $src = file_get_contents("$dest/Weather/Weather.php");

            $this->assertStringContainsString('class Weather extends \Zero\Core\Module', $src);
            $this->assertStringContainsString('namespace Zero\Module;', $src);
            // The two things a naive s/Module/Weather/g would have destroyed:
            $this->assertStringNotContainsString('\Zero\Core\Weather', $src);
            $this->assertStringNotContainsString('namespace Zero\Weather;', $src);
        } finally {
            $this->rmrf($dest);
        }
    }

    public function testAssetsAreRenamedAndReferencesFollow(): void
    {
        $dest = $this->generate('HelloThere --component');
        try {
            $this->assertFileExists("$dest/HelloThere/assets/css/hello-there.css");
            $this->assertFileExists("$dest/HelloThere/assets/js/hello-there.js");
            $this->assertFileExists("$dest/HelloThere/assets/component/hello-there-panel.html");
            $this->assertFileDoesNotExist("$dest/HelloThere/assets/css/module.css");

            $panel = file_get_contents("$dest/HelloThere/assets/component/hello-there-panel.html");
            $this->assertStringContainsString('<template id="hello-there-panel">', $panel);
            $this->assertStringContainsString('class HelloTherePanel extends ShadowComponent', $panel);
            $this->assertStringContainsString('customElements.define("hello-there-panel", HelloTherePanel)', $panel);
        } finally {
            $this->rmrf($dest);
        }
    }

    public function testModelNamespaceAndClassAreRewritten(): void
    {
        $dest = $this->generate('Weather --model');
        try {
            $src = file_get_contents("$dest/Weather/model/WeatherModel.php");
            $this->assertStringContainsString('namespace Zero\Module\Weather\Model;', $src);
            $this->assertStringContainsString('class WeatherModel', $src);
        } finally {
            $this->rmrf($dest);
        }
    }

    public function testAdminIsRewritten(): void
    {
        $dest = $this->generate('Weather --admin');
        try {
            $src = file_get_contents("$dest/Weather/Admin/WeatherAdmin.php");
            $this->assertStringContainsString('namespace Zero\Module\Weather\Admin;', $src);
            $this->assertStringContainsString('class WeatherAdmin extends AdminResponse', $src);

            $meta = require "$dest/Weather/Admin/admin-config.php";
            $this->assertArrayNotHasKey('enabled', $meta, 'whether a module is on is app/modules.ini');
            $this->assertArrayNotHasKey('order', $meta);
            $this->assertSame('Manage Weather', $meta['label']);

            $this->assertFileExists("$dest/Weather/Admin/assets/css/weatheradmin.css");
        } finally {
            $this->rmrf($dest);
        }
    }

    public function testSubmoduleNamespaceUsesTheSemicolonForm(): void
    {
        $dest = $this->generate('Weather --submodule=Forecast');
        try {
            $src = file_get_contents("$dest/Weather/submodule/Forecast/Forecast.php");
            $this->assertStringContainsString('namespace Zero\Module\Weather;', $src);
            $this->assertStringContainsString('class Forecast extends \Zero\Core\Module', $src);
        } finally {
            $this->rmrf($dest);
        }
    }

    public function testIniConstantPrefixIsRewrittenButPhpIsNot(): void
    {
        $dest = $this->generate('HelloThere --config');
        try {
            $ini = parse_ini_file("$dest/HelloThere/config.ini");
            $this->assertArrayHasKey('HELLO_THERE_EXAMPLE_CONSTANT', $ini);
            // The committed copies in config-tmpl/ carry the same keys.
            $tmpl = parse_ini_file("$dest/HelloThere/config-tmpl/config.ini");
            $this->assertArrayHasKey('HELLO_THERE_EXAMPLE_CONSTANT', $tmpl);
            $this->assertFileExists("$dest/HelloThere/config-tmpl/example.ini");
        } finally {
            $this->rmrf($dest);
        }
    }

    public function testConfigEmitsStringsWithThePrefixRewrittenAndNotIgnored(): void
    {
        $dest = $this->generate('HelloThere --config');
        try {
            $file = "$dest/HelloThere/strings/example.ini";
            $this->assertFileExists($file);
            $ini = parse_ini_file($file, false, INI_SCANNER_RAW);
            $this->assertSame(['HELLO_THERE_EXAMPLE_STRING'], array_keys($ini));
            $this->assertStringNotContainsString('MODULE_', file_get_contents($file));
            // Wording is committed: the module's .gitignore does not hide strings/.
            $this->assertStringNotContainsString('strings', file_get_contents("$dest/HelloThere/.gitignore"));
        } finally {
            $this->rmrf($dest);
        }
    }

    public function testStringsAreNotEmittedWithoutConfig(): void
    {
        $dest = $this->generate('Weather');
        try {
            $this->assertDirectoryDoesNotExist("$dest/Weather/strings");
        } finally {
            $this->rmrf($dest);
        }
    }

    public function testGeneratedSmokeTestIsRenamedAndSelfLocating(): void
    {
        $dest = $this->generate('Weather --tests');
        try {
            $src = file_get_contents("$dest/Weather/tests/WeatherSmokeTest.php");
            $this->assertStringContainsString('namespace Zero\Module\Weather\Tests;', $src);
            $this->assertStringContainsString('class WeatherSmokeTest', $src);
            // It must NOT have copied the skeleton's own conventions tests.
            $this->assertFileDoesNotExist("$dest/Weather/tests/WeatherTest.php");
            $this->assertFileDoesNotExist("$dest/Weather/tests/MkmodTest.php");
        } finally {
            $this->rmrf($dest);
        }
    }

    public function testMkmodIsNotCopiedIntoGeneratedModules(): void
    {
        $dest = $this->generate('Weather --all');
        try {
            $this->assertFileDoesNotExist("$dest/Weather/mkmod");
            $this->assertFileDoesNotExist("$dest/Weather/README");
        } finally {
            $this->rmrf($dest);
        }
    }

    public function testGitignoreIsThePlainBareModuleFile(): void
    {
        $dest = $this->generate('Weather');
        try {
            $this->assertFileExists("$dest/Weather/.gitignore");
            $rules = array_values(array_filter(
                array_map('trim', file("$dest/Weather/.gitignore")),
                fn(string $line): bool => $line !== '' && $line[0] !== '#'
            ));
            // The skeleton's own file also un-ignores its config examples; those
            // negations are skeleton-only and must not reach a new module.
            $this->assertSame(['*swp', 'config/', 'config.ini', '!**/config-tmpl/config.ini'], $rules);
            $this->assertStringContainsString('!/config.ini', file_get_contents(dirname(__DIR__) . '/.gitignore'));
        } finally {
            $this->rmrf($dest);
        }
    }

    public function testUserVisibleStringsAreRewritten(): void
    {
        $dest = $this->generate('Weather --frame');
        try {
            $view = file_get_contents("$dest/Weather/view/index.php");
            $this->assertStringContainsString('<h1>Weather</h1>', $view);
            $this->assertStringNotContainsString('Module Skeleton', $view);
            // Links in the view must point at the new module, not /module/.
            $this->assertStringContainsString('/weather/ping/hello', $view);

            $head = file_get_contents("$dest/Weather/frame/head.php");
            $this->assertStringContainsString("?? 'Weather'", $head);
        } finally {
            $this->rmrf($dest);
        }
    }

    public function testGeneratedPhpIsSyntacticallyValid(): void
    {
        $dest = $this->generate('Weather --all');
        try {
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator("$dest/Weather", \FilesystemIterator::SKIP_DOTS)
            );
            $checked = 0;
            foreach ($files as $file) {
                if ($file->getExtension() !== 'php') { continue; }
                $out = [];
                exec('php -l ' . escapeshellarg($file->getPathname()) . ' 2>&1', $out, $code);
                $this->assertSame(0, $code, $file->getPathname() . ":\n" . implode("\n", $out));
                $checked++;
            }
            $this->assertGreaterThan(5, $checked);
        } finally {
            $this->rmrf($dest);
        }
    }

    public function testForceOverwritesExistingTarget(): void
    {
        $dest = $this->generate('Weather');
        try {
            [$code] = $this->mkmod('Weather --force --dest ' . escapeshellarg($dest));
            $this->assertSame(0, $code);
        } finally {
            $this->rmrf($dest);
        }
    }

    public function testGeneratedModuleCarriesNoSkeletonProse(): void
    {
        $dest = $this->generate('Weather --all --submodule=Forecast');
        try {
            $forbidden = [
                'MKMOD:SKELETON-ONLY',
                'the reference module',
                'The skeleton module',
                'mkmod copies this file',
                'modules/Module/',
                'loadAdminStyles',
                'skeleton module script',
                'Example Submodule',
                'submodule/Example/assets/css/example.css',
                'the parent module has no',
            ];
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator("$dest/Weather", \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($files as $file) {
                if (!$file->isFile()) { continue; }
                $src = file_get_contents($file->getPathname());
                foreach ($forbidden as $needle) {
                    $this->assertStringNotContainsString($needle, $src, $file->getPathname());
                }
            }

            // The dead-link case: no submodule link unless it matches the real submodule.
            $view = file_get_contents("$dest/Weather/view/index.php");
            $this->assertStringNotContainsString('/weather/example', $view);

            $subview = file_get_contents("$dest/Weather/submodule/Forecast/view/index.php");
            $this->assertStringNotContainsString('/weather/example', $subview);
            $this->assertStringNotContainsString('Example', $subview);

            // The submodule CONTROLLER's docblock is copied too. Left ungeneralised
            // it told every generated submodule it was reached via example() at
            // /parent/example, from submodule/Example/ — all false.
            $subctl = file_get_contents("$dest/Weather/submodule/Forecast/Forecast.php");
            $this->assertStringNotContainsString('Example', $subctl);
            $this->assertStringNotContainsString('example()', $subctl);
            $this->assertStringNotContainsString('/weather/example', $subctl);
        } finally {
            $this->rmrf($dest);
        }
    }

    public function testSelftestPasses(): void
    {
        [$code, $out] = $this->mkmod('--selftest');
        $this->assertSame(0, $code, "selftest failed:\n$out");
        $this->assertStringContainsStringIgnoringCase('ok', $out);
    }

    public function testSelftestLeavesNoTempDirectories(): void
    {
        foreach (glob(sys_get_temp_dir() . '/mkmod-selftest.*') ?: [] as $stale) {
            exec('rm -rf ' . escapeshellarg($stale));
        }

        [$code, $out] = $this->mkmod('--selftest');
        $this->assertSame(0, $code, "selftest failed:\n$out");

        $left = glob(sys_get_temp_dir() . '/mkmod-selftest.*') ?: [];
        $this->assertSame([], $left, 'selftest left temp directories behind: ' . implode(', ', $left));
    }

    public function testSelftestCatchesSkeletonDrift(): void
    {
        // Simulate someone reformatting the anchored class line and confirm
        // selftest notices.
        //
        // The corruption is applied to a COPY of the skeleton, never to the real
        // one: this working tree is the live site, and a crashed test run would
        // otherwise leave https://unisolu.com/module/ broken. That is what the
        // --skel flag is for. (On any site the same applies to its own /module/ URL.)
        $skelCopy = sys_get_temp_dir() . '/mkmod-skel-' . getmypid();
        $this->rmrf($skelCopy);
        try {
            exec('cp -R ' . escapeshellarg(dirname(__DIR__)) . ' ' . escapeshellarg($skelCopy), $_, $cp);
            $this->assertSame(0, $cp, 'failed to copy the skeleton');

            $controller = "$skelCopy/Module.php";
            $original = file_get_contents($controller);
            file_put_contents(
                $controller,
                str_replace(
                    'class Module extends \Zero\Core\Module',
                    'class  Module extends \Zero\Core\Module',   // two spaces breaks the ^class Module\b anchor
                    $original
                )
            );
            $this->assertNotSame($original, file_get_contents($controller), 'drift was not applied');

            [$code, $out] = $this->mkmod('--selftest --skel ' . escapeshellarg($skelCopy));
            $this->assertSame(1, $code, "selftest should fail on drift, got:\n$out");
            $this->assertStringContainsString('survived substitution', $out);

            // The real skeleton must be untouched.
            $this->assertStringContainsString(
                'class Module extends \Zero\Core\Module',
                file_get_contents(dirname(__DIR__) . '/Module.php')
            );
        } finally {
            $this->rmrf($skelCopy);
        }
    }

    public function testSelftestAcceptsAnIntactSkeletonCopy(): void
    {
        // Control for the drift test: an unmodified copy must still pass, proving
        // the previous test failed because of the drift and not because --skel
        // itself broke path resolution.
        $skelCopy = sys_get_temp_dir() . '/mkmod-skel-ok-' . getmypid();
        $this->rmrf($skelCopy);
        try {
            exec('cp -R ' . escapeshellarg(dirname(__DIR__)) . ' ' . escapeshellarg($skelCopy));

            [$code, $out] = $this->mkmod('--selftest --skel ' . escapeshellarg($skelCopy));
            $this->assertSame(0, $code, "selftest on an intact copy should pass:\n$out");
        } finally {
            $this->rmrf($skelCopy);
        }
    }

    public function testManifestOnlyEmitsExtensionsTheEngineRewrites(): void
    {
        // substitute()'s catch-all branch copies verbatim. If a new manifest entry
        // introduces an extension the engine does not rewrite, identifiers would
        // silently survive into generated modules. Force that to be a decision.
        $dest = sys_get_temp_dir() . '/mkmod-ext-' . getmypid();
        try {
            @mkdir($dest, 0777, true);
            [$code, $out] = $this->mkmod('Weather --all --submodule=Forecast --dry-run --dest ' . escapeshellarg($dest));
            $this->assertSame(0, $code, $out);

            $handled = ['php', 'css', 'js', 'html', 'ini', 'gitignore'];
            foreach (explode("\n", $out) as $line) {
                $line = trim($line);
                if ($line === '' || !str_contains($line, '.')) { continue; }
                if (!preg_match('/\.([a-z0-9]+)$/i', $line, $m)) { continue; }
                $this->assertContains(
                    strtolower($m[1]),
                    $handled,
                    "manifest emits .{$m[1]} which substitute() does not rewrite — add a branch or confirm verbatim copy is intended"
                );
            }
        } finally {
            exec('rm -rf ' . escapeshellarg($dest));
        }
    }
}
