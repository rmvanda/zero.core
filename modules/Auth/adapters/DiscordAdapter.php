<?php
require_once __DIR__ . '/OAuth2Adapter.php';

use \Zero\Core\Exception\HTTPError;
use \Zero\Lib\Http\Client;

/**
 * Discord OAuth2 adapter.
 *
 * Discord is OAuth2-only. /users/@me returns an email plus a `verified` boolean;
 * the base verified-email gate requires verified === true.
 */
class DiscordAdapter extends OAuth2Adapter
{
    protected string $provider      = 'discord';
    protected int    $via           = 13;
    protected string $authEndpoint  = 'https://discord.com/oauth2/authorize';
    protected string $tokenEndpoint = 'https://discord.com/api/oauth2/token';
    protected string $scopes        = 'identify email';

    protected function fetchIdentity(string $accessToken): array
    {
        $client = new Client();
        $client->setAuth('BEARER', ['token' => $accessToken]);

        $response = $client->get('https://discord.com/api/users/@me');
        if (!$response->isSuccess()) {
            throw new HTTPError(502, "Discord API request failed (HTTP {$response->statusCode})");
        }

        return $response->json() ?? [];
    }

    public function format(array $raw): array
    {
        $id     = $raw['id'] ?? null;
        $avatar = $raw['avatar'] ?? null;

        return [
            'full_name' => $raw['global_name'] ?? ($raw['username'] ?? null),
            'id'        => $id,
            'email'     => $raw['email'] ?? null,
            'verified'  => ($raw['verified'] ?? false) === true,
            'pic'       => ($id && $avatar)
                ? "https://cdn.discordapp.com/avatars/{$id}/{$avatar}.png"
                : null,
        ];
    }
}
