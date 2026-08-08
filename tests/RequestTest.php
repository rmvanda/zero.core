<?php

namespace Zero\Tests\Core;

use Zero\Core\Request;

class RequestTest extends ZeroTestCase
{
    /**
     * Helper: construct a Request with the given URI and return the static state.
     */
    private function parseRequest(
        string $uri,
        string $method = 'GET',
        string $host = 'unisolu.com',
        bool $https = true,
        bool $ajax = false,
        ?string $accept = null,
    ): void {
        $this->simulateRequest($uri, $method, $host, $https, $ajax, $accept);
        new Request();
    }

    /* ── URI Parsing ── */

    public function testBasicModuleAndEndpoint(): void
    {
        $this->parseRequest('/auth/login');

        $this->assertSame('auth', Request::$module);
        $this->assertSame('login', Request::$endpoint);
        $this->assertSame('Auth', Request::$Module);
        $this->assertSame([], Request::$args);
    }

    public function testUriWithArguments(): void
    {
        $this->parseRequest('/blog/view/42/comments');

        $this->assertSame('blog', Request::$module);
        $this->assertSame('view', Request::$endpoint);
        $this->assertSame(['42', 'comments'], Request::$args);
    }

    public function testModuleOnlyDefaultsEndpointToIndex(): void
    {
        $this->parseRequest('/dashboard');

        $this->assertSame('dashboard', Request::$module);
        $this->assertSame('index', Request::$endpoint);
        $this->assertSame('Dashboard', Request::$Module);
    }

    public function testRootUrlDefaultsToIndexIndex(): void
    {
        $this->parseRequest('/');

        $this->assertSame('index', Request::$module);
        $this->assertSame('index', Request::$endpoint);
    }

    public function testQueryStringStripped(): void
    {
        $this->parseRequest('/search/results?q=hello&page=2');

        $this->assertSame('search', Request::$module);
        $this->assertSame('results', Request::$endpoint);
        $this->assertSame([], Request::$args);
    }

    /* ── Kebab-case to camelCase conversion ── */

    public function testKebabCaseModule(): void
    {
        $this->parseRequest('/user-profile/edit');

        $this->assertSame('userProfile', Request::$module);
        $this->assertSame('UserProfile', Request::$Module);
        $this->assertSame('user-profile', Request::$moduleOrig);
    }

    public function testKebabCaseEndpoint(): void
    {
        $this->parseRequest('/auth/forgot-password');

        $this->assertSame('auth', Request::$module);
        $this->assertSame('forgotPassword', Request::$endpoint);
        $this->assertSame('forgot-password', Request::$endpointOrig);
    }

    public function testKebabCaseModuleAndEndpoint(): void
    {
        $this->parseRequest('/user-profile/change-password');

        $this->assertSame('userProfile', Request::$module);
        $this->assertSame('changePassword', Request::$endpoint);
    }

    public function testMultipleHyphens(): void
    {
        $this->parseRequest('/my-cool-module/do-something-great');

        $this->assertSame('myCoolModule', Request::$module);
        $this->assertSame('doSomethingGreat', Request::$endpoint);
    }

    /* ── Case normalization ── */

    public function testUppercaseModuleNormalized(): void
    {
        $this->parseRequest('/AUTH/LOGIN');

        $this->assertSame('auth', Request::$module);
        $this->assertSame('login', Request::$endpoint);
        $this->assertSame('Auth', Request::$Module);
    }

    public function testMixedCaseNormalized(): void
    {
        $this->parseRequest('/DashBoard/MyEndpoint');

        $this->assertSame('dashboard', Request::$module);
        $this->assertSame('myendpoint', Request::$endpoint);
    }

    /* ── Protocol detection ── */

    public function testHttpsProtocol(): void
    {
        $this->parseRequest('/index/index', 'GET', 'unisolu.com', true);
        $this->assertSame('https', Request::$protocol);
    }

    public function testHttpProtocol(): void
    {
        $this->parseRequest('/index/index', 'GET', 'unisolu.com', false);
        $this->assertSame('http', Request::$protocol);
    }

    /* ── HTTP method ── */

    public function testGetMethod(): void
    {
        $this->parseRequest('/test/index', 'GET');
        $this->assertSame('GET', Request::$method);
    }

    public function testPostMethod(): void
    {
        $this->parseRequest('/test/index', 'POST');
        $this->assertSame('POST', Request::$method);
    }

    public function testDeleteMethod(): void
    {
        $this->parseRequest('/test/index', 'DELETE');
        $this->assertSame('DELETE', Request::$method);
    }

    /* ── Subdomain / domain ── */

    public function testSubdomainParsing(): void
    {
        $this->parseRequest('/index/index', 'GET', 'app.unisolu.com');
        $this->assertSame('app', Request::$sub);
        $this->assertSame('app', Request::$subdomain);
    }

    public function testNoSubdomain(): void
    {
        $this->parseRequest('/index/index', 'GET', 'unisolu.com');
        $this->assertSame('', Request::$sub);
    }

    public function testDomainStored(): void
    {
        $this->parseRequest('/index/index', 'GET', 'app.unisolu.com');
        $this->assertSame('app.unisolu.com', Request::$domain);
    }

    public function testTldExtracted(): void
    {
        $this->parseRequest('/index/index', 'GET', 'unisolu.com');
        $this->assertSame('com', Request::$tld);
    }

    /* ── AJAX detection ── */

