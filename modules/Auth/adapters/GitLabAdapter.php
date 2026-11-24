<?php
namespace Zero\Core\Module\Auth\Adapters;

use \Zero\Core\Exception\HTTPError;
use \Zero\Lib\Http\Client;

/**
 * GitLab OAuth2 adapter.
 *
 * GitLab is OAuth2-only. /api/v4/user returns the primary account email plus
 * `confirmed_at`; a non-empty confirmed_at means the email is verified.
 */
class GitLabAdapter extends OAuth2Adapter
{
    protected string $provider      = 'gitlab';
    protected int    $via           = 14;
    protected string $authEndpoint  = 'https://gitlab.com/oauth/authorize';
    protected string $tokenEndpoint = 'https://gitlab.com/oauth/token';
    protected string $scopes        = 'read_user';

    protected function fetchIdentity(string $accessToken): array
    {
        $client = new Client();
        $client->setAuth('BEARER', ['token' => $accessToken]);

        $response = $client->get('https://gitlab.com/api/v4/user');
        if (!$response->isSuccess()) {
            throw new HTTPError(502, "GitLab API request failed (HTTP {$response->statusCode})");
        }

        return $response->json() ?? [];
    }

    public function format(array $raw): array
    {
        return [
            'full_name' => $raw['name'] ?? ($raw['username'] ?? null),
            'id'        => $raw['id'] ?? null,
            'email'     => $raw['email'] ?? null,
            'verified'  => !empty($raw['confirmed_at']),
            'pic'       => $raw['avatar_url'] ?? null,
        ];
    }
}
