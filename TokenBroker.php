<?php
namespace Zero\Core;

use Zero\Entity\OAuthToken;

/**
 * Provider-token access for consumers (e.g. the Dashboard). Returns an
 * always-valid access token, refreshing transparently when the stored one has
 * expired. The only component aware of expiry + refresh.
 */
class TokenBroker
{
    /** Treat a token expiring within this many seconds as already stale. */
    private const SKEW_SECONDS = 60;

    /** Test seam: fn(string $provider): object|null returning an adapter with refresh(). */
    public static ?\Closure $adapterResolver = null;

    /**
     * A currently-valid access token for (user, provider), or null if the user
     * has not connected that provider or the token can no longer be refreshed
     * (caller should prompt re-authentication).
     */
    public static function accessToken(int $userId, string $provider): ?string
    {
        $row = OAuthToken::get($userId, $provider);
        if ($row === null || empty($row['access_token'])) {
            return null;
        }

        $expiresAt = $row['expires_at'] ?? null;
        $stale = $expiresAt !== null
              && strtotime($expiresAt) <= time() + self::SKEW_SECONDS;

        if (!$stale) {
            return $row['access_token'];
        }
        if (empty($row['refresh_token'])) {
            return $row['access_token']; // non-expiring / no refresh (e.g. GitHub)
        }

        try {
            if (self::$adapterResolver) {
                $adapter = (self::$adapterResolver)($provider);
            } else {
                // Auth is a single-segment module class (namespace Zero\Module; class
                // Auth, at modules/Auth/Auth.php). The router loads it via isModule(),
                // NOT the class autoloader — so from core we require it explicitly
                // before the static call. FQCN is \Zero\Module\Auth (not ...\Auth\Auth).
                require_once __DIR__ . '/../modules/Auth/Auth.php';
                $adapter = \Zero\Module\Auth::adapterForProvider($provider);
            }

            if ($adapter === null || !method_exists($adapter, 'refresh')) {
                return $row['access_token'];
            }

            $new = $adapter->refresh($row);
            if ($new === null || empty($new['access_token'])) {
                return null; // refresh failed -> re-auth needed
            }

            OAuthToken::store($userId, $provider, $new);
            return $new['access_token'];
        } catch (\Throwable $e) {
            \Zero\Core\Console::error("TokenBroker refresh failed for {$provider}: " . $e->getMessage());
            return null;
        }
    }

    /** Providers the user has connected. */
    public static function connectedProviders(int $userId): array
    {
        return OAuthToken::providersFor($userId);
    }
}
