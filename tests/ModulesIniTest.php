<?php

declare(strict_types=1);

namespace Zero\Tests\Core;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Zero\Core\Exception\FileNotWritableException;
use Zero\Core\Modules;
use Zero\Core\Exception\StaleFileException;
use Zero\Core\ModulesIni;

/**
 * ModulesIni is the one writer of app/modules.ini. Every test builds throwaway
 * module trees and a throwaway ini file.
 */
class ModulesIniTest extends TestCase
{
    private string $base;
    private string $ini;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/zerotest-modulesini-' . bin2hex(random_bytes(4));
        $this->ini = $this->base . '/app/modules.ini';
        mkdir($this->base . '/app', 0777, true);
        foreach (['bundled' => ['Auth', 'User', 'Admin', 'AccessRequest', 'Sitemap', 'Push', 'Module', 'Test'], 'site' => ['Blog', 'Zeta']] as $root => $names) {
            foreach ($names as $name) {
                mkdir("{$this->base}/{$root}/{$name}", 0777, true);
            }
        }
        $this->configure();
    }

    protected function tearDown(): void
    {
        @chmod($this->base . '/app', 0777);
        exec('rm -rf ' . escapeshellarg($this->base));
        zeroTestConfigureModules();
    }

    private function configure(): void
    {
        Modules::configure(["{$this->base}/site/", "{$this->base}/bundled/"], $this->ini);
    }

    private function text(): string
    {
        return (string) file_get_contents($this->ini);
    }

    /** @return string[] everything in the ini's folder except the ini itself */
    private function strays(): array
    {
        return array_values(array_diff(scandir(dirname($this->ini)), ['.', '..', 'modules.ini', 'modules.ini.lock']));
    }

    public function testOptionalOffIsWrittenAndDefaultRemovesIt(): void
    {
        $ini = new ModulesIni($this->ini);
        $ini->setEnabled('Sitemap', false);
        $ini->save();
        $this->assertStringContainsString("Sitemap = off\n", $this->text());

        $ini = new ModulesIni($this->ini);
        $ini->setEnabled('Sitemap', null);
        $ini->save();
        $this->assertStringNotContainsString('Sitemap', $this->text());
    }

    public function testAnEntryEqualToTheDefaultIsNotWritten(): void
    {
        $ini = new ModulesIni($this->ini);
        $ini->setEnabled('Sitemap', true);
        $ini->setEnabled('Module', false);
        $ini->save();
        $this->assertStringNotContainsString('Sitemap', $this->text());
        $this->assertStringNotContainsString('Module', $this->text());
    }

    public function testExampleOnIsWritten(): void
    {
        $ini = new ModulesIni($this->ini);
        $ini->setEnabled('Test', true);
        $ini->save();
        $this->assertStringContainsString("Test = on\n", $this->text());
        $this->configure();
        $this->assertTrue(Modules::enabled('Test'));
    }

    #[DataProvider('badNames')]
    public function testARefusedNameThrowsAndWritesNothing(string $name): void
    {
        $ini = new ModulesIni($this->ini);
        try {
            $ini->setEnabled($name, false);
            $this->fail('expected DomainException');
        } catch (\DomainException $e) {
            $this->assertTrue(true);
        }
        $ini->save();
        $this->assertStringNotContainsString(' = ', $this->text());
        $this->assertSame([], $this->strays());
    }

    #[DataProvider('unknownNames')]
    public function testAnUnknownNameCannotHaveAnOrder(string $name): void
    {
        $ini = new ModulesIni($this->ini);
        $this->expectException(\DomainException::class);
        $ini->setOrder($name, 5);
    }

    public static function unknownNames(): array
    {
        return [
            'unknown' => ['Nope'],
            'traversal' => ['../x'],
            'newline' => ["Blog\nHacked = on"],
            'equals' => ['Blog=on'],
            'bracket' => ['[enabled]'],
            'empty' => [''],
        ];
    }

    public static function badNames(): array
    {
        return [
            'unknown' => ['Nope'],
            'traversal' => ['../x'],
            'newline' => ["Blog\nHacked = on"],
            'equals' => ['Blog=on'],
            'bracket' => ['[enabled]'],
            'empty' => [''],
        ];
    }

    public function testRequiredModuleCanStillHaveAnOrder(): void
    {
        $ini = new ModulesIni($this->ini);
        $ini->setOrder('Admin', 5);
        $ini->save();
        $this->configure();
        $this->assertSame(5, Modules::order('Admin'));
    }

    public function testOrderIsWrittenAndRemoved(): void
    {
        $ini = new ModulesIni($this->ini);
        $ini->setOrder('Blog', 10);
        $ini->save();
        $this->assertStringContainsString("Blog = 10\n", $this->text());

        $ini = new ModulesIni($this->ini);
        $ini->setOrder('Blog', 0);
        $ini->setOrder('Zeta', 9999);
        $ini->save();
        $this->configure();
        $this->assertSame(0, Modules::order('Blog'));
        $this->assertSame(9999, Modules::order('Zeta'));

        $ini = new ModulesIni($this->ini);
        $ini->setOrder('Blog', null);
        $ini->save();
        $this->configure();
        $this->assertNull(Modules::order('Blog'));
    }

    #[DataProvider('badOrders')]
    public function testAnOrderOutsideTheRangeThrows(int $order): void
    {
        $ini = new ModulesIni($this->ini);
        $this->expectException(\DomainException::class);
        try {
            $ini->setOrder('Blog', $order);
        } finally {
            $this->assertFileDoesNotExist($this->ini);
        }
    }

    public static function badOrders(): array
    {
        return [[-1], [10000], [PHP_INT_MAX]];
    }

    public function testANonIntegerOrderIsRefused(): void
    {
        $ini = new ModulesIni($this->ini);
        $this->expectException(\TypeError::class);
        $ini->setOrder('Blog', '7; x');
    }

    public function testRoundTripThroughModules(): void
    {
        $ini = new ModulesIni($this->ini);
        $ini->setEnabled('Push', false);
        $ini->setEnabled('Module', true);
        $ini->setEnabled('Blog', false);
        $ini->setOrder('Zeta', 3);
        $ini->save();
        $this->configure();

        $this->assertFalse(Modules::enabled('Push'));
        $this->assertTrue(Modules::enabled('Module'));
        $this->assertFalse(Modules::enabled('Blog'));
        $this->assertTrue(Modules::enabled('Zeta'));
        $this->assertFalse(Modules::enabled('Test'));
        $this->assertSame(3, Modules::order('Zeta'));
        $this->assertNull(Modules::order('Blog'));
    }

    public function testAnotherSpellingWritesTheRealName(): void
    {
        $ini = new ModulesIni($this->ini);
        $ini->setEnabled('sitemap', false);
        $ini->save();
        $this->assertStringContainsString("Sitemap = off\n", $this->text());
        $this->assertStringNotContainsString('sitemap =', $this->text());
    }

    public function testHeaderAndAlphabeticalOrder(): void
    {
        $ini = new ModulesIni($this->ini);
        $ini->setEnabled('Sitemap', false);
        $ini->setEnabled('Blog', false);
        $ini->setEnabled('Push', false);
        $ini->setOrder('Zeta', 2);
        $ini->setOrder('Blog', 1);
        $ini->save();

        $this->assertSame(
            "; Only departures from the defaults. Written by the Admin modules screen.\n"
            . "[enabled]\nBlog = off\nPush = off\nSitemap = off\n\n[order]\nBlog = 1\nZeta = 2\n",
            $this->text()
        );
    }

    public function testAMissingFileIsEmptyAndSaveCreatesIt(): void
    {
        $ini = new ModulesIni($this->ini);
        $ini->save();
        $this->assertFileExists($this->ini);
        $this->assertSame(
            "; Only departures from the defaults. Written by the Admin modules screen.\n[enabled]\n\n[order]\n",
            $this->text()
        );
    }

    public function testUnknownNamesAlreadyInTheFileAreKept(): void
    {
        file_put_contents($this->ini, "[enabled]\nGone = off\n\n[order]\nGone = 4\nBlog = 2\n");
        $ini = new ModulesIni($this->ini);
        $ini->setEnabled('Sitemap', false);
        $ini->save();
        $this->assertStringContainsString("Gone = off\n", $this->text());
        $this->assertStringContainsString("Gone = 4\n", $this->text());
        $this->assertStringContainsString("Blog = 2\n", $this->text());
    }

    #[DataProvider('malformed')]
    public function testMalformedContentThrowsAtConstructionAndIsNotTouched(string $content): void
    {
        file_put_contents($this->ini, $content);
        try {
            new ModulesIni($this->ini);
            $this->fail('expected RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString($this->ini, $e->getMessage());
        }
        $this->assertSame($content, $this->text());
    }

    public static function malformed(): array
    {
        return [
            'scalar section' => ["enabled = 1\n[order]\nBlog = 1\n"],
            'syntax error' => ["[enabled\nBlog = off\n"],
            'unknown section' => ["[enabled]\nBlog = off\n[extra]\nA = b\n"],
        ];
    }

    public function testStaleSaveThrowsAndLeavesTheNewerContent(): void
    {
        $ini = new ModulesIni($this->ini);
        file_put_contents($this->ini, "[enabled]\nPush = off\n");
        $ini->setEnabled('Sitemap', false);
        try {
            $ini->save();
            $this->fail('expected RuntimeException');
        } catch (StaleFileException $e) {
            $this->assertSame('app/modules.ini changed since it was read', $e->getMessage());
        }
        $this->assertSame("[enabled]\nPush = off\n", $this->text());
        $this->assertSame([], $this->strays());
    }

    public function testStaleSaveWhenTheFileAppearedAfterAMissingRead(): void
    {
        $ini = new ModulesIni($this->ini);
        file_put_contents($this->ini, "[enabled]\nPush = off\n");
        $this->expectException(\RuntimeException::class);
        $ini->save();
    }

    public function testTwoInstancesSavingInSequence(): void
    {
        $first = new ModulesIni($this->ini);
        $second = new ModulesIni($this->ini);
        $first->setEnabled('Sitemap', false);
        $first->save();
        $second->setEnabled('Push', false);
        try {
            $second->save();
            $this->fail('expected RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('changed since it was read', $e->getMessage());
        }
        $this->assertStringContainsString('Sitemap = off', $this->text());
        $this->assertStringNotContainsString('Push', $this->text());
    }

    public function testTheSameInstanceCanSaveTwice(): void
    {
        $ini = new ModulesIni($this->ini);
        $ini->setEnabled('Sitemap', false);
        $ini->save();
        $ini->setEnabled('Push', false);
        $ini->save();
        $this->assertStringContainsString('Sitemap = off', $this->text());
        $this->assertStringContainsString('Push = off', $this->text());
    }

    public function testAReadOnlyFolderThrowsNamingThePathAndLeavesTheFile(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('root ignores folder permissions');
        }
        file_put_contents($this->ini, "[enabled]\nPush = off\n");
        $ini = new ModulesIni($this->ini);
        $ini->setEnabled('Sitemap', false);
        chmod($this->base . '/app', 0555);
        try {
            $ini->save();
            $this->fail('expected RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertInstanceOf(FileNotWritableException::class, $e);
            $this->assertStringContainsString($this->ini, $e->getMessage());
            $this->assertStringContainsString('is not writable', $e->getMessage());
        } finally {
            chmod($this->base . '/app', 0777);
        }
        $this->assertSame("[enabled]\nPush = off\n", $this->text());
        $this->assertSame([], $this->strays());
    }

    public function testAFolderWhereTheFileShouldBeThrowsNamingThePath(): void
    {
        mkdir($this->ini);
        try {
            new ModulesIni($this->ini);
            $this->fail('expected RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString($this->ini, $e->getMessage());
        }
        $this->assertSame([], $this->strays());
    }

    // ---- INI keywords ------------------------------------------------------

    #[DataProvider('keywordNames')]
    public function testANameThatIsAnIniKeywordCannotBeSet(string $name): void
    {
        mkdir("{$this->base}/site/{$name}");
        $this->configure();
        $ini = new ModulesIni($this->ini);
        foreach ([fn() => $ini->setEnabled($name, false), fn() => $ini->setOrder($name, 3)] as $call) {
            try {
                $call();
                $this->fail('expected DomainException');
            } catch (\DomainException $e) {
                $this->assertStringContainsString('cannot be set from app/modules.ini', $e->getMessage());
            }
        }
        $this->assertFalse(ModulesIni::isSettable($name));
        $ini->save();
        $this->assertStringNotContainsString(' = ', $this->text());
    }

    public static function keywordNames(): array
    {
        return array_map(fn($n) => [$n], ['On', 'Off', 'Yes', 'No', 'True', 'False', 'None', 'Null', 'NULL', 'ON', 'FALSE']);
    }

    public function testOrdinaryNamesAreSettable(): void
    {
        foreach (['Blog', 'Zeta', 'S', 'Index0', 'Nonexistent', 'Online'] as $name) {
            $this->assertTrue(ModulesIni::isSettable($name), $name);
        }
    }

    // ---- strict reading ----------------------------------------------------

    #[DataProvider('strictCases')]
    public function testExistingContentThatIsNotWhatWeWriteThrowsNamingFileAndKey(string $content, string $key): void
    {
        file_put_contents($this->ini, $content);
        $warnings = [];
        set_error_handler(function ($no, $str) use (&$warnings) { $warnings[] = $str; return true; });
        try {
            new ModulesIni($this->ini);
            $this->fail('expected RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString($this->ini, $e->getMessage());
            $this->assertStringContainsString($key, $e->getMessage());
        } finally {
            restore_error_handler();
        }
        $this->assertSame([], $warnings, 'no PHP warning');
        $this->assertSame($content, $this->text());
    }

    public static function strictCases(): array
    {
        return [
            'array value' => ["[enabled]\nPush[] = on\n", 'Push'],
            'string value' => ["[enabled]\nGone = 'abc'\n", 'Gone'],
            'reviewer dollar brace' => ["[enabled]\nGone = '\${abc'\n", 'Gone'],
            'reviewer unset variable' => ["[enabled]\nPush = '\${ZZ_UNSET}'\n", 'Push'],
            'integer under enabled' => ["[enabled]\nPush = 1\n", 'Push'],
            'string under order' => ["[order]\nPush = abc\n", 'Push'],
            'order below range' => ["[order]\nPush = -1\n", 'Push'],
            'order above range' => ["[order]\nPush = 10000\n", 'Push'],
            'order on' => ["[order]\nPush = on\n", 'Push'],
            'duplicate key' => ["[enabled]\nPush = off\nPush = on\n", 'Push'],
            'duplicate in another case' => ["[enabled]\nsitemap = on\nSitemap = off\n", 'Sitemap'],
            'duplicate with a hyphen' => ["[order]\nSite-map = 1\nSitemap = 2\n", 'Site-map'],
            'key that is not a name' => ["[enabled]\nA.b = on\n", 'A.b'],
            'numeric key' => ["[enabled]\n123 = on\n", '123'],
        ];
    }

    public function testWellFormedUnknownNamesSurviveAsWritten(): void
    {
        file_put_contents($this->ini, "; mine\n[enabled]\nGone = off\nlower_name = on\n\n[order]\nGone = 7\n");
        $ini = new ModulesIni($this->ini);
        $ini->setEnabled('Sitemap', false);
        $ini->save();
        $this->assertSame(
            "; Only departures from the defaults. Written by the Admin modules screen.\n"
            . "[enabled]\nGone = off\nlower_name = on\nSitemap = off\n\n[order]\nGone = 7\n",
            $this->text()
        );
        $this->assertStringNotContainsString('"', $this->text());
    }

    public function testTheTextIsCheckedReadingBackBeforeTheRename(): void
    {
        $original = "[enabled]\nPush = off\n";
        file_put_contents($this->ini, $original);
        $ini = new ModulesIni($this->ini);
        // Inject entries the setters would never allow: a keyword and a quote.
        $prop = new \ReflectionProperty(ModulesIni::class, 'enabled');
        $prop->setValue($ini, ['Push' => false, 'On' => false, 'Good' => true]);
        try {
            $ini->save();
            $this->fail('expected RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('would not read back', $e->getMessage());
        }
        $this->assertSame($original, $this->text());
        $this->assertSame([], $this->strays());
    }

    // ---- interrupted write -------------------------------------------------

    public function testAFailureAfterTheTempFileExistsLeavesTheOriginalAndNoTempFile(): void
    {
        $original = "[enabled]\nPush = off\n";
        file_put_contents($this->ini, $original);
        $seen = [];
        $ini = new class($this->ini, $seen) extends ModulesIni {
            public function __construct(string $file, public array &$seen)
            {
                parent::__construct($file);
            }
            protected function replace(string $temp, string $target): bool
            {
                $this->seen['temp'] = file_exists($temp) && file_get_contents($temp) !== '';
                return false;
            }
        };
        $ini->setEnabled('Sitemap', false);
        try {
            $ini->save();
            $this->fail('expected FileNotWritableException');
        } catch (FileNotWritableException $e) {
            $this->assertStringContainsString($this->ini, $e->getMessage());
        }
        $this->assertTrue($seen['temp'], 'the temp file existed with content when the step failed');
        $this->assertSame($original, $this->text());
        $this->assertSame([], $this->strays());
    }

    // ---- hash --------------------------------------------------------------

    public function testHashFollowsTheContent(): void
    {
        $missing = (new ModulesIni($this->ini))->hash();
        $ini = new ModulesIni($this->ini);
        $ini->setEnabled('Sitemap', false);
        $ini->save();
        $this->assertNotSame($missing, $ini->hash());
        $this->assertSame($ini->hash(), (new ModulesIni($this->ini))->hash());
    }

    // ---- file model --------------------------------------------------------

    public function testAReadOnlyFileIsRefusedAndKept(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('root ignores file permissions');
        }
        file_put_contents($this->ini, "[enabled]\nPush = off\n");
        chmod($this->ini, 0444);
        $ini = new ModulesIni($this->ini);
        $ini->setEnabled('Sitemap', false);
        try {
            $ini->save();
            $this->fail('expected FileNotWritableException');
        } catch (FileNotWritableException $e) {
            $this->assertStringContainsString($this->ini, $e->getMessage());
            $this->assertStringContainsString('read-only', $e->getMessage());
        }
        $this->assertSame("[enabled]\nPush = off\n", $this->text());
        $this->assertSame([], $this->strays());
    }

    public function testAnExistingFileKeepsItsPermissionBits(): void
    {
        file_put_contents($this->ini, "[enabled]\nPush = off\n");
        chmod($this->ini, 0640);
        $ini = new ModulesIni($this->ini);
        $ini->setEnabled('Sitemap', false);
        $ini->save();
        clearstatcache();
        $this->assertSame('0640', substr(sprintf('%o', fileperms($this->ini)), -4));

        chmod($this->ini, 0664);
        $ini = new ModulesIni($this->ini);
        $ini->setEnabled('Push', null);
        $ini->save();
        clearstatcache();
        $this->assertSame('0664', substr(sprintf('%o', fileperms($this->ini)), -4));
    }

    public function testANewFileIsGroupWritable(): void
    {
        $old = umask(022);
        try {
            $ini = new ModulesIni($this->ini);
            $ini->setEnabled('Sitemap', false);
            $ini->save();
        } finally {
            umask($old);
        }
        clearstatcache();
        $this->assertSame('0664', substr(sprintf('%o', fileperms($this->ini)), -4));
    }

    // ---- lock file ---------------------------------------------------------

    public function testALockFileThatCannotBeWrittenIsStillUsed(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('root ignores file permissions');
        }
        $lock = $this->ini . '.lock';
        touch($lock);
        chmod($lock, 0444);
        $ini = new ModulesIni($this->ini);
        $ini->setEnabled('Sitemap', false);
        $ini->save();
        $this->assertStringContainsString('Sitemap = off', $this->text());
    }

    public function testALockFileThatIsASymlinkIsRefused(): void
    {
        $target = $this->base . '/elsewhere';
        symlink($target, $this->ini . '.lock');
        $ini = new ModulesIni($this->ini);
        $ini->setEnabled('Sitemap', false);
        try {
            $ini->save();
            $this->fail('expected FileNotWritableException');
        } catch (FileNotWritableException $e) {
            $this->assertStringContainsString('symlink', $e->getMessage());
        }
        $this->assertFileDoesNotExist($target);
        $this->assertFileDoesNotExist($this->ini);
    }
}
