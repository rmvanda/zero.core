<?php

namespace Zero\Tests\Core;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\TestCase;
use Zero\Core\Application;
use Zero\Entity\GenericEntity;

/**
 * The entity framework (Entity, GenericEntity, Event) ships in core/entity/;
 * a site's own entity classes, generated or hand-written, live in the site's
 * entity/ folder. These tests cover where each is found and where a generated
 * class is written. No test touches the database: the path decision and the
 * file write are exercised against a throwaway folder.
 *
 * ENTITY_SITE_DIR and ZERO_ROOT are constants, which cannot be undefined, so
 * every test that defines one runs in its own process.
 */
class EntityLocationTest extends TestCase
{
    private string $root;
    private string $dir = '';

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2) . '/';
    }

    protected function tearDown(): void
    {
        if ($this->dir !== '' && is_dir($this->dir)) {
            foreach (glob($this->dir . '*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($this->dir);
        }
    }

    /**
     * Loading Entity.php opens the framework's shared connection from the
     * HOST/USER/PASS/NAME constants Application defines at request start. A
     * separate process has none, so they are read from database.ini as the
     * entity test scripts do. The connection only reads; no row is written.
     */
    private function defineDatabaseConstants(): void
    {
        $ini = parse_ini_file($this->root . 'app/config/database.ini', false, INI_SCANNER_RAW);
        foreach ($ini as $key => $value) {
            if (!defined($key)) {
                define($key, $value);
            }
        }
    }

    /** A throwaway site entity folder, with the given files, removed in tearDown(). */
    private function siteDir(array $files = []): string
    {
        $this->dir = sys_get_temp_dir() . '/zerotest-entity-' . uniqid() . '/';
        mkdir($this->dir);
        foreach ($files as $name) {
            file_put_contents($this->dir . $name, "<?php\n");
        }
        return $this->dir;
    }

    public function testTheFrameworkClassesLiveInCore(): void
    {
        foreach (['Entity', 'GenericEntity', 'Event'] as $class) {
            $this->assertFileExists($this->root . "core/entity/$class.php");
            $this->assertSame(
                realpath($this->root . "core/entity/$class.php"),
                realpath((string) Application::classFile("Zero\\Entity\\$class")),
                "$class resolves to core/entity/"
            );
        }
    }

    public function testNoFrameworkClassIsLeftInTheSiteFolder(): void
    {
        foreach (['Entity', 'GenericEntity', 'Event'] as $class) {
            $this->assertFileDoesNotExist($this->root . "entity/$class.php");
        }
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testASiteClassResolvesInTheSiteFolderAndAnUnknownOneDoesNot(): void
    {
        $dir = $this->siteDir(['Widget.php']);
        define('ENTITY_SITE_DIR', $dir);

        $this->assertSame(realpath($dir . 'Widget.php'), realpath((string) Application::classFile('Zero\\Entity\\Widget')));
        $this->assertNull(Application::classFile('Zero\\Entity\\Nope'));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAFrameworkClassWinsOverASiteClassOfTheSameName(): void
    {
        $dir = $this->siteDir(['Event.php']);
        define('ENTITY_SITE_DIR', $dir);

        $this->assertSame(
            realpath($this->root . 'core/entity/Event.php'),
            realpath((string) Application::classFile('Zero\\Entity\\Event'))
        );
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testTheSiteFolderIsNotLeftThroughASegmentOrASymlink(): void
    {
        $dir = $this->siteDir(['Widget.php']);
        define('ENTITY_SITE_DIR', $dir);
        symlink($this->root . 'core/Application.php', $dir . 'Linked.php');

        $this->assertNull(Application::classFile('Zero\\Entity\\Linked'), 'a symlink out of the site folder is not loaded');
        $this->assertNull(Application::classFile('Zero\\Entity\\..\\Widget'));
        $this->assertNull(Application::classFile('Zero\\Entity\\Sub\\Widget'));
    }

    public function testWithNoSiteFolderTheFrameworkClassesStillResolve(): void
    {
        $this->assertNotNull(Application::classFile('Zero\\Entity\\Event'));
        $this->assertNull(Application::classFile('Zero\\Entity\\ZerotestNoSuchClass'));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testSiteDirIsTheConstantWhenDefined(): void
    {
        $this->defineDatabaseConstants();
        define('ENTITY_SITE_DIR', '/some/where/entity/');
        define('ZERO_ROOT', '/var/other/');
        $this->assertSame('/some/where/entity/', GenericEntity::siteDir());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testSiteDirIsTheEntityFolderUnderZeroRoot(): void
    {
        $this->defineDatabaseConstants();
        define('ZERO_ROOT', '/var/other');
        $this->assertSame('/var/other/entity/', GenericEntity::siteDir());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testSiteDirFallsBackToTheFrameworkFolderWhenUsedAlone(): void
    {
        $this->defineDatabaseConstants();
        $this->assertFalse(defined('ZERO_ROOT'));
        $this->assertFalse(defined('ENTITY_SITE_DIR'));
        $this->assertSame(realpath($this->root . 'core/entity') . '/', rtrim(GenericEntity::siteDir(), '/') . '/');
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAGeneratedClassIsWrittenToTheSiteFolderNotToCore(): void
    {
        $this->defineDatabaseConstants();
        $dir = $this->siteDir();
        define('ENTITY_SITE_DIR', $dir . 'nested/');   // missing: created on write

        $method = new \ReflectionMethod(GenericEntity::class, 'generateClassFile');
        $path = $method->invoke(null, 'zerotest_gadget', ['attributes' => ['name']]);

        $this->assertSame($dir . 'nested/ZerotestGadget.php', $path);
        $this->assertFileExists($path);
        $this->assertFileDoesNotExist($this->root . 'core/entity/ZerotestGadget.php');
        $this->assertStringContainsString('class ZerotestGadget', (string) file_get_contents($path));

        unlink($path);
        rmdir($dir . 'nested');
    }
}
