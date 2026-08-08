<?php

namespace Zero\Tests\Core\Attribute;

use Zero\Tests\Core\ZeroTestCase;
use Zero\Core\Attribute\RestrictIP;
use Zero\Core\HTTPError;

class RestrictIPTest extends ZeroTestCase
{
    /** A genuine Cloudflare edge address (inside the published 172.64.0.0/13). */
    private const CF_EDGE = '172.68.195.148';

    private function setClientIp(string $ip): void
    {
        // The peer must be a REAL Cloudflare range, not an arbitrary stand-in.
        // Request::clientIp() now believes CF-Connecting-IP only from a verified
        // edge, so the previous placeholder (198.51.100.1, which is TEST-NET-2
        // documentation space) is no longer trusted and the header would be
        // ignored in favour of the peer itself.
        //
        // That silent substitution is worth understanding: with the old
        // placeholder, testThrows403ForUnlistedIp stopped throwing at all,
        // because the resolved IP became 198.51.100.1 — which happens to fall
        // inside that test's own 198.51.100.0/24 allow-list.
        unset($_SERVER['HTTP_X_FORWARDED_FOR']);
        $_SERVER['HTTP_CF_CONNECTING_IP'] = $ip;
        $_SERVER['REMOTE_ADDR'] = self::CF_EDGE;
    }

    protected function tearDown(): void
    {
        unset(
            $_SERVER['HTTP_CF_CONNECTING_IP'],
            $_SERVER['HTTP_X_FORWARDED_FOR'],
            $_SERVER['REMOTE_ADDR']
        );
        parent::tearDown();
    }

    /* ── Exact IP matches pass ── */

    public function testAllowsExactIpMatch(): void
    {
        $this->setClientIp('203.0.113.5');

        $attr = new RestrictIP('203.0.113.5');
        $this->assertTrue($attr->handler());
    }

    public function testAllowsIpFromArray(): void
    {
        $this->setClientIp('198.51.100.20');

        $attr = new RestrictIP(['203.0.113.5', '198.51.100.20']);
        $this->assertTrue($attr->handler());
    }

    public function testAllowsExactIpv6Match(): void
    {
        $this->setClientIp('2001:db8::1');

        $attr = new RestrictIP('2001:db8:0:0:0:0:0:1'); // equivalent textual form
        $this->assertTrue($attr->handler());
    }

    /* ── CIDR ranges ── */

    public function testAllowsIpv4InsideCidr(): void
    {
        $this->setClientIp('10.4.2.99');

        $attr = new RestrictIP('10.0.0.0/8');
        $this->assertTrue($attr->handler());
    }

    public function testAllowsPartialBytePrefix(): void
    {
        // 198.51.100.0/20 covers 198.51.96.0 – 198.51.111.255
        $this->setClientIp('198.51.104.7');

        $attr = new RestrictIP('198.51.100.0/20');
        $this->assertTrue($attr->handler());
    }

    public function testAllowsIpv6InsideCidr(): void
    {
        $this->setClientIp('2001:db8:abcd::42');

        $attr = new RestrictIP('2001:db8::/32');
        $this->assertTrue($attr->handler());
    }

    public function testSlashZeroMatchesSameFamily(): void
    {
        $this->setClientIp('8.8.8.8');

        $attr = new RestrictIP('0.0.0.0/0');
        $this->assertTrue($attr->handler());
    }

    /* ── Denied requests throw 403 ── */

    public function testThrows403ForUnlistedIp(): void
    {
        $this->setClientIp('192.0.2.99');

        $attr = new RestrictIP(['203.0.113.5', '198.51.100.0/24']);

        try {
            $attr->handler();
            $this->fail('Expected HTTPError was not thrown');
        } catch (HTTPError $e) {
            $this->assertSame(403, $e->getCode());
            $this->assertStringContainsString('192.0.2.99', $e->detail);
        }
    }

    public function testThrows403ForIpOutsideCidr(): void
    {
        $this->setClientIp('10.4.2.99');

        $attr = new RestrictIP('10.0.0.0/24'); // client is outside this /24

        $this->expectException(HTTPError::class);
        $this->expectExceptionCode(403);
        $attr->handler();
    }

    public function testDoesNotMatchAcrossAddressFamilies(): void
    {
        // IPv6 client must not match an IPv4 range.
        $this->setClientIp('2001:db8::1');

        $attr = new RestrictIP('0.0.0.0/0');

        $this->expectException(HTTPError::class);
        $this->expectExceptionCode(403);
        $attr->handler();
    }

    /* ── Client IP resolution precedence ── */

    public function testDeniesWhenOnlyXForwardedForIsPresent(): void
    {
        // BEHAVIOUR CHANGE, deliberate. This previously asserted that RestrictIP
        // honoured the first X-Forwarded-For hop. It no longer does, and must not:
        // XFF is a client-supplied chain, not something the proxy vouches for, so
        // it is acceptable for rate-limit attribution and unacceptable for an
        // access decision. RestrictIP now fails closed on it — see
        // Request::clientIpIsVerified().
        unset($_SERVER['HTTP_CF_CONNECTING_IP']);
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.5, 70.41.3.18';
        $_SERVER['REMOTE_ADDR'] = self::CF_EDGE;

        $attr = new RestrictIP('203.0.113.5');

        $this->expectException(HTTPError::class);
        $this->expectExceptionCode(403);
        $attr->handler();
    }

    public function testDeniesAForgedHeaderFromAnUntrustedPeer(): void
    {
        // The hole this closed: the origin accepts *:443 with no edge
        // restriction, so anyone reaching it directly could previously claim any
        // address and satisfy the allow-list with a header they chose themselves.
        $_SERVER['HTTP_CF_CONNECTING_IP'] = '203.0.113.5';   // the allowed address
        unset($_SERVER['HTTP_X_FORWARDED_FOR']);
        $_SERVER['REMOTE_ADDR'] = '192.0.2.66';              // ...but not via Cloudflare

        $attr = new RestrictIP('203.0.113.5');

        $this->expectException(HTTPError::class);
        $this->expectExceptionCode(403);
        $attr->handler();
    }

    public function testAllowsADirectConnectionOnItsOwnAddress(): void
    {
        // No proxy in front: the peer IS the client, settled by the TCP
        // handshake, so it is both usable and verified.
        unset($_SERVER['HTTP_CF_CONNECTING_IP'], $_SERVER['HTTP_X_FORWARDED_FOR']);
        $_SERVER['REMOTE_ADDR'] = '192.168.1.211';

        $attr = new RestrictIP('192.168.1.0/24');
        $this->assertTrue($attr->handler());
    }
}
