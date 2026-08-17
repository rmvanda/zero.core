<?php

namespace Zero\Tests\Core\Attribute;

use Zero\Tests\Core\ZeroTestCase;
use Zero\Core\Attribute\RequireLogin;
use Zero\Core\User;
use PHPUnit\Framework\Attributes\DataProvider;

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
 *
 * The probe also carries the two later additions to the attribute: the
 * optional #[RequireLogin('/some/path')] redirect override (which only the
 * out-of-process probe can observe, since the choice of target is consumed
 * by the same header()+exit()), and the JSON denial path — which throws
 * HTTPError(401) rather than exiting, and so would be testable in-process,
 * but is kept here so every denial case reads the same way.
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
     * shape (null = no session at all) and returns its OUTCOME line, trimmed
     * — what handler() did. See fixtures/require_login_probe.php for the full
     * output contract; the trailing RETURN_URL line it always prints is
     * dropped here and read by probeReturnUrl() instead, so that these
     * assertions stay about the one thing they are each testing.
     *
     * @param string|null $redirect    Passed to the RequireLogin constructor.
     * @param bool        $acceptsJSON Sets Request::$acceptsJSON in the child.
     * @param string|null $requestUri  The URI the denial happens on.
     */
    private function probe(
        ?array $session,
        ?string $redirect = null,
        bool $acceptsJSON = false,
        ?string $requestUri = null
    ): string {
        return $this->probeRaw($session, $redirect, $acceptsJSON, $requestUri)[0] ?? '';
    }

    /**
     * What handler() stored for the post-login bounce, or 'NONE'.
     *
     * REQUEST_URI is attacker-controlled, and Auth::complete()/Auth::token()
     * put this value into a Location header, so what does and does not get
     * stored is a security boundary rather than a convenience.
     */
    private function probeReturnUrl(
        ?array $session,
        ?string $redirect = null,
        bool $acceptsJSON = false,
        ?string $requestUri = null
    ): string {
        $lines = $this->probeRaw($session, $redirect, $acceptsJSON, $requestUri);
        $last  = end($lines) ?: '';

        $this->assertStringStartsWith('RETURN_URL:', $last, "Probe did not report a return_url. Output:\n" . implode("\n", $lines));

        return substr($last, strlen('RETURN_URL:'));
    }

    /** @return string[] Every line the probe printed, trimmed, blanks dropped. */
    private function probeRaw(
        ?array $session,
        ?string $redirect,
        bool $acceptsJSON,
        ?string $requestUri
    ): array {
        $arg = $session === null ? 'null' : json_encode($session);

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open(
            [
                PHP_BINARY,
                self::PROBE,
                $arg,
                $redirect ?? 'null',
                $acceptsJSON ? 'json' : 'html',
                $requestUri ?? '/gated/endpoint',
            ],
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

        return array_values(array_filter(array_map('trim', explode("\n", $stdout)), fn($l) => $l !== ''));
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

    /* ── Optional redirect override ── */

    /**
     * #[RequireLogin('/some/path')] sends anonymous visitors there instead of
     * the login page — for modules that would rather explain what the visitor
     * is signing in for.
     */
    public function testUsesTheSuppliedRedirectInsteadOfTheLoginPage(): void
    {
        $this->assertSame(
            'HEADER:Location: /ttrpg/welcome',
            $this->probe(null, '/ttrpg/welcome')
        );
    }

    /**
     * A redirect target that is not site-relative is ignored and the default
     * login page used. The value comes from source rather than from the
     * request, so this is a typo guard — but a stray absolute URL would still
     * be an open redirect, and falling back is the harmless failure.
     */
    public function testIgnoresANonRelativeRedirectAndFallsBackToLogin(): void
    {
        $this->assertSame(
            'HEADER:Location: /user/login?r=y',
            $this->probe(null, 'https://evil.example/harvest')
        );
    }

    /**
     * ...and "site-relative" is the same predicate the stored return_url uses,
     * not a bare leading slash. #[RequireLogin('//evil.example/x')] passed a
     * str_starts_with($u, '/') check and was emitted verbatim as a Location,
     * which is a protocol-relative URL and an open redirect — from a typo, or
     * from a module author who did not know the difference.
     */
    #[DataProvider('offSiteRedirectOverrides')]
    public function testIgnoresARedirectOverrideThatWouldLeaveTheSite(string $redirect): void
    {
        $this->assertSame(
            'HEADER:Location: /user/login?r=y',
            $this->probe(null, $redirect)
        );
    }

    public static function offSiteRedirectOverrides(): array
    {
        return [
            'scheme relative' => ['//evil.example/harvest'],
            'backslash host'  => ['/\\evil.example/harvest'],
            'tab split'       => ["/\t/evil.example"],
        ];
    }

    /** The override changes only WHERE a denial goes, never WHETHER it denies. */
    public function testRedirectOverrideDoesNotAdmitAnUnverifiedSession(): void
    {
        $this->assertSame(
            'HEADER:Location: /ttrpg/welcome',
            $this->probe([
                'user_id'  => 1,
                'email'    => 'a@example.invalid',
                'verified' => 0,
            ], '/ttrpg/welcome')
        );
    }

    /** A redirect target on an approved request is simply never consulted. */
    public function testRedirectOverrideIsIrrelevantWhenAdmitted(): void
    {
        $this->assertSame('APPROVED', $this->probe([
            'user_id'  => 1,
            'email'    => 'a@example.invalid',
            'verified' => 1,
        ], '/ttrpg/welcome'));
    }

    /* ── JSON callers get a status code, not a login page ── */

    /**
     * An XHR follows a 302 transparently and hands its caller the login page's
     * HTML, which then fails to parse as JSON — the failure surfaces as a
     * parse error rather than "you are not logged in". So a JSON request is
     * denied with HTTPError(401) and no Location header at all.
     */
    public function testDeniesJsonRequestWithA401RatherThanARedirect(): void
    {
        $this->assertSame(
            'HTTPERROR:401:Authentication required',
            $this->probe(null, null, acceptsJSON: true)
        );
    }

    /** The 401 wins over a custom redirect: JSON callers never want a page. */
    public function testJsonDenialIgnoresTheRedirectOverride(): void
    {
        $this->assertSame(
            'HTTPERROR:401:Authentication required',
            $this->probe(null, '/ttrpg/welcome', acceptsJSON: true)
        );
    }

    /** A JSON request from a signed-in visitor is admitted like any other. */
    public function testAdmitsJsonRequestFromAVerifiedSession(): void
    {
        $this->assertSame('APPROVED', $this->probe([
            'user_id'  => 1,
            'email'    => 'a@example.invalid',
            'verified' => 1,
        ], null, acceptsJSON: true));
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

    /* ── What gets stored for the post-login bounce ───────────────────────
     *
     * $_SESSION['return_url'] is written here from REQUEST_URI and read by
     * Auth::complete() and Auth::token(), each of which puts it straight into
     * a Location header. REQUEST_URI is chosen by whoever made the request,
     * so this is the write end of an open-redirect boundary, not bookkeeping.
     */

    public function testStoresTheRequestedPathToReturnTo(): void
    {
        $this->assertSame(
            '/notes/list?tag=a',
            $this->probeReturnUrl(null, requestUri: '/notes/list?tag=a')
        );
    }

    /**
     * The value is dropped rather than rewritten — both consumers already
     * default to '/', so there is nothing for a substitute to buy.
     *
     * No request can currently reach the gate carrying one of these:
     * Request trims leading slashes before routing, so '//evil.example/x'
     * resolves to a module that does not exist and no attribute runs. That is
     * a property of the router, though, not of this code, which is why the
     * guard is here and pinned.
     */
    #[DataProvider('offSiteRequestUris')]
    public function testRefusesToStoreAReturnUrlThatWouldLeaveTheSite(string $uri): void
    {
        $this->assertSame('NONE', $this->probeReturnUrl(null, requestUri: $uri));
    }

    public static function offSiteRequestUris(): array
    {
        return [
            'scheme relative' => ['//evil.example/harvest'],
            // A browser turns \ into / before parsing, so this is the case
            // above. A str_starts_with('/') check admitted it.
            'backslash host'  => ['/\\evil.example/harvest'],
            // Tab is removed by the URL parser rather than encoded.
            'tab split'       => ["/\t/evil.example"],
            'absolute'        => ['https://evil.example/harvest'],
        ];
    }

    /** Denying a redirect target does not stop the denial itself. */
    public function testAnUnsafeReturnUrlStillRedirectsToLogin(): void
    {
        $this->assertSame(
            'HEADER:Location: /user/login?r=y',
            $this->probe(null, requestUri: '//evil.example/harvest')
        );
    }

    /**
     * The JSON path throws before it stores anything. An XHR has no bounce to
     * come back to, and leaving a return_url behind would redirect the NEXT
     * interactive login on that session somewhere the visitor never asked for.
     */
    public function testJsonDenialStoresNoReturnUrl(): void
    {
        $this->assertSame(
            'NONE',
            $this->probeReturnUrl(null, null, acceptsJSON: true, requestUri: '/notes/list')
        );
    }
}
