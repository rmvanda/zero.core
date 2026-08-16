<?php

namespace Zero\Tests\Core\Attribute;

use Zero\Tests\Core\ZeroTestCase;
use Zero\Core\Attribute\RequireLogin;
use Zero\Core\User;

/**
 * handler() now gates on User::isLoggedIn() instead of duplicating its own
 * `isset($_SESSION['user_id'])` predicate (see the docblock on handler()
 * for why). The admitted case is a normal in-process call.
 *
 * The denied case redirects via header()+exit() (redirectToLogin(), a
 * private method — not mockable), which would kill the PHPUnit process
 * running this test rather than fail it. RequirePermissionTest documents
 * the identical problem for RequirePermission's redirect and, at the time
 * it was written, left the denial path untested for exactly that reason.
 *
 * This is precisely the case fixtures/require_login_probe.php exists for:
 * it runs RequireLogin::handler() in its own PHP process (via proc_open
 * below), so exit() only ends that child, and shadows header() (namespace
 * function fallback — see the probe script) to report the Location it was
 * given instead of letting it disappear into a real HTTP response. That is
 * the "observable effect instead of a return value" this suite needed to
 * pin the behaviour change without duplicating isLoggedIn()'s logic here.
 */
class RequireLoginTest extends ZeroTestCase
{
    private const PROBE = __DIR__ . '/fixtures/require_login_probe.php';

    protected function tearDown(): void
    {
        $prop = new \ReflectionProperty(User::class, 'current');
        $prop->setAccessible(true);
        $prop->setValue(null, null);

        parent::tearDown();
    }

    /**
     * Runs the probe script in a fresh OS process with the given $_SESSION
     * shape (null = no session at all) and returns its single line of
     * stdout, trimmed. See fixtures/require_login_probe.php for the output
     * contract.
     */
    private function probe(?array $session): string
    {
        $arg = $session === null ? 'null' : json_encode($session);

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open(
            [PHP_BINARY, self::PROBE, $arg],
            $descriptors,
            $pipes,
            dirname(__DIR__, 3) // zero root, for consistency with a real request's cwd
        );

        $this->assertIsResource($process, 'Failed to spawn the RequireLogin probe process');

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        $this->assertSame(0, $exitCode, "Probe exited abnormally (code {$exitCode}). stderr:\n{$stderr}");

        return trim($stdout);
    }

    /* ── Admitted ── */

    public function testAdmitsSessionWithUserIdEmailAndVerified(): void
    {
        $this->assertSame('APPROVED', $this->probe([
            'user_id'  => 1,
            'email'    => 'a@example.invalid',
            'verified' => 1,
        ]));
    }

    /* ── Denied ── */

    /**
     * The behaviour change: before this change, a session with a user_id but
     * verified=0 passed the old `isset($_SESSION['user_id'])` check and was
     * admitted, even though User::isLoggedIn() already said no. That is the
     * exact shape AllowWithToken::handler() can produce, since it calls
     * User::establish() without checking verified. Gating on isLoggedIn()
     * closes that gap — this must deny.
     */
    public function testDeniesSessionWithUserIdAndEmailButUnverified(): void
    {
        $this->assertSame('HEADER:Location: /user/login?r=y', $this->probe([
            'user_id'  => 1,
            'email'    => 'a@example.invalid',
            'verified' => 0,
        ]));
    }

    public function testDeniesSessionWithUserIdOnlyNoEmail(): void
    {
        $this->assertSame('HEADER:Location: /user/login?r=y', $this->probe([
            'user_id' => 1,
        ]));
    }

    public function testDeniesWhenThereIsNoSessionAtAll(): void
    {
        $this->assertSame('HEADER:Location: /user/login?r=y', $this->probe(null));
    }

    /**
     * Only the nested $_SESSION['user'][...] shape is present, no flat keys.
     * isLoggedIn() reads only the flat keys, so this must deny even though a
     * reader skimming $_SESSION might see a "user" and assume logged in.
     */
    public function testDeniesSessionUserOnlyShapeWithoutFlatKeys(): void
    {
        $this->assertSame('HEADER:Location: /user/login?r=y', $this->probe([
            'user' => [
                'id'       => 1,
                'email'    => 'a@example.invalid',
                'verified' => 1,
            ],
        ]));
    }

    /* ── In-process sanity check of the same predicate handler() now uses ── */

    /**
     * handler() is documented to call User::isLoggedIn() rather than
     * reimplement it. This confirms handler()'s own decision — read via
     * $approved, without going through the redirect — matches
     * isLoggedIn()'s answer for the admitted case, in-process where a
     * regular assertion can observe it directly.
     */
    public function testApprovedFlagAgreesWithIsLoggedIn(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
        $this->loginUser(['verified' => 1]);

        $this->assertTrue(User::isLoggedIn());

        $attr = new RequireLogin();
        $this->assertTrue($attr->handler());
        $this->assertTrue($attr->approved);
    }
}
