<?php

namespace Zero\Tests\Core;

use Zero\Core\Modules;
use Zero\Core\Request;
use Zero\Core\Response;

/**
 * Response::adoptFrameFrom() — cross-request frame inheritance.
 *
 * A shared module — one reachable from anywhere, that should look like part of
 * whatever sent the visitor to it — borrows the referring module's frame. The
 * frame is derived from the referring URL, so it is scoped to one handoff
 * rather than to the browser, and no state is written anywhere.
 *
 * These tests use a throwaway module root: Fixone and Fixtwo have a frame/
 * directory, Fixplain has none, and core's own Auth and AccessRequest have
 * none either. The method's job is to answer "is this a module with a frame".
 */
final class AdoptFrameTest extends ZeroTestCase
{
    /** Probe: Response with its constructor and destructor disarmed. */
    private function probe(): object
    {
        return new class extends Response {
            // Response::__construct() calls defineBasePaths() and
            // registerAutoloaders(), neither of which this method needs, and
            // both of which want a routed request. Skip them.
            public function __construct() {}

            // Response::__destruct() renders the page when nothing else has.
            // Left alone it would emit a frame into the middle of the test run.
            public function __destruct() {}

            public function probeAdopt(?string $url): bool
            {
                return $this->adoptFrameFrom($url);
            }

            public function frame(): ?string
            {
                return $this->framePath ?? null;
            }
        };
    }

    private string $backupDomain;
    private string $mods = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->mods = sys_get_temp_dir() . '/zerotest-adopt-' . getmypid() . '/';
        foreach (['Fixone/frame', 'Fixtwo/frame', 'Fixplain'] as $dir) {
            mkdir($this->mods . $dir, 0777, true);
        }
        Modules::configure([$this->mods, dirname(__DIR__, 2) . '/core/modules/'], null);

