<?php
/**
 * Reap old auth_token rows. Run from cron, hourly or so, e.g.
 * `17 * * * * php /path/to/site/core/modules/Auth/bin/purge-auth-tokens.php`.
 *
 * Deletes by created_at, never by expires_at: the sign-in IP throttle counts
 * created_at over a one-hour window, so reaping on expiry would delete rows it
 * still needs and quietly widen the rate limit.
 *
 * Usage: php core/modules/Auth/bin/purge-auth-tokens.php [retention-seconds]
 */
// this file is {site}/core/modules/Auth/bin/: the site root is four levels up
require __DIR__ . '/../../../autoload.php';

$root = dirname(__DIR__, 4);
$ini  = parse_ini_file($root . '/app/config/database.ini', false, INI_SCANNER_RAW);

Zero\Core\Database::init([
    'host' => $ini['HOST'],
    'name' => $ini['NAME'],
    'user' => $ini['USER'],
    'pass' => $ini['PASS'],
]);

$retain  = isset($argv[1]) ? (int) $argv[1] : 172800;
$deleted = Zero\Module\Auth\Model\AuthToken::purgeOlderThan($retain);

printf("purge-auth-tokens: deleted %d row(s) older than %d seconds\n", $deleted, $retain);
