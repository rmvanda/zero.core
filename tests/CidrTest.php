<?php
namespace Zero\Tests\Core;

use Zero\Core\Cidr;

/**
 * Zero\Core\Cidr decides two things with very different stakes: whether a client
 * is on an allow-list (RestrictIP), and whether a peer may tell us who the real
 * client is (Request::clientIp). A false positive here hands away either.
 */
class CidrTest extends ZeroTestCase
{
    public function testExactIpv4Match(): void
    {
        $this->assertTrue(Cidr::matches('203.0.113.5', '203.0.113.5'));
        $this->assertFalse(Cidr::matches('203.0.113.6', '203.0.113.5'));
    }

    public function testEquivalentIpv6SpellingsCompareEqual(): void
    {
        // Comparison is on the binary form, so these are the same address.
        $this->assertTrue(Cidr::matches('::1', '0:0:0:0:0:0:0:1'));
    }

    public function testIpv4CidrBoundaries(): void
    {
        $this->assertTrue(Cidr::matches('172.64.0.0', '172.64.0.0/13'));
        $this->assertTrue(Cidr::matches('172.68.195.148', '172.64.0.0/13'));
        $this->assertTrue(Cidr::matches('172.71.255.255', '172.64.0.0/13'));

        // One address either side of the range.
        $this->assertFalse(Cidr::matches('172.63.255.255', '172.64.0.0/13'));
        $this->assertFalse(Cidr::matches('172.72.0.0', '172.64.0.0/13'));
    }

    public function testIpv6Cidr(): void
    {
        $this->assertTrue(Cidr::matches('2606:4700::1', '2606:4700::/32'));
        $this->assertFalse(Cidr::matches('2606:4701::1', '2606:4700::/32'));
    }

    public function testNeverMatchesAcrossAddressFamilies(): void
    {
        // A /0 matches everything OF THE SAME FAMILY, and nothing outside it.
        $this->assertFalse(Cidr::matches('203.0.113.5', '::/0'));
        $this->assertFalse(Cidr::matches('2606:4700::1', '0.0.0.0/0'));
    }

    public function testZeroPrefixMatchesEverythingInFamily(): void
    {
        $this->assertTrue(Cidr::matches('203.0.113.5', '0.0.0.0/0'));
        $this->assertTrue(Cidr::matches('2606:4700::1', '::/0'));
    }

    public function testMalformedInputNeverMatches(): void
    {
        // Every one of these must fail closed rather than throw or match.
        $this->assertFalse(Cidr::matches('not-an-ip', '203.0.113.0/24'));
        $this->assertFalse(Cidr::matches('203.0.113.5', 'not-a-range/24'));
        $this->assertFalse(Cidr::matches('203.0.113.5', '203.0.113.0/abc'));
        $this->assertFalse(Cidr::matches('203.0.113.5', '203.0.113.0/33'));
        $this->assertFalse(Cidr::matches('203.0.113.5', '203.0.113.0/-1'));
        $this->assertFalse(Cidr::matches('', '203.0.113.0/24'));
        $this->assertFalse(Cidr::matches('203.0.113.5', ''));
    }

    public function testMatchesAnyStopsAtTheFirstHit(): void
    {
        $this->assertTrue(Cidr::matchesAny('172.68.195.148', ['10.0.0.0/8', '172.64.0.0/13']));
        $this->assertFalse(Cidr::matchesAny('203.0.113.5', ['10.0.0.0/8', '172.64.0.0/13']));
        $this->assertFalse(Cidr::matchesAny('203.0.113.5', []));
    }
}
