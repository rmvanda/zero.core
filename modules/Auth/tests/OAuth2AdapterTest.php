<?php

namespace Zero\Module\Auth\Tests;

use Zero\Tests\Core\ZeroTestCase;

require_once __DIR__ . '/../adapters/OAuth2Adapter.php';

/** Minimal concrete subclass to exercise the shared base behavior. */
class FakeOAuth2Adapter extends \OAuth2Adapter
{
    protected string $provider      = 'fake';
    protected int    $via           = 99;
    protected string $authEndpoint  = 'https://provider.example.com/authorize';
    protected string $tokenEndpoint = 'https://provider.example.com/token';
    protected string $scopes        = 'identity email';

    protected function fetchIdentity(string $accessToken): array { return []; }
    public function format(array $raw): array { return $raw; }
}

class OAuth2AdapterTest extends ZeroTestCase
{
    private function config(): array
    {
        return [
            'client_id'     => 'fake-client',
            'client_secret' => 'shhh',
            'redirect_uri'  => 'https://unisolu.com/auth/complete',
        ];
    }

    public function testBuildAuthorizationUrlContainsOauthParams(): void
    {
        $adapter = new FakeOAuth2Adapter($this->config());
        $url = $adapter->buildAuthorizationUrl();

        $this->assertStringStartsWith('https://provider.example.com/authorize?', $url);

        $query = [];
        parse_str(parse_url($url, PHP_URL_QUERY), $query);

        $this->assertSame('fake-client', $query['client_id']);
        $this->assertSame('https://unisolu.com/auth/complete', $query['redirect_uri']);
        $this->assertSame('code', $query['response_type']);
        $this->assertSame('identity email', $query['scope']);
        $this->assertNotEmpty($query['state']);
    }

    public function testBuildAuthorizationUrlStoresStateInSession(): void
    {
        $adapter = new FakeOAuth2Adapter($this->config());
        $url = $adapter->buildAuthorizationUrl();

        $query = [];
        parse_str(parse_url($url, PHP_URL_QUERY), $query);

        $this->assertSame($_SESSION['oauth_state_fake'], $query['state']);
    }

    public function testBuildAuthorizationUrlEncodesViaInState(): void
    {
        $adapter = new FakeOAuth2Adapter($this->config());
        $url = $adapter->buildAuthorizationUrl();

        $query = [];
        parse_str(parse_url($url, PHP_URL_QUERY), $query);

        // state is "{via}:{random}" so /auth/complete can recover the adapter.
        $this->assertMatchesRegularExpression('/^99:[0-9a-f]{32}$/', $query['state']);
    }

    public function testGetCurrentUserRejectsStateMismatch(): void
    {
        $_SESSION['oauth_state_fake'] = 'expected-state';
        $_GET['state'] = 'attacker-state';
        $_GET['code']  = 'whatever';

        $adapter = new FakeOAuth2Adapter($this->config());

        $this->expectException(\Zero\Core\Exception\HTTPError::class);
        $adapter->getCurrentUser();
    }

    public function testGetCurrentUserRejectsMissingCode(): void
    {
        $_SESSION['oauth_state_fake'] = 'expected-state';
        $_GET['state'] = 'expected-state';
        unset($_GET['code']);

        $adapter = new FakeOAuth2Adapter($this->config());

        $this->expectException(\Zero\Core\Exception\HTTPError::class);
        $adapter->getCurrentUser();
    }
}
