<?php

namespace Zero\Core;

/**
 * IP address and CIDR range matching.
 *
 * Extracted verbatim from Zero\Core\Attribute\RestrictIP, which had the only
 * copy. It now has two consumers with very different stakes — RestrictIP makes
 * an authorisation decision with it, and Request::clientIp() decides whether to
 * believe a forwarding header — so it lives in one place rather than two.
 *
 * Everything is done on the binary form via inet_pton, so equivalent textual
 * spellings compare equal and IPv4 can never accidentally match IPv6.
 *
 * @author James Pope
 */
class Cidr
{
    /**
     * Does $ip match $entry, which may be a plain IP or a CIDR range?
     *
     * @param string $ip    The address under test.
     * @param string $entry A plain IP ('10.0.0.1') or CIDR ('10.0.0.0/8').
     */
    public static function matches(string $ip, string $entry): bool
    {
        return str_contains($entry, '/')
            ? self::inCidr($ip, $entry)
            : self::sameIp($ip, $entry);
    }

    /** Does $ip match any entry in the list? */
    public static function matchesAny(string $ip, array $entries): bool
    {
        foreach ($entries as $entry) {
            if (self::matches($ip, (string) $entry)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Exact IP comparison via binary normalization, so equivalent textual
     * forms (e.g. '::1' vs '0:0:0:0:0:0:0:1') compare equal.
     */
    public static function sameIp(string $ip, string $candidate): bool
    {
        $a = @inet_pton($ip);
        $b = @inet_pton($candidate);

        return $a !== false && $b !== false && $a === $b;
    }

    /**
     * Is $ip inside the CIDR range $cidr? Handles both IPv4 and IPv6 and
     * refuses to match across address families.
     *
     * @param string $cidr e.g. '10.0.0.0/8' or '2001:db8::/32'
     */
    public static function inCidr(string $ip, string $cidr): bool
    {
        if (!str_contains($cidr, '/')) {
            return false;
        }

        [$subnet, $bits] = explode('/', $cidr, 2);

        $ipBin     = @inet_pton($ip);
        $subnetBin = @inet_pton($subnet);

        // Malformed input, or IP and subnet are different families (4 vs 16 bytes).
        if ($ipBin === false || $subnetBin === false || strlen($ipBin) !== strlen($subnetBin)) {
            return false;
        }

        if (!ctype_digit((string) $bits)) {
            return false;
        }

        $bits    = (int) $bits;
        $maxBits = strlen($ipBin) * 8; // 32 for IPv4, 128 for IPv6
        if ($bits < 0 || $bits > $maxBits) {
            return false;
        }

        // A /0 matches everything of the same family.
        if ($bits === 0) {
            return true;
        }

        // Compare the whole bytes covered by the prefix.
        $wholeBytes = intdiv($bits, 8);
        if ($wholeBytes > 0 && substr($ipBin, 0, $wholeBytes) !== substr($subnetBin, 0, $wholeBytes)) {
            return false;
        }

        // Compare the remaining partial byte, if any, under a bit mask.
        $remainderBits = $bits % 8;
        if ($remainderBits === 0) {
            return true;
        }

        $mask    = 0xff << (8 - $remainderBits) & 0xff;
        $ipByte  = ord($ipBin[$wholeBytes]);
        $netByte = ord($subnetBin[$wholeBytes]);

        return ($ipByte & $mask) === ($netByte & $mask);
    }
}
