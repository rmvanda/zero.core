<?php
namespace Zero\Tests\Core;

use Zero\Core\TokenBroker;
use Zero\Core\Crypto;
use Zero\Entity\OAuthToken;

class TokenBrokerTest extends ZeroTestCase
{
    private const U = 999000002;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connectTestDatabase();
        Crypto::$keyOverride = str_repeat('a', 64);
        OAuthToken::forget(self::U, 'discord');
        OAuthToken::forget(self::U, 'github');
    }

    protected function tearDown(): void
    {
        OAuthToken::forget(self::U, 'discord');
        OAuthToken::forget(self::U, 'github');
        TokenBroker::$adapterResolver = null;
        Crypto::$keyOverride = null;
        parent::tearDown();
    }

    public function testReturnsNullWhenNotConnected(): void
    {
        $this->assertNull(TokenBroker::accessToken(self::U, 'discord'));
    }

    public function testReturnsStoredTokenWhenFresh(): void
    {
        OAuthToken::store(self::U, 'discord', [
            'access_token' => 'FRESH',
            'expires_at'   => date('Y-m-d H:i:s', time() + 3600),
        ]);
        $this->assertSame('FRESH', TokenBroker::accessToken(self::U, 'discord'));
    }

    public function testReturnsStoredTokenWhenExpiredButNoRefreshToken(): void
    {
        // GitHub-style: non-expiring / no refresh token available.
        OAuthToken::store(self::U, 'github', [
            'access_token' => 'GHO',
            'expires_at'   => date('Y-m-d H:i:s', time() - 10),
        ]);
        $this->assertSame('GHO', TokenBroker::accessToken(self::U, 'github'));
    }

    public function testRefreshesStaleTokenAndPersists(): void
    {
        OAuthToken::store(self::U, 'discord', [
            'access_token'  => 'OLD',
            'refresh_token' => 'RT',
            'expires_at'    => date('Y-m-d H:i:s', time() - 10),
        ]);

        // Inject a fake adapter whose refresh() returns a new access token.
        TokenBroker::$adapterResolver = fn(string $p) => new class {
            public function refresh(array $stored): ?array
            {
                return ['access_token' => 'NEW',
                        'expires_at' => date('Y-m-d H:i:s', time() + 3600)];
            }
        };

        $this->assertSame('NEW', TokenBroker::accessToken(self::U, 'discord'));

        $row = OAuthToken::get(self::U, 'discord');
        $this->assertSame('NEW', $row['access_token']); // persisted
        $this->assertSame('RT', $row['refresh_token']);  // preserved
    }

    public function testConnectedProviders(): void
    {
        OAuthToken::store(self::U, 'discord', ['access_token' => 'a']);
        OAuthToken::store(self::U, 'github', ['access_token' => 'b']);
        $providers = TokenBroker::connectedProviders(self::U);
        $this->assertContains('discord', $providers);
        $this->assertContains('github', $providers);
    }
}
