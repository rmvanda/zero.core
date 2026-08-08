<?php

namespace Zero\Core;

/**
 * Which peers are entitled to tell us who the real client is.
 *
 * `X-Forwarded-For` and `CF-Connecting-IP` are just request headers — anyone
 * who can open a socket to the origin can set them to anything. They mean
 * something ONLY when the peer that sent them is a proxy we actually put in
 * front of ourselves. This site sits behind Cloudflare, and the origin accepts
 * connections on *:443 with no edge restriction, so without this check a direct
 * connection could claim to be any address it liked — and be rate-limited,
 * banned, or IP-authorised as that address instead of its own.
 *
 * The ranges are compiled in rather than read from a file, deliberately:
 *
 *  - There is no "trust data missing" runtime state, so no fail-open/fail-closed
 *    decision to get subtly wrong under load.
 *  - Changing who we believe becomes a reviewable commit rather than a file that
 *    quietly changed.
 *  - app/.gitignore and modules/.gitignore both match `*config*`, so the obvious
 *    home for a data file would have been untracked.
 *
 * The cost is that a refresh needs a deploy. Cloudflare's published list changes
 * very rarely; `core/bin/refresh-cloudflare-ips.php` fetches the live lists and
 * reports drift against these constants rather than mutating anything.
 *
 * Source: https://www.cloudflare.com/ips-v4 and /ips-v6 (fetched 2026-08-08).
 *
 * @author James Pope
 */
class TrustedProxy
{
    /** @var string[] Cloudflare IPv4 edge ranges. */
    private const CLOUDFLARE_V4 = [
        '173.245.48.0/20',
        '103.21.244.0/22',
        '103.22.200.0/22',
        '103.31.4.0/22',
        '141.101.64.0/18',
        '108.162.192.0/18',
        '190.93.240.0/20',
        '188.114.96.0/20',
        '197.234.240.0/22',
        '198.41.128.0/17',
        '162.158.0.0/15',
        '104.16.0.0/13',
        '104.24.0.0/14',
        '172.64.0.0/13',
        '131.0.72.0/22',
    ];

    /** @var string[] Cloudflare IPv6 edge ranges. */
    private const CLOUDFLARE_V6 = [
        '2400:cb00::/32',
        '2606:4700::/32',
        '2803:f800::/32',
        '2405:b500::/32',
        '2405:8100::/32',
        '2a06:98c0::/29',
        '2c0f:f248::/32',
    ];

    /**
     * Is this peer a Cloudflare edge, and therefore entitled to set
     * CF-Connecting-IP / X-Forwarded-For on our behalf?
     */
    public static function isCloudflare(string $ip): bool
    {
        $ip = trim($ip);

        return $ip !== '' && Cidr::matchesAny($ip, self::ranges());
    }

    /**
     * Every trusted range, IPv4 then IPv6.
     *
     * Public so the refresh script can diff these against Cloudflare's live
     * lists without duplicating them.
     *
     * @return string[]
     */
    public static function ranges(): array
    {
        return array_merge(self::CLOUDFLARE_V4, self::CLOUDFLARE_V6);
    }
}
