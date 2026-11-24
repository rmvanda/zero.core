<?php
namespace Zero\Core\Module\Auth\OAuth;

use Zero\Core\Database;

/**
 * OAuth provider registry (the `oauth_provider` table). Provider-agnostic
 * source of truth for which providers exist, their display metadata + via code,
 * and whether an admin has enabled them.
 *
 * "Available" = admin-enabled AND credentials configured in this deployment, so
 * a provider auto-appears once its credentials exist and can be hidden by the
 * (future) admin dashboard without code changes. Consumed by the login buttons
 * and /user/profile. Sibling of the dedicated-table store Token.
 */
class Provider
{
    /** Per-request cache of all() — the sitewide login partial reads it on every page. */
    private static ?array $cache = null;

    /** All registered providers, sorted, each annotated with a `configured` bool. */
    public static function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        $stmt = Database::getConnection()->query(
            "SELECT id, slug, name, via_code, enabled, sort_order, created_at, updated_at
             FROM oauth_provider ORDER BY sort_order, name"
        );
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as &$row) {
            $row['enabled']    = (bool) $row['enabled'];
            $row['via_code']   = (int) $row['via_code'];
            $row['configured'] = self::isConfigured($row['slug']);
        }
        unset($row);
        return self::$cache = $rows;
    }

    /** Providers a user can actually use right now: enabled AND configured. */
    public static function available(): array
    {
        return array_values(array_filter(
            self::all(),
            fn(array $p) => $p['enabled'] && $p['configured']
        ));
    }

    /** One provider row (annotated), or null if the slug isn't registered. */
    public static function bySlug(string $slug): ?array
    {
        foreach (self::all() as $p) {
            if ($p['slug'] === $slug) {
                return $p;
            }
        }
        return null;
    }

    /** Toggle a provider's admin-enabled flag (for the future admin dashboard). */
    public static function setEnabled(string $slug, bool $enabled): void
    {
        $stmt = Database::getConnection()->prepare(
            "UPDATE oauth_provider SET enabled = ? WHERE slug = ?"
        );
        $stmt->execute([$enabled ? 1 : 0, $slug]);
        self::$cache = null; // invalidate the per-request cache
    }

    /**
     * Whether this provider's OAuth credentials are configured in this
     * deployment. Google is credentialed via its google_api.json; the OAuth2
     * providers via the auto-loaded {SLUG}_CLIENT_ID ini constant (the same gate
     * the login buttons already used).
     */
    public static function isConfigured(string $slug): bool
    {
        if ($slug === 'google') {
            // the file GoogleAdapter reads, found from this module's own folder
            return is_file(dirname(__DIR__) . '/adapters/config/google_api.json');
        }
        // OAuth2 providers need BOTH id and secret to complete a token exchange.
        $idConst     = strtoupper($slug) . '_CLIENT_ID';
        $secretConst = strtoupper($slug) . '_CLIENT_SECRET';
        return defined($idConst) && constant($idConst)
            && defined($secretConst) && constant($secretConst);
    }
}
