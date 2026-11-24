<?php
namespace Zero\Core\Module\Auth\Model;

use Zero\Core\Database;
use Zero\Core\Request;

/**
 * One-time tokens: magic-link sign-in, email verification, later password reset.
 *
 * Not a Zero\Core\Model subclass on purpose — the primary key is the selector,
 * reads are constant-time comparisons, and consumption is a conditional UPDATE
 * whose rowCount() IS the result. Model::save() has no shape for that.
 */
class AuthToken
{
    /** Issue a token. Returns "selector:validator" — the only time the validator exists in plaintext. */
    public static function issue(int $userId, string $purpose, int $ttlSeconds): string
    {
        $selector  = self::b64url(random_bytes(16));   // 22 chars
        $validator = self::b64url(random_bytes(32));   // 43 chars

        // expires_at is computed by the DATABASE, not by PHP. Every read compares
        // it against MySQL's NOW(), and PHP here runs UTC while MySQL runs local
        // time — writing a PHP timestamp made every token outlive its TTL by the
        // offset between them (4 hours on this host).
        Database::getConnection()->prepare(
            "INSERT INTO auth_token (selector, validator, user_id, purpose, expires_at, created_ip)
             VALUES (?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND), ?)"
        )->execute([
            $selector,
            hash('sha256', $validator),
            $userId,
            $purpose,
            $ttlSeconds,
            // NOT $_SERVER['REMOTE_ADDR']: Auth::throttle() queries this column
            // by client IP, and behind Cloudflare REMOTE_ADDR is the POP's egress
            // address. Writing one and reading the other made the WHERE never
            // match, so the throttle counted 0 and never fired. `?:` maps '' to
            // NULL rather than storing an empty string; the column is nullable.
            Request::clientIp() ?: null,
        ]);

        return $selector . ':' . $validator;
    }

    /**
     * Validate and burn a token. Returns the user id, or null for every failure
     * mode without distinguishing them — the caller shows one generic message.
     */
    public static function consume(string $token, string $purpose): ?int
    {
        if (!str_contains($token, ':')) {
            return null;
        }
        [$selector, $validator] = explode(':', $token, 2);
        if ($selector === '' || $validator === '') {
            return null;
        }

        $db   = Database::getConnection();
        $stmt = $db->prepare(
            "SELECT user_id, validator FROM auth_token
             WHERE selector = ? AND purpose = ? AND consumed_at IS NULL AND expires_at > NOW()"
        );
        $stmt->execute([$selector, $purpose]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        // Constant-time: never compare secrets with == or ===.
        if (!hash_equals($row['validator'], hash('sha256', $validator))) {
            return null;
        }

        // Burn it in the same statement that re-checks it. A check-then-update
        // would race two concurrent clicks into two sessions; rowCount() === 0
        // means another request got there first.
        $burn = $db->prepare(
            "UPDATE auth_token SET consumed_at = NOW()
             WHERE selector = ? AND consumed_at IS NULL AND expires_at > NOW()"
        );
        $burn->execute([$selector]);
        if ($burn->rowCount() === 0) {
            return null;
        }

        return (int) $row['user_id'];
    }

    /**
     * Resolve a token's user WITHOUT consuming it, for display on the confirm
     * page. Deliberately performs no UPDATE — see the DO-NOT-MOVE note in
     * Auth::token(). Same constant-time comparison as consume().
     */
    public static function peek(string $token, string $purpose): ?int
    {
        if (!str_contains($token, ':')) {
            return null;
        }
        [$selector, $validator] = explode(':', $token, 2);
        if ($selector === '' || $validator === '') {
            return null;
        }

        $stmt = Database::getConnection()->prepare(
            "SELECT user_id, validator FROM auth_token
             WHERE selector = ? AND purpose = ? AND consumed_at IS NULL AND expires_at > NOW()"
        );
        $stmt->execute([$selector, $purpose]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false || !hash_equals($row['validator'], hash('sha256', $validator))) {
            return null;
        }
        return (int) $row['user_id'];
    }

    /** Live (unconsumed, unexpired) tokens for a user and purpose. */
    public static function liveCountForUser(int $userId, string $purpose): int
    {
        $stmt = Database::getConnection()->prepare(
            "SELECT COUNT(*) FROM auth_token
             WHERE user_id = ? AND purpose = ? AND consumed_at IS NULL AND expires_at > NOW()"
        );
        $stmt->execute([$userId, $purpose]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Consume the oldest live token for a user/purpose, to cap outstanding links.
     *
     * NO CALLER REMAINS. Auth::throttle() used to call this when a user hit the
     * live-token cap, but burning the oldest let anyone POST /auth/signin three
     * times with a victim's address and destroy the link the victim was about to
     * click — a targeted, repeatable denial of sign-in. The throttle now declines
     * to issue instead. Kept for a future operator/admin-initiated revocation.
     */
    public static function burnOldest(int $userId, string $purpose): void
    {
        Database::getConnection()->prepare(
            "UPDATE auth_token SET consumed_at = NOW()
             WHERE user_id = ? AND purpose = ? AND consumed_at IS NULL AND expires_at > NOW()
             ORDER BY created_at ASC LIMIT 1"
        )->execute([$userId, $purpose]);
    }

    /** How many tokens this IP has been issued within the window. Drives the throttle. */
    public static function issuedFromIpSince(string $ip, int $seconds): int
    {
        $stmt = Database::getConnection()->prepare(
            "SELECT COUNT(*) FROM auth_token
             WHERE created_ip = ? AND created_at > DATE_SUB(NOW(), INTERVAL ? SECOND)"
        );
        $stmt->execute([$ip, $seconds]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Reap expired rows. Safe to call opportunistically.
     *
     * WARNING: do NOT schedule this. It deletes on expires_at, and the IP
     * throttle counts created_at over a longer window — reaping on expiry
     * removes rows the throttle still needs and inflates the effective cap.
     * Use purgeOlderThan() for maintenance. This is kept for tests and for
     * callers that genuinely want expiry semantics.
     */
    public static function purgeExpired(): int
    {
        $stmt = Database::getConnection()->prepare("DELETE FROM auth_token WHERE expires_at <= NOW()");
        $stmt->execute();
        return $stmt->rowCount();
    }

    /**
     * Reap rows older than a retention window, by created_at.
     *
     * Deliberately NOT by expires_at — see purgeExpired(). The IP throttle
     * counts created_at over IP_WINDOW (1 hour) while login tokens expire in
     * 15 minutes, so deleting on expiry removes rows the throttle still needs.
     * Retention must exceed both the longest TTL (24h, verify) and the throttle
     * window, hence the two-day default.
     */
    public static function purgeOlderThan(int $seconds = 172800): int
    {
        $stmt = Database::getConnection()->prepare(
            "DELETE FROM auth_token WHERE created_at < DATE_SUB(NOW(), INTERVAL ? SECOND)"
        );
        $stmt->execute([$seconds]);
        return $stmt->rowCount();
    }

    /** URL-safe base64 with no padding, so a token survives a query string intact. */
    private static function b64url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
