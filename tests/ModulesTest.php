<?php

namespace Zero\Tests\Core;

use PHPUnit\Framework\TestCase;
use Zero\Core\Modules;

/**
 * Modules knows where a module lives, which tier it is and whether it is on.
 * Every test builds throwaway module trees and hands them to configure().
 */
class ModulesTest extends TestCase
{
    private string $base;
    private string $site;
    private string $bundled;
    private string $ini;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/zerotest-modules-' . bin2hex(random_bytes(4));
        $this->site = $this->base . '/site/';
        $this->bundled = $this->base . '/bundled/';
        $this->ini = $this->base . '/modules.ini';
        mkdir($this->site, 0777, true);
        mkdir($this->bundled, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->remove($this->base);
        zeroTestConfigureModules();
    }

    private function remove(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);
            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->remove($path . '/' . $entry);
            }
        }
        rmdir($path);
    }

    private function make(string $root, string ...$names): void
    {
        foreach ($names as $name) {
            mkdir($root . $name, 0777, true);
        }
    }

    private function configure(?string $ini = null): void
    {
        Modules::configure([$this->site, $this->bundled], $ini);
    }

    private function writeIni(string $content): void
    {
        file_put_contents($this->ini, $content);
    }

    public function testClashOnIdenticalNameThrowsNamingBothPaths(): void
    {
        $this->make($this->site, 'Auth');
        $this->make($this->bundled, 'Auth');
        $this->configure();

        try {
            Modules::assertNoClash();
            $this->fail('expected a RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString($this->site . 'Auth', $e->getMessage());
            $this->assertStringContainsString($this->bundled . 'Auth', $e->getMessage());
        }
    }

    public function testClashIgnoresCase(): void
    {
        $this->make($this->site, 'auth');
        $this->make($this->bundled, 'Auth');
        $this->configure();

        $this->expectException(\RuntimeException::class);
        Modules::assertNoClash();
    }

    public function testASitesIndexReplacesCoresAndIsNotAClash(): void
    {
        $this->make($this->site, 'Index');
        $this->make($this->bundled, 'Index');
        $this->configure();

        Modules::assertNoClash();
        $this->assertSame($this->site . 'Index/', Modules::path('Index'), 'the site\'s root comes first');
        $this->assertSame('required', Modules::tier('Index'));
        $this->assertTrue(Modules::enabled('Index'));
    }

    public function testCoresIndexServesASiteThatHasNone(): void
    {
        $this->make($this->bundled, 'Index');
        $this->configure();

        Modules::assertNoClash();
        $this->assertSame($this->bundled . 'Index/', Modules::path('Index'));
    }

    public function testOnlyTheExactNameIndexMayBeReplaced(): void
    {
        $this->make($this->site, 'index');
        $this->make($this->bundled, 'Index');
        $this->configure();

        $this->expectException(\RuntimeException::class);
        Modules::assertNoClash();
    }

    public function testNoClashWhenNamesDiffer(): void
    {
        $this->make($this->site, 'Lists');
        $this->make($this->bundled, 'Auth');
        $this->configure();

        Modules::assertNoClash();
        $this->addToAssertionCount(1);
    }

    public function testMissingRootIsSkipped(): void
    {
        $this->make($this->site, 'Lists');
        Modules::configure([$this->site, $this->base . '/nowhere/'], null);

        Modules::assertNoClash();
        $this->assertSame(['Lists'], array_keys(Modules::all()));
    }

    public function testPathMatchesAnySpelling(): void
    {
        $this->make($this->site, 'ApiConfig');
        $this->configure();

        $expected = $this->site . 'ApiConfig/';
        foreach (['ApiConfig', 'api-config', 'apiconfig', 'APICONFIG'] as $name) {
            $this->assertSame($expected, Modules::path($name), $name);
            $this->assertSame('ApiConfig', Modules::name($name), $name);
        }
    }

    public function testPathUnknownOrTraversalIsNull(): void
    {
        $this->make($this->site, 'Lists');
        $this->configure();

        $this->assertNull(Modules::path('Nope'));
        $this->assertNull(Modules::path('../etc'));
        $this->assertNull(Modules::path(''));
        $this->assertNull(Modules::name('Nope'));
    }

    public function testPathFindsModuleInSecondRoot(): void
    {
        $this->make($this->bundled, 'Auth');
        $this->configure();

        $this->assertSame($this->bundled . 'Auth/', Modules::path('auth'));
    }

    public function testLowercaseDirectoryIsNotAModule(): void
    {
        $this->make($this->site, 'vendor', 'Lists');
        $this->configure();

        $this->assertSame(['Lists'], array_keys(Modules::all()));
        $this->assertNull(Modules::path('vendor'));
    }

    public function testTiers(): void
    {
        $this->configure();

        foreach (['Index', 'Auth', 'User', 'Admin', 'AccessRequest'] as $name) {
            $this->assertSame('required', Modules::tier($name), $name);
        }
        foreach (['Push', 'Entity'] as $name) {
            $this->assertSame('optional', Modules::tier($name), $name);
        }
        foreach (['Module', 'HelloWorld', 'Test'] as $name) {
            $this->assertSame('example', Modules::tier($name), $name);
        }
        $this->assertSame('site', Modules::tier('Lists'));
    }

    public function testEnabledDefaultsWithoutIni(): void
    {
        $this->make($this->site, 'Lists');
        $this->make($this->bundled, 'Auth', 'Push', 'HelloWorld');
        $this->configure($this->ini);

        $this->assertTrue(Modules::enabled('Auth'));
        $this->assertTrue(Modules::enabled('Push'));
        $this->assertFalse(Modules::enabled('HelloWorld'));
        $this->assertTrue(Modules::enabled('Lists'));
        $this->assertFalse(Modules::enabled('Nope'));
        $this->assertNull(Modules::order('Links'));
    }

    public function testEnabledFollowsIni(): void
    {
        $this->make($this->site, 'Lists');
        $this->make($this->bundled, 'Auth', 'Push', 'HelloWorld');
        $this->writeIni("[enabled]\nPush = off\nHelloWorld = on\nAuth = off\nLists = off\nGhost = off\n");
        $this->configure($this->ini);

        $this->assertFalse(Modules::enabled('Push'));
        $this->assertTrue(Modules::enabled('HelloWorld'));
        $this->assertTrue(Modules::enabled('Auth'));
        $this->assertFalse(Modules::enabled('Lists'));
        $this->assertFalse(Modules::enabled('Ghost'));
    }

    public function testRoutableNeedsExistsAndEnabled(): void
    {
        $this->make($this->site, 'Lists', 'Gone');
        $this->writeIni("[enabled]\nGone = off\n");
        $this->configure($this->ini);

        $this->assertTrue(Modules::routable('lists'));
        $this->assertFalse(Modules::routable('Gone'));
        $this->assertFalse(Modules::routable('Nope'));
    }

    public function testOrderComesFromIni(): void
    {
        $this->make($this->site, 'Links', 'Lists');
        $this->writeIni("[order]\nLinks = 10\n");
        $this->configure($this->ini);

        $this->assertSame(10, Modules::order('Links'));
        $this->assertNull(Modules::order('Lists'));
    }

    public function testDropDisabledAssetLinksRemovesOnlyDisabledSymlinks(): void
    {
        $this->make($this->site, 'Lists', 'Gone', 'Real');
        mkdir($this->site . 'Gone/assets');
        mkdir($this->site . 'Lists/assets');
        $this->writeIni("[enabled]\nGone = off\nReal = off\n");
        $this->configure($this->ini);

        $web = $this->base . '/www';
        mkdir($web . '/assets/real', 0777, true);
        symlink($this->site . 'Gone/assets', $web . '/assets/gone');
        symlink($this->site . 'Lists/assets', $web . '/assets/lists');

        Modules::dropDisabledAssetLinks($web);

        $this->assertFalse(is_link($web . '/assets/gone'));
        $this->assertTrue(is_link($web . '/assets/lists'));
        $this->assertTrue(is_dir($web . '/assets/real') && !is_link($web . '/assets/real'));
    }

    public function testClashFoldsHyphensLikePath(): void
    {
        $this->make($this->site, 'ApiConfig');
        $this->make($this->bundled, 'Api-Config');
        $this->configure();

        $this->expectException(\RuntimeException::class);
        Modules::assertNoClash();
    }

    public function testClashUsesTheCachedListing(): void
    {
        $this->make($this->site, 'Lists');
        $this->configure();
        Modules::all();
        $this->make($this->bundled, 'Lists');

        Modules::assertNoClash();
        $this->addToAssertionCount(1);
    }

    public function testDropDisabledAssetLinksLeavesALinkPointingElsewhere(): void
    {
        $this->make($this->site, 'Gone', 'Elsewhere');
        mkdir($this->site . 'Gone/assets');
        $this->writeIni("[enabled]\nGone = off\n");
        $this->configure($this->ini);

        $web = $this->base . '/www';
        mkdir($web . '/assets', 0777, true);
        symlink($this->site . 'Elsewhere', $web . '/assets/gone');

        Modules::dropDisabledAssetLinks($web);
        $this->assertTrue(is_link($web . '/assets/gone'));

        unlink($web . '/assets/gone');
        symlink($this->site . 'Gone/assets', $web . '/assets/gone');
        Modules::dropDisabledAssetLinks($web);
        $this->assertFalse(is_link($web . '/assets/gone'));
    }

    public function testOnlyNamesTheRouterServesAreModules(): void
    {
        $this->make($this->site, 'Lists', 'Links (old)', 'Evil&Co', 'Api-Config', 'Api.Config', 'lowercase', '9Lives', 'Under_score', 'A', 'Index0');
        $this->configure();

        $names = array_keys(Modules::all());
        sort($names);
        $this->assertSame(['A', 'Index0', 'Lists'], $names);
        foreach (['Links (old)', 'Evil&Co', 'Api-Config', 'Api.Config', 'lowercase', '9Lives', 'Under_score'] as $name) {
            $this->assertArrayNotHasKey($name, Modules::all(), $name);
        }
        $this->assertNull(Modules::path('Evil&Co'));
    }

    public function testAFolderThatIsNotAModuleNameNeverClashes(): void
    {
        $this->make($this->site, 'Links (old)');
        $this->make($this->bundled, 'Links (old)');
        $this->configure();
        Modules::assertNoClash();
        $this->assertTrue(true);
    }

    public function testAnUnparseableIniWarnsOncePerRequest(): void
    {
        $this->make($this->site, 'Lists');
        $this->writeIni("[enabled\nLists = = off\n");
        $this->configure($this->ini);

        \Zero\Core\Console::clearMessages();
        Modules::enabled('Lists');
        Modules::enabled('Lists');
        Modules::order('Lists');

        $warnings = array_values(array_filter(\Zero\Core\Console::getMessages(), fn($m) => $m['level'] === 'WARN'));
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString($this->ini, $warnings[0]['message']);
    }

    public function testAParseableIniDoesNotWarn(): void
    {
        $this->make($this->site, 'Lists');
        $this->writeIni("[enabled]\nLists = off\n");
        $this->configure($this->ini);

        \Zero\Core\Console::clearMessages();
        Modules::enabled('Lists');
        $this->assertSame([], array_filter(\Zero\Core\Console::getMessages(), fn($m) => $m['level'] === 'WARN'));
    }

    public function testAFolderThatStartsUppercaseButBreaksTheRuleWarnsOncePerRequest(): void
    {
        $this->make($this->site, 'Lists', 'Foo_Bar', '.Hidden', 'lowercase', 'Links (old)');
        $this->configure();

        \Zero\Core\Console::clearMessages();
        Modules::all();
        Modules::all();
        Modules::path('Lists');
        Modules::assertNoClash();

        $warnings = array_column(array_filter(\Zero\Core\Console::getMessages(), fn($m) => $m['level'] === 'WARN'), 'message');
        sort($warnings);
        $this->assertCount(2, $warnings);
        $this->assertStringContainsString('Foo_Bar', $warnings[0]);
        $this->assertStringContainsString('^[A-Z][A-Za-z0-9]*$', $warnings[0]);
        $this->assertStringContainsString('Links (old)', $warnings[1]);
        $this->assertArrayNotHasKey('Foo_Bar', Modules::all());
    }

    public function testIniHashIsTheHashModulesIniHasForTheSameContent(): void
    {
        $this->make($this->site, 'Lists');
        foreach ([null, '', "[enabled]\nLists = off\n\n[order]\nLists = 3\n"] as $content) {
            @unlink($this->ini);
            if ($content !== null) {
                $this->writeIni($content);
            }
            $this->configure($this->ini);
            $this->assertSame((new \Zero\Core\ModulesIni($this->ini))->hash(), Modules::iniHash(), var_export($content, true));
        }
        $this->configure(null);
        $this->assertSame('absent', Modules::iniHash());
    }

    public function testIniHashIsOfTheTextTheAnswersCameFrom(): void
    {
        $this->make($this->site, 'Lists');
        $this->writeIni("[enabled]\nLists = off\n");
        $this->configure($this->ini);
        $this->assertFalse(Modules::enabled('Lists'));

        $this->writeIni("[enabled]\nLists = on\n");
        $this->assertFalse(Modules::enabled('Lists'), 'cached for the request');
        $this->assertSame(sha1("[enabled]\nLists = off\n"), Modules::iniHash());
    }

    public function testTheTextParsesTheSameAsTheFile(): void
    {
        $text = "; Only departures from the defaults.\n[enabled]\nModule = on\nTest = off\n\n[order]\nLinks = 10\nAuth = 20\n";
        $this->writeIni($text);
        $this->assertSame(@parse_ini_file($this->ini, true, INI_SCANNER_TYPED), Modules::parseIniString($text));
        $this->assertSame(Modules::parseIniString($text), Modules::parseIniFile($this->ini));
        $this->assertNull(Modules::parseIniString("[enabled\nLists = = off\n"));
        $this->assertNull(Modules::parseIniFile($this->base . '/missing.ini'));
    }

    public function testMalformedIniDegradesToDefaults(): void
    {
        $this->make($this->site, 'Lists');
        $this->make($this->bundled, 'HelloWorld');

        $this->writeIni("[enabled\nLists = = off\n[[order\n");
        $this->configure($this->ini);
        $this->assertTrue(Modules::enabled('Lists'));
        $this->assertFalse(Modules::enabled('HelloWorld'));
        $this->assertNull(Modules::order('Lists'));

        $this->writeIni("enabled = 1\norder = 2\n");
        $this->configure($this->ini);
        $this->assertTrue(Modules::enabled('Lists'));
        $this->assertFalse(Modules::enabled('HelloWorld'));
        $this->assertNull(Modules::order('Lists'));
    }

    public function testTheFirstRootWinsWhicheverOrderTheRootsAreGiven(): void
    {
        $this->make($this->site, 'OnlySite', 'Both');
        $this->make($this->bundled, 'OnlyBundled', 'Both');

        Modules::configure([$this->site, $this->bundled], null);
        $this->assertSame($this->site . 'OnlySite/', Modules::path('OnlySite'));
        $this->assertSame($this->bundled . 'OnlyBundled/', Modules::path('OnlyBundled'));
        $this->assertSame($this->site . 'Both/', Modules::path('Both'));
        $this->assertSame($this->site . 'Both/', Modules::all()['Both']);

        Modules::configure([$this->bundled, $this->site], null);
        $this->assertSame($this->site . 'OnlySite/', Modules::path('OnlySite'));
        $this->assertSame($this->bundled . 'OnlyBundled/', Modules::path('OnlyBundled'));
        $this->assertSame($this->bundled . 'Both/', Modules::path('Both'));
        $this->assertSame($this->bundled . 'Both/', Modules::all()['Both']);
    }

    public function testConfigureTwiceLeaksNothing(): void
    {
        $this->make($this->site, 'Lists');
        $this->writeIni("[enabled]\nLists = off\n[order]\nLists = 5\n");
        $this->configure($this->ini);
        $this->assertFalse(Modules::enabled('Lists'));
        $this->assertSame(5, Modules::order('Lists'));

        $other = $this->base . '/other/';
        mkdir($other . 'Fresh', 0777, true);
        Modules::configure([$other], null);

        $this->assertSame(['Fresh'], array_keys(Modules::all()));
        $this->assertNull(Modules::path('Lists'));
        $this->assertFalse(Modules::enabled('Lists'));
        $this->assertTrue(Modules::enabled('Fresh'));
        $this->assertNull(Modules::order('Lists'));
    }

    /** A web root with assets/ and a set of module folders that have assets/. */
    private function webRoot(): string
    {
        $web = $this->base . '/www';
        mkdir($web . '/assets', 0777, true);
        return $web;
    }

    public function testDropDisabledAssetLinksWalksEveryKindOfLink(): void
    {
        $this->make($this->site, 'NetGone', 'On', 'Foo', 'FooBar', 'Ttrpg');
        foreach (['NetGone', 'On', 'Foo', 'FooBar'] as $name) {
            mkdir($this->site . $name . '/assets');
        }
        mkdir($this->site . 'Ttrpg/submodule/Hexmap/assets', 0777, true);
        mkdir($this->site . 'Test/submodule/Route/assets', 0777, true);
        mkdir($this->base . '/outside');
        $this->writeIni("[enabled]\nNetGone = off\nFoo = off\nTtrpg = off\nTest = off\n");
        $this->configure($this->ini);

        $web = $this->webRoot();
        $assets = $web . '/assets';
        symlink($this->site . 'NetGone/assets', $assets . '/netGone');
        symlink($this->site . 'Ttrpg/submodule/Hexmap/assets', $assets . '/hexmap');
        mkdir($assets . '/test');
        symlink($this->site . 'Test/submodule/Route/assets', $assets . '/test/route');
        symlink($this->site . 'On/assets', $assets . '/on');
        symlink($this->site . 'FooBar/assets', $assets . '/foobar');
        symlink($this->base . '/outside', $assets . '/outside');
        mkdir($assets . '/css');
        symlink($this->site . 'On/assets', $assets . '/css/on');

        Modules::dropDisabledAssetLinks($web);

        $this->assertFalse(is_link($assets . '/netGone'), 'camel-case link');
        $this->assertFalse(is_link($assets . '/hexmap'), 'submodule link');
        $this->assertFalse(is_link($assets . '/test/route'), 'link below a real directory');
        $this->assertTrue(is_dir($assets . '/test') && !is_link($assets . '/test'), 'the real directory stays');
        $this->assertTrue(is_link($assets . '/on'), 'enabled module');
        $this->assertTrue(is_link($assets . '/css/on'), 'enabled module, one level down');
        $this->assertTrue(is_link($assets . '/foobar'), 'Foo does not match FooBar');
        $this->assertTrue(is_link($assets . '/outside'), 'link outside every module');
        $this->assertTrue(is_dir($assets . '/css'), 'real directory');
    }

    public function testDropDisabledAssetLinksIgnoresAMissingAssetsFolderAndDeadLinks(): void
    {
        $this->make($this->site, 'Gone');
        $this->writeIni("[enabled]\nGone = off\n");
        $this->configure($this->ini);

        $web = $this->base . '/www';
        Modules::dropDisabledAssetLinks($web);

        mkdir($web . '/assets', 0777, true);
        symlink($this->base . '/nowhere', $web . '/assets/dead');
        Modules::dropDisabledAssetLinks($web);
        $this->assertTrue(is_link($web . '/assets/dead'));
    }

    public function testASymlinkedSecondRootIsNotAClash(): void
    {
        $this->make($this->site, 'Lists');
        symlink(rtrim($this->site, '/'), $this->base . '/alias');
        Modules::configure([$this->site, $this->base . '/alias/', $this->bundled], null);

        Modules::assertNoClash();
        $this->assertSame(['Lists'], array_keys(Modules::all()));
        $this->assertSame($this->site . 'Lists/', Modules::path('Lists'));
    }

    public function testRoutablePathIsThePathOfAnEnabledModuleOnly(): void
    {
        $this->make($this->site, 'Lists', 'Gone');
        $this->writeIni("[enabled]\nGone = off\n");
        $this->configure($this->ini);

        $this->assertSame($this->site . 'Lists/', Modules::routablePath('lists'));
        $this->assertNull(Modules::routablePath('Gone'));
        $this->assertNull(Modules::routablePath('Nope'));
    }

    public function testAssetLinkCleanupRunsOnlyWhenTheDisabledSetChanges(): void
    {
        $this->make($this->site, 'Gone', 'Also');
        mkdir($this->site . 'Gone/assets');
        mkdir($this->site . 'Also/assets');
        $this->writeIni("[enabled]\nGone = off\n");
        $this->configure($this->ini);
        $web = $this->webRoot();
        $marker = $this->base . '/cache/nested/modules-assets.marker';
        $link = $web . '/assets/gone';

        symlink($this->site . 'Gone/assets', $link);
        $this->assertTrue(Modules::dropDisabledAssetLinksWhenChanged($marker, $web), 'first run cleans');
        $this->assertFalse(is_link($link));
        $this->assertFileExists($marker);

        symlink($this->site . 'Gone/assets', $link);
        $this->assertFalse(Modules::dropDisabledAssetLinksWhenChanged($marker, $web), 'same set: nothing to do');
        $this->assertTrue(is_link($link));

        // The set changes (a second module goes off) with the ini file the same size and age.
        $this->writeIni("[enabled]\nGone = off\nAlso = off\n");
        $this->configure($this->ini);
        symlink($this->site . 'Also/assets', $web . '/assets/also');
        $this->assertTrue(Modules::dropDisabledAssetLinksWhenChanged($marker, $web), 'a changed set cleans again');
        $this->assertFalse(is_link($link));
        $this->assertFalse(is_link($web . '/assets/also'));
    }

    public function testANewExampleTierFolderWithAnUnchangedIniLosesItsLink(): void
    {
        $this->make($this->site, 'Lists');
        $this->writeIni("[enabled]\nLists = on\n");
        $this->configure($this->ini);
        $web = $this->webRoot();
        $marker = $this->base . '/cache/marker';

        $this->assertFalse(Modules::dropDisabledAssetLinksWhenChanged($marker, $web), 'nothing off, no marker');

        mkdir($this->site . 'HelloWorld/assets', 0777, true);
        symlink($this->site . 'HelloWorld/assets', $web . '/assets/helloworld');
        $this->configure($this->ini);

        $this->assertTrue(Modules::dropDisabledAssetLinksWhenChanged($marker, $web));
        $this->assertFalse(is_link($web . '/assets/helloworld'));
    }

    public function testAnEmptyDisabledSetRewritesAStaleMarkerButCleansNothing(): void
    {
        $this->make($this->site, 'Lists');
        $this->configure($this->base . '/missing.ini');
        $web = $this->webRoot();
        $marker = $this->base . '/cache/marker';
        mkdir(dirname($marker));
        file_put_contents($marker, 'stale-set');
        symlink($this->site . 'Lists', $web . '/assets/lists');

        Modules::dropDisabledAssetLinksWhenChanged($marker, $web);

        $this->assertNotSame('stale-set', file_get_contents($marker));
        $this->assertTrue(is_link($web . '/assets/lists'));
        $this->assertFalse(Modules::dropDisabledAssetLinksWhenChanged($marker, $web), 'now in step');
    }

    public function testAssetLinkCleanupWithAnUnwritableMarkerStillCleans(): void
    {
        $this->make($this->site, 'Gone');
        mkdir($this->site . 'Gone/assets');
        $this->writeIni("[enabled]\nGone = off\n");
        $this->configure($this->ini);
        $web = $this->webRoot();
        file_put_contents($this->base . '/afile', 'x');
        $marker = $this->base . '/afile/sub/marker';

        foreach ([1, 2] as $run) {
            symlink($this->site . 'Gone/assets', $web . '/assets/gone');
            $this->assertTrue(Modules::dropDisabledAssetLinksWhenChanged($marker, $web), "run $run");
            $this->assertFalse(is_link($web . '/assets/gone'));
        }
    }

    public function testAFailedRemovalLeavesTheMarkerUnwritten(): void
    {
        $this->make($this->site, 'Gone');
        mkdir($this->site . 'Gone/assets');
        $this->writeIni("[enabled]\nGone = off\n");
        $this->configure($this->ini);
        $web = $this->webRoot();
        $marker = $this->base . '/cache/marker';
        symlink($this->site . 'Gone/assets', $web . '/assets/gone');
        chmod($web . '/assets', 0555);
        if (is_writable($web . '/assets')) {
            chmod($web . '/assets', 0755);
            $this->markTestSkipped('directory permissions are not enforced for this user');
        }

        try {
            $this->assertTrue(Modules::dropDisabledAssetLinksWhenChanged($marker, $web));
            $this->assertFileDoesNotExist($marker);
        } finally {
            chmod($web . '/assets', 0755);
        }
        $this->assertTrue(Modules::dropDisabledAssetLinksWhenChanged($marker, $web), 'retried next time');
        $this->assertFalse(is_link($web . '/assets/gone'));
        $this->assertFileExists($marker);
    }

    public function testNoIniAndNothingDisabledByDefaultDoesNothing(): void
    {
        $this->make($this->site, 'Lists');
        $this->configure($this->base . '/missing.ini');
        $web = $this->webRoot();
        $marker = $this->base . '/cache/marker';

        $this->assertFalse(Modules::dropDisabledAssetLinksWhenChanged($marker, $web));
        $this->assertFileDoesNotExist($marker);
    }

    public function testNoIniStillCleansTheExampleTierModules(): void
    {
        $this->make($this->bundled, 'HelloWorld');
        mkdir($this->bundled . 'HelloWorld/assets');
        $this->configure($this->base . '/missing.ini');
        $web = $this->webRoot();
        symlink($this->bundled . 'HelloWorld/assets', $web . '/assets/helloworld');

        $this->assertTrue(Modules::dropDisabledAssetLinksWhenChanged($this->base . '/cache/marker', $web));
        $this->assertFalse(is_link($web . '/assets/helloworld'));
    }

    public function testIniFileIsExposed(): void
    {
        $this->configure($this->ini);
        $this->assertSame($this->ini, Modules::iniFile());
    }

    public function testADisabledModuleThatIsASymlinkToAParentDirectoryDropsNothing(): void
    {
        $this->make($this->site, 'Real', 'Other');
        mkdir($this->site . 'Other/assets');
        symlink($this->base, $this->site . 'Gone');
        $this->writeIni("[enabled]\nGone = off\n");
        $this->configure($this->ini);
        $web = $this->webRoot();
        symlink($this->site . 'Other/assets', $web . '/assets/other');

        Modules::dropDisabledAssetLinks($web);

        $this->assertTrue(is_link($web . '/assets/other'));
    }
}
