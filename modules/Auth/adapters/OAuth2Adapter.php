<?php
use \Zero\Core\Console;
use \Zero\Core\HTTPError;
use \Zero\Lib\Http\Client;

/**
 * Abstract OAuth2 authorization-code adapter.
 *
 * Owns the OAuth2 login flow shared by the pure-OAuth2 providers (GitHub,
 * Discord, GitLab): CSRF state, code->token exchange, and the centralized
 * verified-email gate that Auth::complete()'s email-only user matching depends
 * on. Subclasses supply endpoints, scopes, and the provider-specific identity
 * fetch + mapping.
 *
 * Mirrors the KeycloakAdapter shape: global-namespace class, required (not
 * autoloaded), instantiated via `new $adapterClass()` from Auth::loadAdapter().
 */
abstract class OAuth2Adapter
{
    public $user;

    protected $config;                 // assoc: client_id, client_secret, redirect_uri

    /** Provider slug — session-key namespace + config-constant prefix ({PROVIDER}_CLIENT_ID). */
    protected string $provider = '';

    /** OAuth2 endpoints + scope string — set by each subclass. */
    protected string $authEndpoint  = '';
    protected string $tokenEndpoint = '';
    protected string $scopes        = '';

    /** URL routing code (the Auth::$smids key) — encoded into the OAuth `state`
     *  so /auth/complete can re-select this adapter without a ?via query param. */
    protected int $via = 0;

    /** Full token bundle captured during the last code exchange, for persistence. */
    protected array $tokens = [];

    /** Injected HTTP client (test seam); null uses a fresh Client. */
    protected ?\Zero\Lib\Http\Client $httpClient = null;

    /**
     * @param array|null $config Inject config for testing; null loads from ini constants.
     */
    public function __construct(?array $config = null)
    {
        $this->config = $config ?? $this->loadConfig();
    }

    /**
     * Build adapter config from the auto-loaded ini constants
     * ({PROVIDER}_CLIENT_ID / {PROVIDER}_CLIENT_SECRET, defined by
     * Application::defineConstants() from app/config/{provider}.ini) and derive
     * the redirect_uri from PRIMARY_DOMAIN. The callback carries no ?via — the
     * provider is recovered from the OAuth `state` (see buildAuthorizationUrl()).
     */
    protected function loadConfig(): array
    {
        $prefix      = strtoupper($this->provider);
        $idConst     = "{$prefix}_CLIENT_ID";
        $secretConst = "{$prefix}_CLIENT_SECRET";

        if (!defined($idConst) || !defined($secretConst)) {
            Console::warn("OAuth2 credentials not defined for '{$this->provider}' "
                . "(expected {$idConst}/{$secretConst} in app/config/{$this->provider}.ini)");
        }

        return [
            'client_id'     => defined($idConst) ? constant($idConst) : '',
            'client_secret' => defined($secretConst) ? constant($secretConst) : '',
            'redirect_uri'  => 'https://' . PRIMARY_DOMAIN . '/auth/complete',
        ];
    }

    /** Session key holding the single-use CSRF state for this provider. */
    protected function stateKey(): string
    {
        return "oauth_state_{$this->provider}";
    }

    /**
     * Build the provider authorization URL and store a CSRF state in the session.
     */
    public function buildAuthorizationUrl(): string
    {
        // Prefix the via code onto the CSRF state so /auth/complete can re-select
        // this adapter without a ?via query param. bin2hex() never emits ':', so the
        // "{via}:" prefix is unambiguous; the CSRF check still compares the full state.
        $state = $this->via . ':' . bin2hex(random_bytes(16));
        $_SESSION[$this->stateKey()] = $state;

        $params = http_build_query([
            'client_id'     => $this->config['client_id'] ?? '',
            'redirect_uri'  => $this->config['redirect_uri'] ?? '',
            'response_type' => 'code',
            'scope'         => $this->scopes,
            'state'         => $state,
        ]);

        return "{$this->authEndpoint}?{$params}";
    }

    /** Redirect the user to the provider authorization URL. */
    public function openAuthorizationUrl(): void
    {
        header("Location: " . $this->buildAuthorizationUrl());
    }

