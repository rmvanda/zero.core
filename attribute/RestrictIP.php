<?php

namespace Zero\Core\Attribute;

use \Attribute;
use \Zero\Core\Console;
use \Zero\Core\HTTPError;

#[Attribute]
class RestrictIP {

    /** @var string[] Allowed IPs / CIDR ranges (IPv4 and IPv6) */
    private array $allowed;

    /**
     * RestrictIP constructor
     *
     * @param string|array $allowed A single IP/CIDR or an array of them.
     *        Accepts plain addresses ('203.0.113.5', '2001:db8::1') and
     *        CIDR ranges ('203.0.113.0/24', '2001:db8::/32').
     */
    public function __construct(string|array $allowed) {
        // Normalize to array, drop empty entries.
        $this->allowed = array_values(array_filter(
            is_array($allowed) ? $allowed : [$allowed],
            fn($entry) => is_string($entry) && trim($entry) !== ''
        ));
    }

    /**
     * Deny the request with a 403 if the client IP is not in the allow-list.
     *
     * @return bool True if the client IP is allowed.
     * @throws HTTPError 403 if the client IP does not match any allowed entry.
     */
    public function handler(): bool {
        $clientIp = $this->clientIp();

        foreach ($this->allowed as $entry) {
            if ($this->matches($clientIp, trim($entry))) {
                return true;
            }
        }

        Console::warn("RestrictIP blocked request from '{$clientIp}' (allowed: " . implode(', ', $this->allowed) . ")");
        throw new HTTPError(403, "Access denied for IP {$clientIp}");
    }

    /**
     * Resolve the client IP.
     *
     * Mirrors the precedence used by Application::banmotherfuckers(): behind
     * Cloudflare, CF-Connecting-IP is the true, Cloudflare-verified client IP
     * (REMOTE_ADDR is only Cloudflare's edge address). X-Forwarded-For is a
     * spoofable chain, so we take only its first hop as a fallback, and finally
     * fall back to REMOTE_ADDR for direct (non-proxied) requests.
     *
     * @return string
     */
    private function clientIp(): string {
        $ip = $_SERVER['HTTP_CF_CONNECTING_IP']
            ?? explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '')[0]
            ?? $_SERVER['REMOTE_ADDR']
            ?? '';

        return trim($ip);
    }

    /**
     * Does $ip match a single allow-list entry (exact IP or CIDR range)?
     *
     * @param string $ip    The client IP.
     * @param string $entry An allow-list entry — plain IP or CIDR.
     * @return bool
     */
    private function matches(string $ip, string $entry): bool {
        return str_contains($entry, '/')
            ? $this->inCidr($ip, $entry)
            : $this->sameIp($ip, $entry);
    }

    /**
     * Exact IP comparison via binary normalization, so equivalent textual
     * forms (e.g. '::1' vs '0:0:0:0:0:0:0:1') compare equal.
     *
     * @param string $ip
     * @param string $candidate
     * @return bool
     */
    private function sameIp(string $ip, string $candidate): bool {
        $a = @inet_pton($ip);
        $b = @inet_pton($candidate);

        return $a !== false && $b !== false && $a === $b;
    }

    /**
     * Is $ip inside the CIDR range $cidr? Handles both IPv4 and IPv6 and
     * refuses to match across address families.
     *
     * @param string $ip
     * @param string $cidr e.g. '10.0.0.0/8' or '2001:db8::/32'
     * @return bool
     */
    private function inCidr(string $ip, string $cidr): bool {
        [$subnet, $bits] = explode('/', $cidr, 2);

        $ipBin     = @inet_pton($ip);
        $subnetBin = @inet_pton($subnet);

        // Malformed input, or IP and subnet are different families (4 vs 16 bytes).
        if ($ipBin === false || $subnetBin === false || strlen($ipBin) !== strlen($subnetBin)) {
            return false;
        }

        if (!ctype_digit((string)$bits)) {
            return false;
        }

        $bits    = (int)$bits;
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
