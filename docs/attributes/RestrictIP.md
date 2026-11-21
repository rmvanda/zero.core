# RestrictIP

**Class:** `Zero\Core\Attribute\RestrictIP` (`core/attribute/RestrictIP.php`)

Restricts an endpoint, or a whole class, to an allow-list of client IP
addresses and CIDR ranges. Any other client gets a `403`.

## Usage

```php
use Zero\Core\Attribute\RestrictIP;

#[RestrictIP('203.0.113.5')]
public function adminOnly() {}

#[RestrictIP('10.0.0.0/8')]
public function internal() {}

// Plain IPs and CIDR ranges, IPv4 and IPv6
#[RestrictIP(['203.0.113.5', '198.51.100.0/24', '2001:db8::/32'])]
public function restricted() {}

// Class level: every endpoint
#[RestrictIP('10.0.0.0/8')]
class Ops extends Module {}
```

## Parameters

- `$allowed` (string|array): one entry or a list. An entry is a plain address
  (`'203.0.113.5'`, `'2001:db8::1'`) or a CIDR range (`'203.0.113.0/24'`,
  `'2001:db8::/32'`). Entries are trimmed; empty and non-string entries are
  dropped. An empty list, or an entry that is not a valid address or range,
  matches nobody.

## Client IP

The client IP is `Request::clientIp()`:

- The peer (`REMOTE_ADDR`) is a Cloudflare edge (`Zero\Lib\Net\TrustedProxy`):
  `CF-Connecting-IP`, else the first hop of `X-Forwarded-For`, else the peer.
- Any other peer: the peer itself. Forwarding headers from a client that
  connects straight to the origin are not believed.

Before matching, the attribute requires `Request::clientIpIsVerified()` and
refuses when it is false: there is no peer address, or the peer is a Cloudflare
edge that sent no `CF-Connecting-IP`. An address taken from `X-Forwarded-For`
alone is client-supplied, so it is never used to let someone in.

## Matching

`Zero\Lib\Net\Cidr::matches()`, on the binary form of the address (`inet_pton`):

- Equivalent spellings of an address compare equal.
- IPv4 never matches IPv6, and the reverse.
- CIDR matching is bit-exact, including prefixes that end mid-byte (`/20`).
- `/0` matches every address of the same family.

## Refusal

| Case | `HTTPError` | Logged at warn |
|---|---|---|
| Client IP not verified | `403` `Access denied` | `RestrictIP denied: client IP could not be verified` |
| Client IP not on the list | `403` `Access denied for IP <ip>` | `RestrictIP blocked request from '<ip>' (allowed: <list>)` |

## Class level and method level

A method-level `#[RestrictIP]` replaces the class-level one for that endpoint;
the two lists are not merged. To widen one endpoint, repeat the class's entries:

```php
#[RestrictIP('10.0.0.0/8')]
class Ops extends Module {

    public function status() {}                    // 10.0.0.0/8

    #[RestrictIP(['10.0.0.0/8', '203.0.113.5'])]   // this list only
    public function report() {}
}
```

See [../ATTRIBUTES.md](../ATTRIBUTES.md#class-level-and-method-level).

Matcher tests: `core/tests/CidrTest.php`
