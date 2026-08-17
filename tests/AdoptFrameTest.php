<?php

namespace Zero\Tests\Core;

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
 * These tests use REAL module directories under modules/ rather than fixtures:
 * the method's whole job is to answer "is this a module with a frame", so a
 * fixture tree would test the fixture rather than the answer. Ttrpg, Notebook
 * and Module have frame/ directories; Auth and AccessRequest do not.
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

    protected function setUp(): void
    {
        parent::setUp();

        // defineConstants() does not run under PHPUnit.
        if (!defined('MODULE_PATH')) {
            define('MODULE_PATH', dirname(__DIR__, 2) . '/modules/');
        }

        $this->backupDomain = Request::$domain ?? '';
        Request::$domain = 'unisolu.com';
    }

    protected function tearDown(): void
    {
        Request::$domain = $this->backupDomain;
        parent::tearDown();
    }

    /* ── Accepts ── */

    public function testAdoptsFrameOfReferringModule(): void
    {
        $p = $this->probe();

        $this->assertTrue($p->probeAdopt('https://unisolu.com/ttrpg/campaigns'));
        $this->assertSame(MODULE_PATH . 'Ttrpg/frame/', $p->frame());
    }

    public function testAdoptsParentFrameFromSubmodulePath(): void
    {
        // The first segment is the module; Module::defineBasePaths() already
        // walks a submodule up to its parent's frame, so taking the first
        // segment lands on the same directory the submodule would have used.
        $p = $this->probe();

        $this->assertTrue($p->probeAdopt('https://unisolu.com/ttrpg/characters/42'));
        $this->assertSame(MODULE_PATH . 'Ttrpg/frame/', $p->frame());
    }

    public function testModuleSegmentIsCaseInsensitive(): void
    {
        // Request::$module lowercases the segment "to normalize /BATSHIT/ReQuEsTs".
        // A Referer carrying that URL has to resolve the same way routing did.
        $p = $this->probe();

        $this->assertTrue($p->probeAdopt('https://unisolu.com/TTRPG/campaigns'));
        $this->assertSame(MODULE_PATH . 'Ttrpg/frame/', $p->frame());
    }

    public function testAcceptsRefererWithQueryAndFragment(): void
    {
        $p = $this->probe();

        $this->assertTrue($p->probeAdopt('https://unisolu.com/notebook/page?id=7#top'));
        $this->assertSame(MODULE_PATH . 'Notebook/frame/', $p->frame());
    }

    public function testAcceptsHostWithPort(): void
    {
        // Request::$domain is HTTP_HOST verbatim, which carries a port when one
        // is in the URL. Comparing hosts rather than authorities keeps a
        // non-default port from reading as a different site.
        $p = $this->probe();
        Request::$domain = 'unisolu.com:8443';

        $this->assertTrue($p->probeAdopt('https://unisolu.com:8443/ttrpg/'));
        $this->assertSame(MODULE_PATH . 'Ttrpg/frame/', $p->frame());
    }

    /* ── Rejects, leaving the resolved frame alone ── */

    public function testRejectsModuleWithoutFrameDirectory(): void
    {
        $p = $this->probe();

        $this->assertFalse($p->probeAdopt('https://unisolu.com/auth/login'));
        $this->assertNull($p->frame());
    }

    public function testRejectsUnknownModule(): void
    {
        $p = $this->probe();

        $this->assertFalse($p->probeAdopt('https://unisolu.com/nosuchmodule/x'));
        $this->assertNull($p->frame());
    }

    public function testRejectsForeignHost(): void
    {
        // Referer is client-controlled. Without this an off-site page could
        // choose which of our frames renders around our own content.
        $p = $this->probe();

        $this->assertFalse($p->probeAdopt('https://evil.example/ttrpg/campaigns'));
        $this->assertNull($p->frame());
    }

    public function testRejectsHostThatMerelyEndsWithOurDomain(): void
    {
        $p = $this->probe();

        $this->assertFalse($p->probeAdopt('https://notunisolu.com/ttrpg/'));
        $this->assertFalse($p->probeAdopt('https://unisolu.com.evil.example/ttrpg/'));
        $this->assertNull($p->frame());
    }

    public function testRejectsTraversal(): void
    {
        $p = $this->probe();

        $this->assertFalse($p->probeAdopt('https://unisolu.com/../../etc/passwd'));
        $this->assertFalse($p->probeAdopt('https://unisolu.com/%2e%2e%2f%2e%2e/etc'));
        $this->assertFalse($p->probeAdopt('https://unisolu.com/./ttrpg/'));
        $this->assertNull($p->frame());
    }

    public function testRejectsSegmentWithPathSeparatorOrNullByte(): void
    {
        $p = $this->probe();

        $this->assertFalse($p->probeAdopt("https://unisolu.com/ttrpg\0/x"));
        $this->assertFalse($p->probeAdopt('https://unisolu.com/ttrpg%00/x'));
        $this->assertNull($p->frame());
    }

    public function testRejectsRootAndEmptyPaths(): void
    {
        $p = $this->probe();

        $this->assertFalse($p->probeAdopt('https://unisolu.com/'));
        $this->assertFalse($p->probeAdopt('https://unisolu.com'));
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
        $this->assertFalse($p->probeAdopt('/ttrpg/campaigns'));   // no host to verify
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

        $this->assertFalse($p->probeAdopt('https://unisolu.com/access-request/permission'));
        $this->assertNull($p->frame());
    }

    /* ── Does not touch the session ── */

    public function testWritesNothingToTheSession(): void
    {
        $_SESSION = [];
        $p = $this->probe();

        $p->probeAdopt('https://unisolu.com/ttrpg/campaigns');

        $this->assertSame([], $_SESSION,
            'adoptFrameFrom() exists to remove the per-request session write; it must not add one back');
    }
}