        $this->backupDomain = Request::$domain ?? '';
        Request::$domain = 'example.test';
    }

    protected function tearDown(): void
    {
        Request::$domain = $this->backupDomain;
        exec('rm -rf ' . escapeshellarg($this->mods));
        zeroTestConfigureModules();
        parent::tearDown();
    }

    /* ── Accepts ── */

    public function testAdoptsFrameOfReferringModule(): void
    {
        $p = $this->probe();

        $this->assertTrue($p->probeAdopt('https://example.test/fixone/campaigns'));
        $this->assertSame($this->mods . 'Fixone/frame/', $p->frame());
    }

    public function testAdoptsParentFrameFromSubmodulePath(): void
    {
        // The first segment is the module; Module::defineBasePaths() already
        // walks a submodule up to its parent's frame, so taking the first
        // segment lands on the same directory the submodule would have used.
        $p = $this->probe();

        $this->assertTrue($p->probeAdopt('https://example.test/fixone/characters/42'));
        $this->assertSame($this->mods . 'Fixone/frame/', $p->frame());
    }

    public function testModuleSegmentIsCaseInsensitive(): void
    {
        // Request::$module lowercases the segment "to normalize /BATSHIT/ReQuEsTs".
        // A Referer carrying that URL has to resolve the same way routing did.
        $p = $this->probe();

        $this->assertTrue($p->probeAdopt('https://example.test/FIXONE/campaigns'));
        $this->assertSame($this->mods . 'Fixone/frame/', $p->frame());
    }

    public function testAcceptsRefererWithQueryAndFragment(): void
    {
        $p = $this->probe();

        $this->assertTrue($p->probeAdopt('https://example.test/fixtwo/page?id=7#top'));
        $this->assertSame($this->mods . 'Fixtwo/frame/', $p->frame());
    }

    public function testAcceptsHostWithPort(): void
    {
        // Request::$domain is HTTP_HOST verbatim, which carries a port when one
        // is in the URL. Comparing hosts rather than authorities keeps a
        // non-default port from reading as a different site.
        $p = $this->probe();
        Request::$domain = 'example.test:8443';

        $this->assertTrue($p->probeAdopt('https://example.test:8443/fixone/'));
        $this->assertSame($this->mods . 'Fixone/frame/', $p->frame());
    }

    /* ── Rejects, leaving the resolved frame alone ── */

    public function testRejectsModuleWithoutFrameDirectory(): void
    {
        $p = $this->probe();

        $this->assertFalse($p->probeAdopt('https://example.test/fixplain/x'));
        $this->assertNull($p->frame());
    }

    public function testRejectsUnknownModule(): void
    {
        $p = $this->probe();

        $this->assertFalse($p->probeAdopt('https://example.test/nosuchmodule/x'));
        $this->assertNull($p->frame());
    }

    public function testRejectsForeignHost(): void
    {
        // Referer is client-controlled. Without this an off-site page could
        // choose which of our frames renders around our own content.
        $p = $this->probe();

        $this->assertFalse($p->probeAdopt('https://evil.example/fixone/campaigns'));
        $this->assertNull($p->frame());
    }

    public function testRejectsHostThatMerelyEndsWithOurDomain(): void
    {
        $p = $this->probe();

        $this->assertFalse($p->probeAdopt('https://notexample.test/fixone/'));
        $this->assertFalse($p->probeAdopt('https://example.test.evil.example/fixone/'));
        $this->assertNull($p->frame());
    }

    public function testRejectsTraversal(): void
    {
        $p = $this->probe();

        $this->assertFalse($p->probeAdopt('https://example.test/../../etc/passwd'));
        $this->assertFalse($p->probeAdopt('https://example.test/%2e%2e%2f%2e%2e/etc'));
        $this->assertFalse($p->probeAdopt('https://example.test/./fixone/'));
        $this->assertNull($p->frame());
    }

    public function testRejectsSegmentWithPathSeparatorOrNullByte(): void
    {
        $p = $this->probe();

        $this->assertFalse($p->probeAdopt("https://example.test/fixone\0/x"));
        $this->assertFalse($p->probeAdopt('https://example.test/fixone%00/x'));
        $this->assertNull($p->frame());
    }

    public function testRejectsRootAndEmptyPaths(): void
    {
        $p = $this->probe();

        $this->assertFalse($p->probeAdopt('https://example.test/'));
        $this->assertFalse($p->probeAdopt('https://example.test'));
        $this->assertNull($p->frame());
    }

    public function testRejectsMissingReferer(): void
    {
        // The common case, not an edge case: direct navigation, a bookmark, or
        // a client that strips the header. Falling through to the module's own
        // resolved frame is the correct outcome, so this must be quiet.
        $p = $this->probe();

        $this->assertFalse($p->probeAdopt(null));
        $this->assertFalse($p->probeAdopt(''));
        $this->assertNull($p->frame());
    }

    public function testRejectsMalformedUrl(): void
    {
        $p = $this->probe();

        $this->assertFalse($p->probeAdopt('not a url at all'));
        $this->assertFalse($p->probeAdopt('http://'));
        $this->assertFalse($p->probeAdopt('/fixone/campaigns'));   // no host to verify
        $this->assertNull($p->frame());
    }

    public function testKebabModuleResolvesThroughTheRoutingConversion(): void
    {
        // AccessRequest is reached at /access-request/, so the segment has to go
        // through the same dash-to-camel conversion Request applies before it
        // becomes a directory name. It has no frame/ today, so the answer is
        // false either way — what this pins is that the conversion does not
        // throw and does not resolve to something outside modules/.
        $p = $this->probe();

        $this->assertFalse($p->probeAdopt('https://example.test/access-request/permission'));
        $this->assertNull($p->frame());
    }

    /* ── Does not touch the session ── */

    public function testWritesNothingToTheSession(): void
    {
        $_SESSION = [];
        $p = $this->probe();

        $p->probeAdopt('https://example.test/fixone/campaigns');

        $this->assertSame([], $_SESSION,
            'adoptFrameFrom() exists to remove the per-request session write; it must not add one back');
    }
}
