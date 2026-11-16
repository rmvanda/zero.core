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
        'Zero\\Lib\\Push\\PushHelper' => 'stale copy in core/modules/Push/; the class loads from core/lib/Push/',
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
            'Zero\\Lib\\Push\\PushHelper'       => 'core/lib/Push/PushHelper.php',
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
            realpath((string) Application::classFile('Zero\Module\Admin\Firewall'))
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
            'dotdot inside'       => ['Zero\\Module\\Auth\\..\\Admin\\Admin'],
            'slashes'             => ['Zero\\Module\\Auth/../x'],
            'null byte'           => ["Zero\\Module\\Auth\0\\Auth"],
            'dot'                 => ['Zero\\Module\\Auth.php'],
            'empty segment'       => ['Zero\\Module\\\\Auth'],
            'trailing newline'    => ["Zero\\Module\\Auth\n"],
            'long segment'        => ['Zero\\Module\\Auth\\' . str_repeat('A', 5000)],
            'dotdot in core rule' => ['Zero\\Core\\..\\..\\x'],
        ];
    }

    public function testModuleNameCheck(): void
    {
        foreach (['..', 'Auth\\OAuth\\Token', 'auth/../admin', '', 'Auth.php', "Auth\n", 'api-config'] as $bad) {
            $this->assertFalse(Application::isModuleName($bad), var_export($bad, true));
        }
        foreach (['Auth', 'AccessRequest'] as $good) {
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
        foreach ($this->phpFiles($core, ['tests', 'skeleton', 'archive', 'modules']) as $file) {
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
