<?php
use \Zero\Core\Console;
use \Zero\Core\HTTPError;
use \Zero\Lib\Http\Client;

/**
 * Keycloak OIDC Adapter
 *
 * @deprecated 2026-08-08 — not maintained. Reachable only via /auth/keycloak-test,
 *   unlinked from the login UI, and absent from the `oauth_provider` registry, so
 *   nothing routes to it in normal use. Left in place rather than deleted in case
 *   the OIDC surface is ever wanted again.
 *
 *   Consequence to know if it IS revived: it still enforces the verified-email
 *   invariant inline during ID-token validation (see validateIdToken()), whereas
 *   every other adapter now reports faithfully and lets Auth::enforceVerifiedEmail()
 *   gate the output. Keycloak's copy was deliberately not migrated — it sits with
 *   the nonce/azp claim checks, which are genuinely the adapter's job — but it does
 *   mean the rule exists in two places. Reconcile before relying on this again.
 *
 * Implements the OpenID Connect authorization-code flow against a Keycloak realm.
 * Mirrors the GoogleAdapter shape: global-namespace class, required (not autoloaded),
 * and instantiated via `new $adapterClass()` from Auth::loadAdapter().
 *
 * TEST integration only — reachable solely via /auth/keycloak-test. Not exposed in
 * the normal login UI/nav. On success, Auth::complete() runs the full production login.
 */
class KeycloakAdapter
{
    public $user;

    private $config;          // assoc array: base_url, realm, client_id, client_secret, redirect_uri
    public $issuer;           // {base_url}/realms/{realm}
    public $authEndpoint;     // .../protocol/openid-connect/auth
    public $tokenEndpoint;    // .../protocol/openid-connect/token
    public $jwksUri;          // .../protocol/openid-connect/certs

    /**
     * @param array|null $config Inject config for testing; null loads config/keycloak.json
     */
    public function __construct(?array $config = null)
    {
        $this->config = $config ?? $this->loadConfig();

        $base  = rtrim($this->config['base_url'] ?? '', '/');
        $realm = $this->config['realm'] ?? '';

        $this->issuer        = "$base/realms/$realm";
        $this->authEndpoint  = "{$this->issuer}/protocol/openid-connect/auth";
        $this->tokenEndpoint = "{$this->issuer}/protocol/openid-connect/token";
        $this->jwksUri       = "{$this->issuer}/protocol/openid-connect/certs";
    }

    /**
     * Load adapter config from the local JSON file (mirrors GoogleAdapter's config pattern).
     */
    private function loadConfig(): array
    {
        $path = __DIR__ . "/config/keycloak.json";

        if (!file_exists($path)) {
            Console::warn("Keycloak config not found at: " . $path);
            return [];
        }

        return json_decode(file_get_contents($path), true) ?? [];
    }

    /**
     * Build the Keycloak authorization URL and store CSRF state + replay nonce in the session.
     */
    public function buildAuthorizationUrl(): string
    {
        $state = bin2hex(random_bytes(16));
        $nonce = bin2hex(random_bytes(16));

        $_SESSION['kc_state'] = $state;
        $_SESSION['kc_nonce'] = $nonce;

        $params = http_build_query([
            'client_id'     => $this->config['client_id'] ?? '',
            'redirect_uri'  => $this->config['redirect_uri'] ?? '',
            'response_type' => 'code',
            'scope'         => 'openid email profile',
            'state'         => $state,
            'nonce'         => $nonce,
        ]);

        return "{$this->authEndpoint}?{$params}";
    }

    /**
     * Redirect the user to the Keycloak authorization URL.
     */
    public function openAuthorizationUrl(): void
    {
        header("Location: " . $this->buildAuthorizationUrl());
    }