    /**
     * OAuth2 callback handler: validate state, exchange the code, fetch identity,
     * enforce a verified email, and return the normalized user. Called by
     * Auth::complete() via its else branch.
     */
    public function getCurrentUser(): array
    {
        // CSRF: returned state must match what we stored before the redirect.
        $expectedState = $_SESSION[$this->stateKey()] ?? null;
        if (empty($_GET['state']) || !is_string($_GET['state']) || $_GET['state'] !== $expectedState) {
            throw new HTTPError(400, "OAuth2 state mismatch ({$this->provider})");
        }

        if (empty($_GET['code']) || !is_string($_GET['code'])) {
            throw new HTTPError(400, "Missing OAuth2 authorization code ({$this->provider})");
        }

        // Exchange the code for the full token bundle (access + refresh + expiry + scope).
        $this->tokens = $this->exchangeCode($_GET['code']);

        $raw  = $this->fetchIdentity($this->tokens['access_token']);
        $user = $this->format($raw);

        // NOT gated here. Auth::enforceVerifiedEmail() checks this adapter's
        // OUTPUT, because the invariant belongs to Auth — complete() is what
        // matches accounts by email alone. An adapter's job is to report what
        // the provider said, including verified => false, faithfully.

        // Record the provider's stable user id alongside the bundle for persistence.
        $this->tokens['provider_user_id'] = isset($user['id']) ? (string) $user['id'] : null;

        // State is single-use; clear it once the identity validates.
        unset($_SESSION[$this->stateKey()]);
        $_SESSION['login_provider'] = $this->provider;

        $this->user = $user;
        return $user;
    }

    /**
     * Exchange an authorization code for the token bundle at the token endpoint
     * (form-encoded request, JSON response).
     */
    protected function exchangeCode(string $code): array
    {
        $client = $this->http();
        $client->setHeader('Accept', 'application/json');

        $response = $client->post($this->tokenEndpoint, [
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'redirect_uri'  => $this->config['redirect_uri'] ?? '',
            'client_id'     => $this->config['client_id'] ?? '',
            'client_secret' => $this->config['client_secret'] ?? '',
        ], false); // false = application/x-www-form-urlencoded

        if (!$response->isSuccess()) {
            throw new HTTPError(502, "OAuth2 token exchange failed ({$this->provider}, HTTP {$response->statusCode})");
        }

        $json = $response->json();
        if ($json === null) {
            throw new HTTPError(502, "OAuth2 token response is not valid JSON ({$this->provider})");
        }
        if (empty($json['access_token'])) {
            throw new HTTPError(502, "OAuth2 token response missing access_token ({$this->provider})");
        }

        return $this->normalizeToken($json);
    }

    /** HTTP client for token calls (test seam via setHttpClient()). */
    protected function http(): \Zero\Lib\Http\Client
    {
        return $this->httpClient ?? new \Zero\Lib\Http\Client();
    }

    /** Inject an HTTP client (tests). */
    public function setHttpClient(\Zero\Lib\Http\Client $client): void
    {
        $this->httpClient = $client;
    }

    /** The token bundle captured during getCurrentUser(), for Token::store(). */
    public function tokenBundle(): array
    {
        return $this->tokens;
    }

    /**
     * Map a provider token response to the persisted bundle shape. Only includes
     * keys the response actually carried, so Token::store()'s partial update
     * preserves prior values (e.g. a refresh with no new refresh_token).
     */
    protected function normalizeToken(array $json): array
    {
        $bundle = [
            'access_token' => $json['access_token'],
            'token_type'   => $json['token_type'] ?? 'Bearer',
        ];
        if (isset($json['expires_in'])) {
            $bundle['expires_at'] = date('Y-m-d H:i:s', time() + (int) $json['expires_in']);
        }
        if (isset($json['scope'])) {
            $bundle['scope'] = $json['scope'];
        }
        if (!empty($json['refresh_token'])) {
            // GitLab rotates its refresh token; capture the new one. Google/Discord
            // omit it on refresh, leaving the stored one intact via store().
            $bundle['refresh_token'] = $json['refresh_token'];
        }
        return $bundle;
    }

    /**
     * Refresh an expired access token using the stored refresh token. Returns a new
     * normalized bundle, or null if the provider issued no refresh token (e.g.
     * GitHub OAuth apps) or the refresh failed.
     */
    public function refresh(array $stored): ?array
    {
        $refreshToken = $stored['refresh_token'] ?? null;
        if (empty($refreshToken)) {
            return null; // nothing to refresh (non-expiring provider)
        }

        $client = $this->http();
        $client->setHeader('Accept', 'application/json');

        $response = $client->post($this->tokenEndpoint, [
            'grant_type'    => 'refresh_token',
            'refresh_token' => $refreshToken,
            'client_id'     => $this->config['client_id'] ?? '',
            'client_secret' => $this->config['client_secret'] ?? '',
        ], false);

        if (!$response->isSuccess()) {
            Console::warn("OAuth2 token refresh failed ({$this->provider}, HTTP {$response->statusCode})");
            return null;
        }

        $json = $response->json();
        if ($json === null || empty($json['access_token'])) {
            return null;
        }

        return $this->normalizeToken($json);
    }

    /**
     * Fetch the provider identity with the access token. Return a provider-shaped
     * assoc array that format() maps. Implementations must ensure the data needed
     * to resolve a verified email is present.
     */
    abstract protected function fetchIdentity(string $accessToken): array;

    /**
     * Map provider identity to the standard shape Auth::complete() consumes:
     * ['full_name','id','email','verified','pic'].
     */
    abstract public function format(array $raw): array;
}
