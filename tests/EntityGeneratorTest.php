<?php

namespace Zero\Tests\Core;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Zero\Entity\GenericEntity;

/**
 * GenericEntity writes PHP source from a definition. Names must be plain
 * identifiers, may not collide with a framework class, and every value placed
 * in the generated code is emitted as data. No test touches the database: the
 * generator is called against a throwaway site folder.
 *
 * ENTITY_SITE_DIR is a constant, so each test runs in its own process.
 */
class EntityGeneratorTest extends TestCase
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

    /** Loading Entity.php opens the shared connection (read only); see EntityLocationTest. */
    private function prepare(string $sub = ''): void
    {
        $ini = parse_ini_file($this->root . 'app/config/database.ini', false, INI_SCANNER_RAW);
        foreach ($ini as $key => $value) {
            if (!defined($key)) {
                define($key, $value);
            }
        }
        $this->dir = sys_get_temp_dir() . '/zerotest-entitygen-' . uniqid() . '/';
        mkdir($this->dir);
        define('ENTITY_SITE_DIR', $this->dir . $sub);
    }

    private function generate(string $name, array $config): string
    {
        $method = new \ReflectionMethod(GenericEntity::class, 'generateClassFile');
        return $method->invoke(null, $name, $config, true);
    }

    /** Token types of the code with the timestamp line irrelevant (it is a comment). */
    private function typeSequence(string $code): array
    {
        $types = [];
        foreach (token_get_all($code) as $token) {
            $types[] = is_array($token) ? $token[0] : $token;
        }
        return $types;
    }

    /** The values of every string literal in the code, decoded. */
    private function literals(string $code): array
    {
        $out = [];
        foreach (token_get_all($code) as $token) {
            if (is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING) {
                $out[] = preg_replace('/\\\\([\\\\\'])/', '$1', substr($token[1], 1, -1));
            }
        }
        return $out;
    }

    private function lint(string $path): void
    {
        exec('php -l ' . escapeshellarg($path) . ' 2>&1', $output, $code);
        $this->assertSame(0, $code, implode("\n", $output));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testNamesThatCollideWithAFrameworkClassAreRefusedAndNothingIsWritten(): void
    {
        $this->prepare();
        foreach (['generic_entity', 'GenericEntity', 'event', 'Entity', 'test_case', "foo\n", '_', ''] as $name) {
            try {
                $this->generate($name, ['attributes' => ['a']]);
                $this->fail("'" . addcslashes($name, "\n") . "' was accepted");
            } catch (\Exception $e) {
                $this->assertNotInstanceOf(\Error::class, $e);
            }
        }
        $this->assertSame([], glob($this->dir . '*'));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testHostileNamesAndKeysAreRefusedAndNothingIsWritten(): void
    {
        $this->prepare();
        $bad = [
            ['attributes' => ["a']); system('id'); //"]],
            ['attributes' => ["*/ system('id'); /*"]],
            ['attributes' => ["a\n"]],
            ['attributes' => ['a'], 'defaults' => ['a' => "server:REMOTE_ADDR']; system('id'); \$_SERVER['X"]],
            ['attributes' => ['a'], 'defaults' => ['a' => 'server:remote_addr']],
            ['attributes' => ['a'], 'defaults' => ["b']; system('id'); //" => 'x']],
            ['attributes' => ['a'], 'defaults' => ['a' => new \stdClass()]],
            ['attributes' => ['a'], 'uniq' => ["a']); system('id'); //"]],
        ];
        foreach ($bad as $config) {
            try {
                $this->generate('zerotest_widget', $config);
                $this->fail('accepted ' . json_encode($config));
            } catch (\Exception $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
        $this->assertSame([], glob($this->dir . '*'));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testHostileValuesBecomeDataNotCode(): void
    {
        $this->prepare();
        $cases = [
            'date format'   => ['date:', "Y'.system('id').'", 'date:Y-m-d'],
            'uniqid prefix' => ['uniqid:', "x'.phpinfo().'", 'uniqid:x'],
            'plain default' => ['', "it's a \\ \$x {\$x}\nline", 'plain'],
        ];
        foreach ($cases as $label => [$prefix, $hostile, $harmless]) {
            $good = $this->generate('zerotest_widget', ['attributes' => ['a'], 'defaults' => ['a' => $harmless]]);
            $goodCode = (string) file_get_contents($good);
            $path = $this->generate('zerotest_widget', ['attributes' => ['a'], 'defaults' => ['a' => $prefix . $hostile]]);
            $code = (string) file_get_contents($path);

            $this->lint($path);
            $this->assertSame($this->typeSequence($goodCode), $this->typeSequence($code), "$label: same tokens as a harmless value");
            $this->assertContains($hostile, $this->literals($code), "$label: the value round-trips as data");
        }
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAServerKeyIsEmittedAsData(): void
    {
        $this->prepare();
        $path = $this->generate('zerotest_widget', ['attributes' => ['a'], 'defaults' => ['a' => 'server:REMOTE_ADDR']]);
        $this->lint($path);
        $this->assertContains('REMOTE_ADDR', $this->literals((string) file_get_contents($path)));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testALegitimateDefinitionGeneratesTheSameClassAsBefore(): void
    {
        $this->prepare();
        $path = $this->generate('zerotest_product', [
            'attributes' => ['name', 'price', 'sku', 'status', 'created_at'],
            'defaults'   => ['status' => 'active', 'created_at' => 'date:Y-m-d H:i:s'],
            'uniq'       => ['sku'],
        ]);
        $code = preg_replace('/Generated on: [0-9: -]+/', 'Generated on: STAMP', (string) file_get_contents($path));
        $expected = <<<'PHP'
<?php
/**
 * Generated Entity Class: ZerotestProduct
 * Generated on: STAMP
 * From GenericEntity definition: 'zerotest_product'
 *
 * This class was auto-generated from a GenericEntity definition.
 * You can now modify this file directly - it will not be overwritten
 * unless you call GenericEntity::define() with $force=true.
 *
 * Original configuration:
 * - Attributes: name, price, sku, status, created_at
 * - Unique constraints: sku
 */

namespace Zero\Entity;

class ZerotestProduct extends Entity
{

    public $uniq = array (
  0 => 'sku',
);

    public function __construct(?array $data = [])
    {

        // Apply default values
        if (!array_key_exists('status', $data)) {
            $data['status'] = 'active';
        }
        if (!array_key_exists('created_at', $data)) {
            $data['created_at'] = date('Y-m-d H:i:s', time());
        }

        parent::__construct($data);
    }
}

PHP;
        $this->assertSame($expected, $code);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testACreatedSiteFolderIsGroupWritable(): void
    {
        $this->prepare();
        rmdir($this->dir);
        umask(022);
        $this->generate('zerotest_widget', ['attributes' => ['a']]);
        $this->assertSame('0775', substr(sprintf('%o', fileperms($this->dir)), -4));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAnUnwritableSiteFolderIsANormalExceptionWithNoWarning(): void
    {
        $this->prepare();
        $warnings = [];
        set_error_handler(static function ($no, $str) use (&$warnings) {
            if (error_reporting() & $no) {   // a suppressed (@) warning is not reported
                $warnings[] = $str;
            }
            return true;
        });
        chmod($this->dir, 0555);
        try {
            if (is_writable($this->dir)) {
                $this->markTestSkipped('running as a user that ignores folder permissions');
            }
            $this->generate('zerotest_widget', ['attributes' => ['a']]);
            $this->fail('the write was accepted');
        } catch (\Exception $e) {
            $this->assertStringContainsString('Failed to write class file', $e->getMessage());
        } finally {
            restore_error_handler();
            chmod($this->dir, 0775);
        }
        $this->assertSame([], $warnings);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testReservedWordsAreRefusedAndNothingIsWritten(): void
    {
        $this->prepare();
        $words = ['class', 'function', 'list', 'static', 'namespace', 'trait', 'readonly', 'match', 'fn', 'array',
            'callable', 'new', 'print', 'self', 'parent', 'int', 'string', 'object', 'null', 'true', 'false',
            'mixed', 'iterable', 'void', 'never', 'bool', 'float', 'CLASS', 'Static', 'foreach'];
        foreach ($words as $word) {
            try {
                $this->generate($word, ['attributes' => ['a']]);
                $this->fail("$word was accepted");
            } catch (\Exception $e) {
                $this->assertStringContainsString('reserved', $e->getMessage(), $word);
            }
        }
        $this->assertSame([], glob($this->dir . '*'));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testSourceThatDoesNotParseIsRefusedBeforeItIsWritten(): void
    {
        $this->prepare();
        $method = new \ReflectionMethod(GenericEntity::class, 'parseCheck');
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("Generated class for 'broken' does not parse");
        try {
            $method->invoke(null, "<?php\nnamespace Zero\\Entity;\nclass list extends Entity {}\n", 'broken');
        } finally {
            $this->assertSame([], glob($this->dir . '*'));
        }
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testNameLengthsAreCappedAtSixtyFour(): void
    {
        $this->prepare();
        $this->generate(str_repeat('a', 64), ['attributes' => [str_repeat('b', 64)]]);
        $this->assertCount(1, glob($this->dir . '*'));
        foreach ([[str_repeat('a', 65), ['x']], ['zerotest_ok', [str_repeat('b', 65)]]] as [$name, $attrs]) {
            try {
                $this->generate($name, ['attributes' => $attrs]);
                $this->fail('a 65 character name was accepted');
            } catch (\Exception $e) {
                $this->assertStringContainsString('longer than 64', $e->getMessage());
            }
        }
        $this->assertCount(1, glob($this->dir . '*'));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAFileDifferingOnlyInCaseIsACollision(): void
    {
        $this->prepare();
        file_put_contents($this->dir . 'FOOBAR.php', "<?php\n");
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('same class');
        $this->generate('foo_bar', ['attributes' => ['a']]);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testForceOverwritesOnlyTheFileOfTheSameEntity(): void
    {
        $this->prepare();
        $this->generate('foo_bar', ['attributes' => ['a']]);
        $this->generate('foo_bar', ['attributes' => ['a', 'b']]);   // same entity: overwritten
        $this->assertStringContainsString('a, b', (string) file_get_contents($this->dir . 'FooBar.php'));

        try {
            $this->generate('fooBar', ['attributes' => ['a']]);     // same class, other entity name
            $this->fail('a different entity overwrote the file');
        } catch (\Exception $e) {
            $this->assertStringContainsString("generated for entity 'foo_bar'", $e->getMessage());
        }

        file_put_contents($this->dir . 'Hand.php', "<?php\nclass Hand {}\n");
        try {
            $this->generate('hand', ['attributes' => ['a']]);
            $this->fail('a hand-written file was overwritten');
        } catch (\Exception $e) {
            $this->assertStringContainsString('cannot be determined', $e->getMessage());
        }
        $this->assertStringContainsString('class Hand', (string) file_get_contents($this->dir . 'Hand.php'));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testEveryCreatedFolderLevelIsGroupWritable(): void
    {
        $this->prepare('a/b/');
        umask(022);
        $this->generate('zerotest_widget', ['attributes' => ['a']]);
        foreach (['a', 'a/b'] as $level) {
            $this->assertSame('0775', substr(sprintf('%o', fileperms($this->dir . $level)), -4), $level);
        }
        unlink($this->dir . 'a/b/ZerotestWidget.php');
        rmdir($this->dir . 'a/b');
        rmdir($this->dir . 'a');
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testEachConstantCarriesItsOwnComment(): void
    {
        $this->prepare();
        $max = (new \ReflectionClassConstant(GenericEntity::class, 'MAX_NAME_LENGTH'))->getDocComment();
        $identifier = (new \ReflectionClassConstant(GenericEntity::class, 'IDENTIFIER'))->getDocComment();
        $this->assertStringContainsString('Longest entity type name', (string) $max);
        $this->assertStringNotContainsString('plain identifier', (string) $max);
        $this->assertStringContainsString('plain identifier', (string) $identifier);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAClassThatCannotBeLoadedKeepsItsCauseAsPrevious(): void
    {
        $this->prepare();
        // The wrapping Exception in define() passes the original Throwable on.
        $method = new \ReflectionMethod(GenericEntity::class, 'define');
        $lines = array_slice(file($method->getFileName()), $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1);
        $this->assertMatchesRegularExpression('/could not be loaded: ".*getMessage\(\), 0, \$e\)/', implode('', $lines));
    }
}