    public function testAjaxDetected(): void
    {
        $this->parseRequest('/api/data', 'GET', 'unisolu.com', true, true);
        $this->assertTrue(Request::$madeWithAJAX);
    }

    public function testNonAjaxRequest(): void
    {
        $this->parseRequest('/page/view', 'GET', 'unisolu.com', true, false);
        $this->assertFalse(Request::$madeWithAJAX);
    }

    /* ── Accept header / JSON detection ── */

    public function testAcceptsJsonDetected(): void
    {
        $this->parseRequest('/api/data', 'GET', 'unisolu.com', true, false, 'application/json');
        $this->assertTrue(Request::$acceptsJSON);
    }

    public function testAcceptsJsonInMixedHeader(): void
    {
        $this->parseRequest('/api/data', 'GET', 'unisolu.com', true, false, 'text/html,application/json');
        $this->assertTrue(Request::$acceptsJSON);
    }

    public function testDoesNotAcceptJson(): void
    {
        $this->parseRequest('/page/view', 'GET', 'unisolu.com', true, false, 'text/html');
        $this->assertFalse(Request::$acceptsJSON);
    }

    /* ── URI array structure ── */

    public function testUriArrayContainsAllSegments(): void
    {
        $this->parseRequest('/blog/view/42/comments');

        $this->assertSame(['blog', 'view', '42', 'comments'], Request::$uriArray);
    }

    public function testUriPrefixedWithSlash(): void
    {
        $this->parseRequest('/auth/login');
        $this->assertSame('/auth/login', Request::$uri);
    }

    /* ── Client IP resolution ── */

    public function testClientIpPrefersCloudflareHeader(): void
    {
        $_SERVER['HTTP_CF_CONNECTING_IP'] = '198.51.100.5';
        $_SERVER['HTTP_X_FORWARDED_FOR']  = '203.0.113.1, 70.41.3.18';
        $_SERVER['REMOTE_ADDR']           = '172.68.195.148';
        $this->assertSame('198.51.100.5', Request::clientIp());
    }

    public function testClientIpFallsBackToFirstForwardedHop(): void
    {
        unset($_SERVER['HTTP_CF_CONNECTING_IP']);
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.1, 70.41.3.18';
        $_SERVER['REMOTE_ADDR']          = '172.68.195.148';
        $this->assertSame('203.0.113.1', Request::clientIp());
    }

    public function testForwardingHeadersAreIgnoredFromAnUntrustedPeer(): void
    {
        // THE point of the trust gate. The origin accepts *:443 with no edge
        // restriction and its address is published in this domain's SPF record,
        // so anyone can connect directly and claim to be someone else.
        $_SERVER['REMOTE_ADDR']           = '203.0.113.99';
        $_SERVER['HTTP_CF_CONNECTING_IP'] = '198.51.100.1';
        $_SERVER['HTTP_X_FORWARDED_FOR']  = '198.51.100.2';

        $this->assertSame('203.0.113.99', \Zero\Core\Request::clientIp());
    }

    public function testForwardingHeadersAreBelievedFromACloudflarePeer(): void
    {
        $_SERVER['REMOTE_ADDR']           = '172.68.195.148';   // a real observed CF edge
        $_SERVER['HTTP_CF_CONNECTING_IP'] = '198.51.100.1';
        unset($_SERVER['HTTP_X_FORWARDED_FOR']);

        $this->assertSame('198.51.100.1', \Zero\Core\Request::clientIp());
    }

    public function testAnIpv6CloudflarePeerIsAlsoTrusted(): void
    {
        $_SERVER['REMOTE_ADDR']           = '2606:4700::1';
        $_SERVER['HTTP_CF_CONNECTING_IP'] = '198.51.100.1';
        unset($_SERVER['HTTP_X_FORWARDED_FOR']);

        $this->assertSame('198.51.100.1', \Zero\Core\Request::clientIp());
    }

    public function testDirectConnectionIsConsideredVerified(): void
    {
        // REMOTE_ADDR is settled by the TCP handshake — the one value here an
        // attacker cannot choose.
        unset($_SERVER['HTTP_CF_CONNECTING_IP'], $_SERVER['HTTP_X_FORWARDED_FOR']);
        $_SERVER['REMOTE_ADDR'] = '203.0.113.99';

        $this->assertTrue(\Zero\Core\Request::clientIpIsVerified());
    }

    public function testForwardedForAloneIsNotVerified(): void
    {
        // A trusted peer forwarding only a client-supplied chain: usable for
        // rate-limit attribution, not for an access decision. RestrictIP denies
        // on this.
        $_SERVER['REMOTE_ADDR']          = '172.68.195.148';
        unset($_SERVER['HTTP_CF_CONNECTING_IP']);
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.9';

        $this->assertSame('198.51.100.9', \Zero\Core\Request::clientIp());
        $this->assertFalse(\Zero\Core\Request::clientIpIsVerified());
    }

    public function testClientIpFallsBackToRemoteAddr(): void
    {
        // The regression that made the throttle inert: a ?? chain never reached
        // this branch, because explode(',', '')[0] is '' rather than null.
        unset($_SERVER['HTTP_CF_CONNECTING_IP'], $_SERVER['HTTP_X_FORWARDED_FOR']);
        $_SERVER['REMOTE_ADDR'] = '203.0.113.99';
        $this->assertSame('203.0.113.99', Request::clientIp());
    }
}
