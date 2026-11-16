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
        'Zero\\Core\\CleanInput' => 'aux/ class in the Zero\\Core namespace; required by path',
        'Zero\\Core\\OCR' => 'aux/ class in the Zero\\Core namespace; Share requires it by path',
        'Zero\\Core\\ReceiptParser' => 'aux/ class in the Zero\\Core namespace; Share and Test require it by path',
        'Zero\\Module\\MyModule' => 'documentation example, never loaded',
        'Zero\\Lib\\Push\\PushHelper' => 'stale copy in modules/Push/; the class loads from lib/Push/',
        'Zero\\Module\\Slots\\Model\\SlotsRng' => 'second class in SlotsEngine.php, used only once that file is loaded',
        'Zero\\Module\\TheButton\\ButtonModel' => 'required by TheButton',
        'Zero\\Module\\TheButton\\InviteeModel' => 'required by TheButton',
        'Zero\\Module\\TheButton\\PressModel' => 'required by TheButton',
        'Zero\\Module\\Ttrpg\\EntityGuard' => 'required by Ttrpg from helper/',
        'Zero\\Module\\Ttrpg\\GearApiHandler' => 'required by Ttrpg from helper/',
        'Zero\\Module\\Ttrpg\\RequestHelper' => 'required by Ttrpg from helper/',
        'Zero\\Module\\Ttrpg\\SearchApiHandler' => 'required by Ttrpg from helper/',
        'Zero\\Module\\Ttrpg\\HexmapModel' => 'required by the Hexmap submodule',
        'Zero\\Module\\Ttrpg\\DMToolsTests' => 'manual test script in test/',
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

        $this->assertGreaterThan(100, $checked, 'the walk found almost no classes');
        $this->assertSame([], $misses, count($misses) . " of $checked classes do not resolve:\n" . implode("\n", $misses));
    }

    public function testUnknownClassIsNull(): void
    {
        $this->assertNull(Application::classFile('Zero\Module\Nope\Thing'));
        $this->assertNull(Application::classFile('Other\Thing'));
    }

    public function testSubmoduleClass(): void
    {
        $this->assertSame(
            realpath($this->root . 'modules/Admin/submodule/Firewall/Firewall.php'),
            realpath((string) Application::classFile('Zero\Module\Admin\Firewall'))
        );
    }

    public function testAuxClass(): void
    {
        $yelp = $this->root . 'aux/yelp/Yelp.php';
        if (!is_file($yelp)) {
            $this->markTestSkipped('aux/ is not present in this checkout');
        }
        $this->assertSame(realpath($yelp), realpath((string) Application::classFile('Zero\\Aux\\Yelp\\Yelp')));
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
        foreach ($this->phpFiles($core, ['tests', 'skeleton', 'archive']) as $file) {
            yield $file;
        }
        foreach ($this->phpFiles($this->root . 'lib/', []) as $file) {
            yield $file;
        }
        // aux/ is its own repository, ignored in the stage repository; walked when present
        foreach ($this->phpFiles($this->root . 'aux/', ['tests', 'node_modules', 'data', 'docs']) as $file) {
            yield $file;
        }
        foreach (glob($this->root . 'entity/*.php') ?: [] as $file) {
            yield $file;
        }
        foreach (glob($this->root . 'modules/*', GLOB_ONLYDIR) ?: [] as $dir) {
            if (basename($dir) === 'Jackpack') {
                continue;
            }
            foreach ($this->phpFiles($dir . '/', self::SKIP_DIRS) as $file) {
                yield $file;
            }
        }
    }

    /**
     * @param string[] $skip directory names not to descend into
     * @return \Generator<string>
     */
    private function phpFiles(string $dir, array $skip): \Generator
    {
        if (!is_dir($dir)) {
            return;
        }
        $names = scandir($dir) ?: [];
        sort($names);
        foreach ($names as $name) {
            if ($name[0] === '.') {
                continue;
            }
            $path = $dir . $name;
            if (is_dir($path)) {
                if (!in_array($name, $skip, true)) {
                    yield from $this->phpFiles($path . '/', $skip);
                }
            } elseif (str_ends_with($name, '.php')) {
                yield $path;
            }
        }
    }

    /**
     * Fully qualified names of the classes, interfaces, traits and enums a
     * file declares in a namespace that begins Zero\.
     *
     * @return string[]
     */
    private function declaredClasses(string $file): array
    {
        $tokens = token_get_all((string) file_get_contents($file));
        $namespace = '';
        $found = [];
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if (!is_array($token)) {
                continue;
            }
            if ($token[0] === T_NAMESPACE) {
                $namespace = '';
                for ($j = $i + 1; $j < $count; $j++) {
                    $t = $tokens[$j];
                    if (is_array($t) && in_array($t[0], [T_STRING, T_NAME_QUALIFIED, T_NS_SEPARATOR], true)) {
                        $namespace .= $t[1];
                    } elseif ($t === ';' || $t === '{') {
                        break;
                    }
                }
                $i = $j;
            } elseif (in_array($token[0], [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)) {
                // "Foo::class" and "new class" are not declarations
                $prev = $this->previousCode($tokens, $i);
                if ($prev !== null && is_array($prev) && in_array($prev[0], [T_DOUBLE_COLON, T_NEW], true)) {
                    continue;
                }
                for ($j = $i + 1; $j < $count; $j++) {
                    $t = $tokens[$j];
                    if (is_array($t) && $t[0] === T_STRING) {
                        if (str_starts_with($namespace, 'Zero\\')) {
                            $found[] = $namespace . '\\' . $t[1];
                        }
                        break;
                    }
                    if (is_array($t) && $t[0] === T_WHITESPACE) {
                        continue;
                    }
                    break;
                }
            }
        }

        return $found;
    }

    private function previousCode(array $tokens, int $i): array|string|null
    {
        for ($k = $i - 1; $k >= 0; $k--) {
            if (is_array($tokens[$k]) && in_array($tokens[$k][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            return $tokens[$k];
        }

        return null;
    }
}
