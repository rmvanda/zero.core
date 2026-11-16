<?php
namespace Zero\Module\Auth\OAuth;

use Zero\Core\Crypto;
use Zero\Core\Database;

/**
 * Per-provider OAuth token store. One row per (user_id, provider) in the
 * dedicated `oauth_token` table (NOT the EAV Entity framework — see
 * docs/superpowers/specs/2026-07-07-oauth-token-persistence-design.md).
 *
 * Secret columns are transparently encrypted at rest via Zero\Core\Crypto;
 * callers always pass and receive plaintext.
 */
class Token
{
    /** Columns encrypted at rest. */
    private const SECRET_COLUMNS = ['access_token', 'refresh_token'];

    /** Writable columns (whitelist — never interpolate arbitrary keys into SQL). */
    private const COLUMNS = [
        'access_token', 'refresh_token', 'token_type',
        'scope', 'provider_user_id', 'expires_at',
    ];

    /**
     * Upsert a provider's token bundle. Only keys present in $fields are written;
     * omitted columns are left intact (so a refresh returning no new refresh_token
     * preserves the stored one, while GitLab's rotated token overwrites).
     */
    public static function store(int $userId, string $provider, array $fields): void
    {
        $cols = array_values(array_intersect(self::COLUMNS, array_keys($fields)));
        if (empty($cols)) {
            return;
        }

        $insertCols   = array_merge(['user_id', 'provider'], $cols);
        $placeholders = implode(', ', array_fill(0, count($insertCols), '?'));

        $params = [$userId, $provider];
        foreach ($cols as $col) {
            $params[] = self::encodeColumn($col, $fields[$col]);
        }

        // On duplicate (user_id, provider), update only the supplied columns.
        $updates = implode(', ', array_map(fn($c) => "$c = VALUES($c)", $cols));

        $sql = "INSERT INTO oauth_token (" . implode(', ', $insertCols) . ") "
             . "VALUES ($placeholders) "
             . "ON DUPLICATE KEY UPDATE $updates";

        Database::getConnection()->prepare($sql)->execute($params);
    }

    /** Decrypted [column => value] for one provider, or null if not connected. */
    public static function get(int $userId, string $provider): ?array
    {
        $stmt = Database::getConnection()->prepare(
            "SELECT access_token, refresh_token, token_type, scope,
                    provider_user_id, expires_at, created_at, updated_at
             FROM oauth_token WHERE user_id = ? AND provider = ?"
        );
        $stmt->execute([$userId, $provider]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        foreach (self::SECRET_COLUMNS as $col) {
            if (!empty($row[$col])) {
                $row[$col] = Crypto::decrypt($row[$col]); // null => re-auth needed
            }
        }
        return $row;
    }

    /** Provider slugs this user has connected. */
    public static function providersFor(int $userId): array
    {
        $stmt = Database::getConnection()->prepare(
            "SELECT provider FROM oauth_token WHERE user_id = ? ORDER BY provider"
        );
        $stmt->execute([$userId]);
        return $stmt->fetchAll(\PDO::FETCH_COLUMN) ?: [];
    }

    /**
     * Display-only connection metadata for all of a user's connected providers,
     * keyed by provider slug: [slug => [provider, scope, expires_at, created_at,
     * updated_at]]. Deliberately selects NO secret columns — nothing here needs
     * decryption, and profile views never touch the tokens themselves.
     */
    public static function connectionsFor(int $userId): array
    {
        $stmt = Database::getConnection()->prepare(
            "SELECT provider, scope, expires_at, created_at, updated_at
             FROM oauth_token WHERE user_id = ?"
        );
        $stmt->execute([$userId]);
        $out = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $out[$row['provider']] = $row;
        }
        return $out;
    }

    /** Remove a provider's tokens (explicit disconnect). */
    public static function forget(int $userId, string $provider): void
    {
        $stmt = Database::getConnection()->prepare(
            "DELETE FROM oauth_token WHERE user_id = ? AND provider = ?"
        );
        $stmt->execute([$userId, $provider]);
    }

    private static function encodeColumn(string $col, $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = (string) $value;
        return in_array($col, self::SECRET_COLUMNS, true) ? Crypto::encrypt($value) : $value;
    }
}
