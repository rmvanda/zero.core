<?php

namespace Zero\Core\Attribute;

use \Attribute;
use \Zero\Core\Cidr;
use \Zero\Core\Console;
use \Zero\Core\HTTPError;
use \Zero\Core\Request;

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
        // Fail closed when the client IP cannot be established with confidence.
        // This is an AUTHORISATION decision, so it must refuse rather than
        // guess: the false case is a trusted proxy forwarding only a
        // client-supplied X-Forwarded-For chain, which is fine for rate-limit
        // attribution and not fine for deciding who gets in. See
        // Request::clientIpIsVerified().
        if (!Request::clientIpIsVerified()) {
            Console::warn("RestrictIP denied: client IP could not be verified");
            throw new HTTPError(403, "Access denied");
        }

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
     * Delegates to the shared resolver, which believes CF-Connecting-IP only
     * when the peer is a verified Cloudflare edge and otherwise returns
     * REMOTE_ADDR. That matters most here: this class makes an authorisation
     * decision, and before the trust gate existed the allow-list could be
     * satisfied by a forged header from anyone able to reach the origin
     * directly.
     *
     * @return string
     */
    private function clientIp(): string {
        return Request::clientIp();
    }

    /**
     * Does $ip match a single allow-list entry (exact IP or CIDR range)?
     *
     * Delegates to Zero\Core\Cidr, which is where this logic now lives — it
     * gained a second consumer in Request::clientIp(), which uses it to decide
     * whether a peer is entitled to set forwarding headers. One matcher, so an
     * authorisation check and a trust check cannot drift apart.
     *
     * @param string $ip    The client IP.
     * @param string $entry An allow-list entry — plain IP or CIDR.
     * @return bool
     */
    private function matches(string $ip, string $entry): bool {
        return Cidr::matches($ip, $entry);
    }
}
