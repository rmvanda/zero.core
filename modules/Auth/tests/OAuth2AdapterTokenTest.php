<?php
namespace Zero\Module\Auth\Tests;

use Zero\Tests\Core\ZeroTestCase;
use Zero\Lib\Http\Client;
use Zero\Lib\Http\Response;

require_once __DIR__ . '/../adapters/OAuth2Adapter.php';

/** Fake adapter with a fixed verified identity, for token-capture tests. */
class TokenFakeAdapter extends \OAuth2Adapter
{
    protected string $provider      = 'fake';
    protected int    $via           = 99;
    protected string $authEndpoint  = 'https://provider.example.com/authorize';
    protected string $tokenEndpoint = 'https://provider.example.com/token';
    protected string $scopes        = 'identity email';

    protected function fetchIdentity(string $accessToken): array
    {
        return ['id' => 'PU-1', 'email' => 'a@b.com', 'verified' => true];
    }
    public function format(array $raw): array
    {
        return [
            'full_name' => 'A B', 'id' => $raw['id'], 'email' => $raw['email'],
            'verified' => $raw['verified'], 'pic' => null,
        ];
    }
}

/** Canned-response HTTP client (overrides only what the adapter calls). */
class StubHttpClient extends Client
{
    public array $lastPost = [];
    public function __construct(private Response $canned) {}
    public function post(string $url, $data = null, bool $json = true): Response
    {
        $this->lastPost = ['url' => $url, 'data' => $data, 'json' => $json];
        return $this->canned;
    }
    public function setHeader(string $key, string $value): self { return $this; }
}

class OAuth2AdapterTokenTest extends ZeroTestCase
{
    private function config(): array
    {
        return ['client_id' => 'cid', 'client_secret' => 'sec',
                'redirect_uri' => 'https://unisolu.com/auth/complete'];
    }

    private function stub(array $json): StubHttpClient
    {
        return new StubHttpClient(new Response(
            statusCode: 200, headers: [], body: json_encode($json),
            responseTime: 1.0, error: null, success: true
        ));
    }

    public function testGetCurrentUserCapturesTokenBundle(): void
    {
        $_SESSION['oauth_state_fake'] = 's';
        $_GET['state'] = 's';
        $_GET['code']  = 'auth-code';

        $adapter = new TokenFakeAdapter($this->config());
        $adapter->setHttpClient($this->stub([
            'access_token' => 'AT-1', 'refresh_token' => 'RT-1',
            'expires_in' => 3600, 'scope' => 'identity email', 'token_type' => 'Bearer',
        ]));

        $user = $adapter->getCurrentUser();
        $this->assertSame('a@b.com', $user['email']);

        $b = $adapter->tokenBundle();
        $this->assertSame('AT-1', $b['access_token']);
        $this->assertSame('RT-1', $b['refresh_token']);
        $this->assertSame('identity email', $b['scope']);
        $this->assertSame('PU-1', $b['provider_user_id']);
        $this->assertNotEmpty($b['expires_at']);
    }

    public function testRefreshReturnsNewBundle(): void
    {
        $adapter = new TokenFakeAdapter($this->config());
        $adapter->setHttpClient($this->stub([
            'access_token' => 'AT-2', 'expires_in' => 7200, 'token_type' => 'Bearer',
        ]));

        $b = $adapter->refresh(['refresh_token' => 'RT-1']);
        $this->assertSame('AT-2', $b['access_token']);
        $this->assertArrayNotHasKey('refresh_token', $b); // none returned -> preserved by store()
    }

    public function testRefreshCapturesRotatedRefreshToken(): void
    {
        $adapter = new TokenFakeAdapter($this->config());
        $adapter->setHttpClient($this->stub([
            'access_token' => 'AT-3', 'refresh_token' => 'RT-ROTATED', 'expires_in' => 7200,
        ]));

        $b = $adapter->refresh(['refresh_token' => 'RT-OLD']);
        $this->assertSame('RT-ROTATED', $b['refresh_token']); // GitLab-style rotation
    }

    public function testRefreshWithoutRefreshTokenReturnsNull(): void
    {
        $adapter = new TokenFakeAdapter($this->config());
        $this->assertNull($adapter->refresh(['refresh_token' => null])); // e.g. GitHub
    }
}
