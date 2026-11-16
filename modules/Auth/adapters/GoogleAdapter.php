<?php
use \Zero\Core\Console as Console;

/**
 * Google OAuth Adapter
 *
 * Handles Google OAuth authentication flow.
 * Extends Google_Client from the Google API PHP Client library.
 */
class GoogleAdapter extends Google_Client
{
    public $user;
    private $path;

    /** Normalized token bundle from the last login, for Token::store(). */
    protected array $tokens = [];

    public function __construct()
    {
        parent::__construct();

        $this->path = __DIR__."/config/google_api.json";

        if(!file_exists($this->path)){
            Console::warn("Google API config not found at: " . $this->path);
        }

        $this->setAuthConfigFile($this->path);
        $this->addScope(Google_Service_Oauth2::USERINFO_EMAIL);
        $this->addScope(Google_Service_Oauth2::USERINFO_PROFILE);
//        $this->addScope(\Google\Service\Calendar::CALENDAR_READONLY);
//        $this->addScope(\Google\Service\Calendar::CALENDAR_EVENTS_READONLY);
        // redirect_uri is only needed for the interactive auth-code login (Auth
        // module context, where GP_REDIRECT_URI is defined). The refresh_token
        // grant used by refresh() from other modules needs no redirect_uri, and
        // GP_REDIRECT_URI is not defined outside the Auth module — guard it so
        // constructing this adapter from Broker (Calendar/Dashboard) can't fatal.
        if (defined('GP_REDIRECT_URI')) {
            $this->setRedirectUri(GP_REDIRECT_URI);
        }
        $this->setAccessType('offline');
        $this->setIncludeGrantedScopes(true);
        // Use setPrompt('consent') instead of setApprovalPrompt('force')
        // — the library ignores approval_prompt when prompt is set (line 414-417 of Client.php)
        // — 'consent' forces the consent screen to appear, ensuring new scopes are granted
        $this->setPrompt('consent');
    }

    /**
     * Redirect user to Google authorization URL
     */
    public function openAuthorizationUrl()
    {
        header("Location: " . $this->createAuthUrl());
    }

    /**
     * Get current user from Google OAuth callback
     *
     * @return array Formatted user data
     */
    public function getCurrentUser()
    {
        $this->authenticate($_GET['code']);
        $oauth = new Google_Service_Oauth2($this);
        $this->user = $oauth->userinfo_v2_me->get();

        $this->user = $this->format($this->user);
        $token = $this->getAccessToken();
        // Capture a normalized bundle for persistence (Auth::complete()).
        $this->tokens = $this->normalizeGoogleToken($token, $this->user['id'] ?? null);
        // Tokens are persisted to the oauth_token table by Auth::complete()
        // (via tokenBundle()); no session storage needed.
        $this->user['token'] = $token;

        return $this->user;
    }

    /**
     * Format Google user data to standard format
     *
     * @param array|object $user Raw user data from Google
     * @return array Formatted user data
     */
    public function format($user)
    {
        $fuser['full_name'] = $user['given_name'] . " " . $user['family_name'];
        $fuser['id'] = $user['id'];
        $fuser['email'] = $user['email'];
        // Normalized to a real bool. Google reports verified_email on the OAuth2
        // userinfo path and email_verified on the one-tap ID token, and neither
        // is guaranteed to arrive as a PHP bool. Everything downstream compares
        // strictly, so an unnormalized "true" would read as UNVERIFIED.
        // ?? rather than the previous ?:, so an explicit false is kept as false
        // instead of falling through to the other key.
        $fuser['verified'] = filter_var(
            $user['verified_email'] ?? $user['email_verified'] ?? false,
            FILTER_VALIDATE_BOOLEAN
        );
        $fuser['pic'] = $user['picture'];
        $fuser['gender'] = $user['gender'] ?? null;
        $fuser['bday'] = $user['birthday'] ?? null;

        return $fuser;
    }

    /** The token bundle captured during getCurrentUser(), for Token::store(). */
    public function tokenBundle(): array
    {
        return $this->tokens;
    }

    /**
     * Map a Google token array to the persisted bundle shape. Google returns
     * expires_in + created; refresh_token only on first consent.
     */
    private function normalizeGoogleToken(array $token, $providerUserId): array
    {
        $bundle = [
            'access_token'     => $token['access_token'] ?? null,
            'token_type'       => $token['token_type'] ?? 'Bearer',
            'scope'            => $token['scope'] ?? null,
            'provider_user_id' => $providerUserId !== null ? (string) $providerUserId : null,
        ];
        if (isset($token['expires_in'])) {
            $created = $token['created'] ?? time();
            $bundle['expires_at'] = date('Y-m-d H:i:s', (int) $created + (int) $token['expires_in']);
        }
        if (!empty($token['refresh_token'])) {
            $bundle['refresh_token'] = $token['refresh_token'];
        }
        return $bundle;
    }

    /**
     * Refresh a Google access token using the stored refresh token.
     * Returns a normalized bundle, or null on failure.
     */
    public function refresh(array $stored): ?array
    {
        $refreshToken = $stored['refresh_token'] ?? null;
        if (empty($refreshToken)) {
            return null;
        }
        $new = $this->fetchAccessTokenWithRefreshToken($refreshToken);
        if (empty($new['access_token'])) {
            return null; // Google returns ['error' => ...] on failure
        }
        return $this->normalizeGoogleToken($new, $stored['provider_user_id'] ?? null);
    }
}
