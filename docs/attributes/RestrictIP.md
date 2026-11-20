# RestrictIP

**Namespace:** `\Zero\Core\Attribute\RestrictIP`

**Purpose:** Restrict an endpoint (or an entire module) to a whitelist of client IP addresses or CIDR ranges. Requests from any other IP receive a `403` and execution halts.

## Usage

```php
use Zero\Core\Attribute\RestrictIP;

// Single IP
#[RestrictIP('203.0.113.5')]
public function adminOnly() {
    // Only reachable from 203.0.113.5
}

// CIDR range
#[RestrictIP('10.0.0.0/8')]
public function internal() {
    // Only reachable from the internal 10.x network
}

// Multiple entries — plain IPs and CIDR ranges, IPv4 and IPv6
#[RestrictIP(['203.0.113.5', '198.51.100.0/24', '2001:db8::/32'])]
public function restricted() {
    // Reachable from any listed address / range
}

// Class-level: applies to every endpoint in the module
#[RestrictIP('10.0.0.0/8')]
class Ops extends Module {
    // ...
}
```

## Parameters

- `$allowed` (string|array) — A single IP/CIDR string or an array of them.
  - Plain addresses: `'203.0.113.5'`, `'2001:db8::1'`
  - CIDR ranges: `'203.0.113.0/24'`, `'2001:db8::/32'`
  - Empty / non-string entries are ignored.

## When to Use

- Admin or ops endpoints that should only be reachable from the office / VPN network
- Webhook receivers locked to a provider's published IP ranges
- Internal-only APIs

## Error Response

- HTTP 403 Forbidden
- Detail (on the error page, and as `detail` in JSON): `Access denied for IP <client-ip>`

## Client IP Resolution

The attribute resolves the client IP using the same precedence as `Application::banNefariousRequest()`:

1. `HTTP_CF_CONNECTING_IP` — the Cloudflare-verified client IP (authoritative behind Cloudflare)
2. First hop of `HTTP_X_FORWARDED_FOR` — fallback
3. `REMOTE_ADDR` — direct (non-proxied) requests

> **Security note:** Because the site sits behind Cloudflare, `REMOTE_ADDR` is Cloudflare's edge IP, so `CF-Connecting-IP` is trusted as the real client. This is safe **only** while the origin is not reachable directly — a client able to bypass Cloudflare could spoof `CF-Connecting-IP`. Ensure the origin firewall only accepts traffic from Cloudflare.

## Notes

- IPv4 and IPv6 are both supported. Matches never cross address families (an IPv4 client never matches an IPv6 range and vice-versa).
- Exact IPs are compared after binary normalization (`inet_pton`), so equivalent textual forms compare equal.
- CIDR matching is bit-exact, including partial-byte prefixes (e.g. `/20`).
- A `/0` range matches every address of the same family.

## Override

A method-level `#[RestrictIP]` replaces a class-level `#[RestrictIP]` for that endpoint (standard attribute override semantics). Use a class-level default to lock down a whole module, then relax or tighten specific methods:

```php
#[RestrictIP('10.0.0.0/8')]      // Class default: internal network only
class Ops extends Module {

    public function status() {
        // Inherits the 10.0.0.0/8 restriction
    }

    #[RestrictIP(['10.0.0.0/8', '203.0.113.5'])]  // Also allow one external admin IP
    public function report() {
        // Class-level restriction does NOT apply here — this list replaces it
    }
}
```