    /**
     * Validate a Keycloak ID token: RS256 signature against the realm JWKS,
     * plus issuer, audience, expiry, and replay-nonce checks.
     *
     * @param string $idToken  Compact-serialized JWS (the id_token)
     * @param string $jwksJson Raw JWKS JSON fetched from Keycloak's certs endpoint
     * @return array Decoded token claims
     */
    public function validateIdToken(string $idToken, string $jwksJson): array
    {
        $algManager = new \Jose\Component\Core\AlgorithmManager([
            new \Jose\Component\Signature\Algorithm\RS256(),
        ]);
        $verifier = new \Jose\Component\Signature\JWSVerifier($algManager);

        // Malformed JWKS/token must surface as a clean 401, not a 500.
        $valid = false;
        try {
            $jwkSet = \Jose\Component\Core\JWKSet::createFromJson($jwksJson);
            $jws = (new \Jose\Component\Signature\Serializer\CompactSerializer())->unserialize($idToken);
            $valid = $verifier->verifyWithKeySet($jws, $jwkSet, 0);
        } catch (\Throwable $e) {
            throw new HTTPError(401, "Keycloak token parse/verify error");
        }
        if (!$valid) {
            throw new HTTPError(401, "Keycloak token signature invalid");
        }

        $claims = json_decode($jws->getPayload(), true) ?? [];

        // Issuer must match the configured realm.
        if (($claims['iss'] ?? null) !== $this->issuer) {
            throw new HTTPError(401, "Keycloak token issuer mismatch");
        }

        // Audience must include our client_id (aud may be a string or an array).
        // No empty-string fallback: a missing client_id is a misconfiguration that
        // must surface rather than silently weaken the audience check.
        $aud = $claims['aud'] ?? [];
        $aud = is_array($aud) ? $aud : [$aud];
        if (!in_array(($this->config['client_id'] ?? null), $aud, true)) {
            throw new HTTPError(401, "Keycloak token audience mismatch");
        }

        // OIDC: with multiple audiences, azp must be present and equal our client_id.
        if (count($aud) > 1 && ($claims['azp'] ?? null) !== ($this->config['client_id'] ?? null)) {
            throw new HTTPError(401, "Keycloak token azp mismatch");
        }

        // Not expired.
        if (($claims['exp'] ?? 0) < time()) {
            throw new HTTPError(401, "Keycloak token expired");
        }

        // Replay protection: a session nonce must exist AND match the token's
        // (fail-closed — absent session nonce always rejects, even for a nonce-less token).
        if (!isset($_SESSION['kc_nonce']) || ($claims['nonce'] ?? null) !== $_SESSION['kc_nonce']) {
            throw new HTTPError(401, "Keycloak token nonce mismatch");
        }

        // Identity safety: complete() matches users by email alone, so a token must
        // carry a non-empty, verified email or it could impersonate an existing user.
        if (empty($claims['email'])) {
            throw new HTTPError(401, "Keycloak token missing email");
        }
        if (($claims['email_verified'] ?? false) !== true) {
            throw new HTTPError(403, "Keycloak email not verified");
        }

        return $claims;
    }

    /**
     * OIDC callback handler: validate state, exchange the code, validate the ID token,
     * and return the formatted user. Called by Auth::complete() via its else branch.
     *
     * @return array Formatted user data (see format())
     */
    public function getCurrentUser(): array
    {
        // CSRF: returned state must match what we stored before the redirect.
        $expectedState = $_SESSION['kc_state'] ?? null;
        if (empty($_GET['state']) || !is_string($_GET['state']) || $_GET['state'] !== $expectedState) {
            throw new HTTPError(400, "Keycloak state mismatch");
        }

        if (empty($_GET['code']) || !is_string($_GET['code'])) {
            throw new HTTPError(400, "Missing Keycloak authorization code");
        }

        $tokens  = $this->exchangeCode($_GET['code']);
        $idToken = $tokens['id_token'] ?? null;
        if ($idToken === null) {
            throw new HTTPError(502, "Keycloak returned no id_token");
        }

        $claims = $this->validateIdToken($idToken, $this->fetchJwks());

        // State and nonce are single-use; clear them once the token validates.
        unset($_SESSION['kc_state'], $_SESSION['kc_nonce']);

        // Mark the session so the test page can confirm the Keycloak path specifically.
        $_SESSION['login_provider'] = 'keycloak';

        $this->user = $this->format($claims);
        return $this->user;
    }

    /**
     * Exchange an authorization code for tokens at the Keycloak token endpoint
     * (form-encoded, per OAuth2).
     */
    private function exchangeCode(string $code): array
    {
        $client = new Client();
        $response = $client->post($this->tokenEndpoint, [
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'redirect_uri'  => $this->config['redirect_uri'] ?? '',
            'client_id'     => $this->config['client_id'] ?? '',
            'client_secret' => $this->config['client_secret'] ?? '',
        ], false); // false = application/x-www-form-urlencoded

        if (!$response->isSuccess()) {
            throw new HTTPError(502, "Keycloak token exchange failed (HTTP {$response->statusCode})");
        }

        return $response->json() ?? throw new HTTPError(502, "Keycloak token response is not valid JSON");
    }

    /**
     * Fetch the realm's JWKS (signing keys) for ID-token validation.
     */
    private function fetchJwks(): string
    {
        $client = new Client();
        $response = $client->get($this->jwksUri);

        if (!$response->isSuccess()) {
            throw new HTTPError(502, "Failed to fetch Keycloak JWKS");
        }

        return $response->text();
    }

    /**
     * Map Keycloak claims to the standard user shape that Auth::complete() consumes.
     *
     * @param array $claims Decoded ID-token claims
     * @return array ['full_name','id','email','verified','pic']
     */
    public function format($claims): array
    {
        $fuser = [];
        $fuser['full_name'] = $claims['name']
            ?? trim(($claims['given_name'] ?? '') . " " . ($claims['family_name'] ?? ''));
        $fuser['id']       = $claims['sub'] ?? null;
        $fuser['email']    = $claims['email'] ?? null;
        $fuser['verified'] = $claims['email_verified'] ?? false;
        $fuser['pic']      = $claims['picture'] ?? null;

        return $fuser;
    }
}
