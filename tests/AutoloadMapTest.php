<?php

namespace Zero\Tests\Core;

use PHPUnit\Framework\TestCase;
use Zero\Core\Application;
use Zero\Core\Modules;

/**
 * Application::classFile() maps a class name to the file that declares it.
 *
 * The first test walks every class file the framework ships and asks the map
 * where each class lives; a class the map cannot place is a fatal on a page
 * that autoloads it, so every miss is listed at once.
 */
class AutoloadMapTest extends TestCase
{
    use AutoloadWalkTrait;

    /** Folders that hold no autoloadable classes, skipped at any depth inside a module. */
    private const SKIP_DIRS = [
        'tests', 'node_modules', 'data', 'storage', 'assets', 'view', 'frame', 'template', 'docs',
    ];

    /**
     * Classes the map deliberately does not place, with the reason. Each is
     * loaded by a require (or never loaded), so nothing autoloads it.
     */
    private const NOT_AUTOLOADED = [
        'Zero\\Core\\TrackedStatement' => 'second class in core/Database.php, used only once Database is loaded',
    ];

    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2) . '/';
        Modules::configure([$this->root . 'modules/', $this->root . 'core/modules/'], $this->root . 'app/modules.ini');
    }

    protected function tearDown(): void
    {
        zeroTestConfigureModules();
    }

    public function testEveryClassFileResolves(): void
    {
        $misses = [];
        $checked = 0;

        foreach ($this->classFiles() as $file) {
            foreach ($this->declaredClasses($file) as $fqcn) {
                if (isset(self::NOT_AUTOLOADED[$fqcn])) {
                    continue;
                }
                $checked++;
                $found = Application::classFile($fqcn);
                if ($found === null || realpath($found) !== realpath($file)) {
                    $misses[] = $fqcn . ' (' . substr($file, strlen($this->root)) . ') => ' . ($found ?? 'null');
                }
            }
        }

        $this->assertGreaterThan(50, $checked, 'the walk found almost no classes');
        $this->assertSame([], $misses, count($misses) . " of $checked classes do not resolve:\n" . implode("\n", $misses));
    }

    public function testLibrariesResolveWhereTheyLive(): void
    {
        $core = [
            'Zero\\Lib\\Mail\\Mail'             => 'core/lib/Mail/Mail.php',
            'Zero\\Lib\\Http\\Client'           => 'core/lib/Http/Client.php',
            'Zero\\Lib\\CloudFlare\\CloudFlare' => 'core/lib/CloudFlare/CloudFlare.php',
            'Zero\\Core\\Module\\Push\\Lib\\PushHelper' => 'core/modules/Push/lib/PushHelper.php',
        ];
        foreach ($core as $class => $path) {
            $found = Application::classFile($class);
            $this->assertNotNull($found, $class);
            $this->assertSame(realpath($this->root . $path), realpath($found), $class);
        }
    }

    public function testMovedLibraryNamesNoLongerResolve(): void
    {
        foreach (['Zero\\Lib\\Api\\ServiceClient', 'Zero\\Lib\\Api\\ServiceResponse', 'Zero\\Lib\\Ollama\\OllamaClient', 'Zero\\Lib\\ComfyUI\\ComfyUIClient'] as $class) {
            $this->assertNull(Application::classFile($class), $class);
        }
        $this->assertDirectoryDoesNotExist($this->root . 'lib');
    }

    public function testNoFileNamesTheMovedLibraries(): void
    {
        $needles = ['Zero\\Lib\\Api', 'Zero\\Lib\\Ollama', 'Zero\\Lib\\ComfyUI', 'lib/Api/', 'lib/Ollama/', 'lib/ComfyUI/'];
        $hits = [];
        $skip = ['vendor', '.git', 'node_modules', 'superpowers', 'storage'];
        $self = realpath(__FILE__);
        $walk = function (string $dir) use (&$walk, &$hits, $needles, $skip, $self): void {
            foreach (scandir($dir) ?: [] as $name) {
                if ($name[0] === '.' || in_array($name, $skip, true) || is_link($dir . $name)) {
                    continue;
                }
                $path = $dir . $name;
                if (is_dir($path)) {
                    $walk($path . '/');
                    continue;
                }
                if (realpath($path) === $self || $name === 'DEPRECATING_CHANGES.md' || filesize($path) > 2000000) {
                    continue;
                }
                $text = (string) file_get_contents($path);
                if (str_contains($text, "\0")) {
                    continue;
                }
                foreach ($needles as $needle) {
                    if (str_contains($text, $needle)) {
                        $hits[] = substr($path, strlen($this->root)) . ' names ' . $needle;
                    }
                }
            }
        };
        foreach (['core', 'modules', 'app', 'docs', 'swoole', 'aux', 'plugin', 'entity'] as $top) {
            if (is_dir($this->root . $top)) {
                $walk($this->root . $top . '/');
            }
        }
        foreach (glob($this->root . '*.{php,md,json,xml}', GLOB_BRACE) ?: [] as $file) {
            if (basename($file) === 'DEPRECATING_CHANGES.md') {
                continue;
            }
            $text = (string) file_get_contents($file);
            foreach ($needles as $needle) {
                if (str_contains($text, $needle)) {
                    $hits[] = basename($file) . ' names ' . $needle;
                }
            }
        }
        $this->assertSame([], $hits);
    }

    public function testUnknownClassIsNull(): void
    {
        $this->assertNull(Application::classFile('Zero\Module\Nope\Thing'));
        $this->assertNull(Application::classFile('Other\Thing'));
    }

    public function testSubmoduleClass(): void
    {
        $this->assertSame(
            realpath($this->root . 'core/modules/Admin/submodule/Firewall/Firewall.php'),
            realpath((string) Application::classFile('Zero\Core\Module\Admin\Firewall'))
        );
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('malformedNames')]
    public function testMalformedClassNameIsNull(string $class): void
    {
        $this->assertNull(Application::classFile($class));
    }

    /** @return array<string,array{string}> */
    public static function malformedNames(): array
    {
        return [
            'dotdot to core'      => ['Zero\\Module\\..\\..\\core\\Session'],
            'dotdot inside'       => ['Zero\\Core\\Module\\Auth\\..\\Admin\\Admin'],
            'slashes'             => ['Zero\\Core\\Module\\Auth/../x'],
            'null byte'           => ["Zero\\Core\\Module\\Auth\0\\Auth"],
            'dot'                 => ['Zero\\Core\\Module\\Auth.php'],
            'empty segment'       => ['Zero\\Module\\\\Auth'],
            'trailing newline'    => ["Zero\\Core\\Module\\Auth\n"],
            'long segment'        => ['Zero\\Core\\Module\\Auth\\' . str_repeat('A', 5000)],
            'dotdot in core rule' => ['Zero\\Core\\..\\..\\x'],
        ];
    }

    public function testASiteModuleOverridesAndMayExtendCoresOfTheSameName(): void
    {
        $tmp = sys_get_temp_dir() . '/zerotest-override-' . bin2hex(random_bytes(4));
        try {
            foreach (['site' => "namespace Zero\\Module;\nclass Zerotestover extends \\Zero\\Core\\Module\\Zerotestover { public function whose() { return 'site over ' . parent::whose(); } }",
                      'core' => "namespace Zero\\Core\\Module;\nclass Zerotestover { public function whose() { return 'core'; } }"] as $root => $code) {
                mkdir("$tmp/$root/Zerotestover/model", 0777, true);
                file_put_contents("$tmp/$root/Zerotestover/Zerotestover.php", "<?php\n$code\n");
            }
            file_put_contents("$tmp/core/Zerotestover/model/Thing.php", "<?php\nnamespace Zero\\Core\\Module\\Zerotestover\\Model;\nclass Thing {}\n");
            mkdir("$tmp/core/Zerotestcore", 0777, true);
            file_put_contents("$tmp/core/Zerotestcore/Zerotestcore.php", "<?php\nnamespace Zero\\Core\\Module;\nclass Zerotestcore {}\n");
            Modules::configure(["$tmp/site/", "$tmp/core/"], null, "$tmp/core/");
            Modules::assertNoClash();

            // The URL reaches the site's class; core's stays loadable under its own name
            $this->assertSame("$tmp/site/Zerotestover/Zerotestover.php", Application::controllerFile('zerotestover'));
            $this->assertSame("$tmp/site/Zerotestover/Zerotestover.php", Application::classFile('Zero\\Module\\Zerotestover'));
            $this->assertSame("$tmp/core/Zerotestover/Zerotestover.php", Application::classFile('Zero\\Core\\Module\\Zerotestover'));
            $this->assertSame("$tmp/core/Zerotestover/model/Thing.php", Application::classFile('Zero\\Core\\Module\\Zerotestover\\Model\\Thing'));
            $this->assertNull(Application::classFile('Zero\\Module\\Zerotestover\\Model\\Thing'), 'the site module has no such class');
            $this->assertSame('site over core', (new \Zero\Module\Zerotestover())->whose());

            // A module only core has is a class of Zero\Core\Module\ and of nothing else
            $this->assertSame("$tmp/core/Zerotestcore/Zerotestcore.php", Application::controllerFile('Zerotestcore'));
            $this->assertNull(Application::classFile('Zero\\Module\\Zerotestcore'));

            // Zero\Core\Module itself is still core/Module.php
            $this->assertSame($this->root . 'core/Module.php', Application::classFile('Zero\\Core\\Module'));
        } finally {
            exec('rm -rf ' . escapeshellarg($tmp));
        }
    }

    public function testModuleNameCheck(): void
    {
        foreach (['..', 'Auth\\OAuth\\Token', 'auth/../admin', '', 'Auth.php', "Auth\n", 'api-config'] as $bad) {
            $this->assertFalse(Application::isModuleName($bad), var_export($bad, true));
        }
        foreach (['Auth', 'ApiConfig'] as $good) {
            $this->assertTrue(Application::isModuleName($good), $good);
        }
        $this->assertNull(Application::controllerFile('..'));
        $this->assertNull(Application::controllerFile('Auth\\OAuth\\Token'));
        $this->assertNotNull(Application::controllerFile('Auth'));
    }

    /** @return \Generator<string> */
    private function classFiles(): \Generator
    {
        $core = $this->root . 'core/';
        // core/modules/ is walked below, through Modules::all()
        foreach ($this->phpFiles($core, ['tests', 'skeleton', 'archive', 'deprecated', 'modules']) as $file) {
            yield $file;
        }
        foreach ($this->phpFiles($this->root . 'core/lib/', []) as $file) {
            yield $file;
        }
        foreach (glob($this->root . 'core/entity/*.php') ?: [] as $file) {
            yield $file;
        }
        // The modules core ships; a site's own are walked by its own tests.
        foreach (Modules::all() as $name => $dir) {
            if (!str_starts_with(realpath($dir), realpath($core))) {
                continue;
            }
            foreach ($this->phpFiles($dir, self::SKIP_DIRS) as $file) {
                yield $file;
            }
        }
    }
}
