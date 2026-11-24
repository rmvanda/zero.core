<?php
namespace Zero\Core\Module\Auth\Adapters;

use \Zero\Core\Exception\HTTPError;
use \Zero\Lib\Http\Client;

/**
 * GitHub OAuth2 adapter.
 *
 * GitHub is OAuth2-only (no id_token). The top-level /user email can be null or
 * unverified, so the verified primary email is resolved from /user/emails.
 * GitHub's API rejects requests without a User-Agent header (HTTP 403).
 */
class GitHubAdapter extends OAuth2Adapter
{
    protected string $provider      = 'github';
    protected int    $via           = 12;
    protected string $authEndpoint  = 'https://github.com/login/oauth/authorize';
    protected string $tokenEndpoint = 'https://github.com/login/oauth/access_token';
    protected string $scopes        = 'read:user user:email';

    protected function fetchIdentity(string $accessToken): array
    {
        $profile = $this->apiGet('https://api.github.com/user', $accessToken);
        $emails  = $this->apiGet('https://api.github.com/user/emails', $accessToken);

        $primary = $this->selectVerifiedPrimaryEmail(is_array($emails) ? $emails : []);
        $profile['email']          = $primary['email'] ?? null;
        $profile['email_verified'] = $primary !== null;

        return $profile;
    }

    /** Pick the primary, verified email from GitHub's /user/emails array. */
    public function selectVerifiedPrimaryEmail(array $emails): ?array
    {
        foreach ($emails as $entry) {
            if (($entry['primary'] ?? false) === true && ($entry['verified'] ?? false) === true) {
                return $entry;
            }
        }
        return null;
    }

    public function format(array $raw): array
    {
        return [
            'full_name' => ($raw['name'] ?? null) ?: ($raw['login'] ?? null),
            'id'        => $raw['id'] ?? null,
            'email'     => $raw['email'] ?? null,
            'verified'  => ($raw['email_verified'] ?? false) === true,
            'pic'       => $raw['avatar_url'] ?? null,
        ];
    }

    /** GET a GitHub API endpoint with the required auth + User-Agent headers. */
    private function apiGet(string $url, string $accessToken): array
    {
        $client = new Client();
        $client->setHeaders([
            'Authorization' => 'Bearer ' . $accessToken,
            'User-Agent'    => strtolower(trim(preg_replace('/[^A-Za-z0-9]+/', '-', SITE_SHORT_NAME), '-')) . '-zero',
            'Accept'        => 'application/vnd.github+json',
        ]);

        $response = $client->get($url);
        if (!$response->isSuccess()) {
            throw new HTTPError(502, "GitHub API request failed (HTTP {$response->statusCode})");
        }

        return $response->json() ?? [];
    }
}
