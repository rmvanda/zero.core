<?php
/**
 * Report drift between Cloudflare's published edge ranges and the list compiled
 * into Zero\Core\TrustedProxy.
 *
 * Deliberately does NOT write anything. The ranges decide whose forwarding
 * headers we believe, so they are trust data: changing them should be a
 * reviewed commit, not a file a cron job silently rewrote. A poisoned or stale
 * auto-refresh would quietly widen who gets believed, which is the worst
 * failure mode available here.
 *
 * Exits non-zero on drift or on a fetch failure, so cron mail tells you.
 *
 * Usage: php core/bin/refresh-cloudflare-ips.php
 */
require __DIR__ . '/../../vendor/autoload.php';

use Zero\Core\TrustedProxy;

const SOURCES = [
    'IPv4' => 'https://www.cloudflare.com/ips-v4',
    'IPv6' => 'https://www.cloudflare.com/ips-v6',
];

$live = [];
foreach (SOURCES as $label => $url) {
    $body = @file_get_contents($url, false, stream_context_create([
        'http' => ['timeout' => 15, 'user_agent' => 'zero-trustedproxy-check'],
    ]));

    if ($body === false || trim($body) === '') {
        fwrite(STDERR, "refresh-cloudflare-ips: could not fetch $label from $url\n");
        exit(2);
    }

    foreach (preg_split('/\R/', trim($body)) as $line) {
        $line = trim($line);
        if ($line !== '') {
            $live[] = $line;
        }
    }
}

$bundled = TrustedProxy::ranges();

$added   = array_values(array_diff($live, $bundled));   // Cloudflare has, we do not
$removed = array_values(array_diff($bundled, $live));   // we have, Cloudflare no longer does

if (!$added && !$removed) {
    printf("refresh-cloudflare-ips: up to date (%d ranges)\n", count($bundled));
    exit(0);
}

echo "refresh-cloudflare-ips: DRIFT DETECTED — update core/TrustedProxy.php\n";

foreach ($added as $r) {
    // Missing ranges are the urgent direction: real visitors arriving through a
    // new edge would be attributed to the POP rather than themselves, which is
    // the sitewide-throttle failure this whole mechanism exists to avoid.
    echo "  + $r  (Cloudflare added; we are not trusting it)\n";
}

foreach ($removed as $r) {
    // Stale ranges are the dangerous direction: if Cloudflare released the
    // block, we would still believe forwarding headers from whoever holds it now.
    echo "  - $r  (Cloudflare dropped; we still trust it)\n";
}

exit(1);
