<?php
namespace Zero\Core\Module\Auth\Tests;

use Zero\Tests\Core\ZeroTestCase;

use Zero\Core\Module\Auth\OAuth\Broker;
use Zero\Lib\Crypto;
use Zero\Core\Module\Auth\OAuth\Token;

class OAuthBrokerTest extends ZeroTestCase
{
    private const U = 999000002;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connectTestDatabase();
        Crypto::$keyOverride = str_repeat('a', 64);
        Token::forget(self::U, 'discord');
        Token::forget(self::U, 'github');
    }

    protected function tearDown(): void
    {
        Token::forget(self::U, 'discord');
        Token::forget(self::U, 'github');
        Broker::$adapterResolver = null;
        Crypto::$keyOverride = null;
        parent::tearDown();
    }

    public function testReturnsNullWhenNotConnected(): void
    {
        $this->assertNull(Broker::accessToken(self::U, 'discord'));
    }

    public function testReturnsStoredTokenWhenFresh(): void
    {
        Token::store(self::U, 'discord', [
            'access_token' => 'FRESH',
            'expires_at'   => date('Y-m-d H:i:s', time() + 3600),
        ]);
        $this->assertSame('FRESH', Broker::accessToken(self::U, 'discord'));
    }

    public function testReturnsStoredTokenWhenExpiredButNoRefreshToken(): void
    {
        // GitHub-style: non-expiring / no refresh token available.
        Token::store(self::U, 'github', [
            'access_token' => 'GHO',
            'expires_at'   => date('Y-m-d H:i:s', time() - 10),
        ]);
        $this->assertSame('GHO', Broker::accessToken(self::U, 'github'));
    }

    public function testRefreshesStaleTokenAndPersists(): void
    {
        Token::store(self::U, 'discord', [
            'access_token'  => 'OLD',
            'refresh_token' => 'RT',
            'expires_at'    => date('Y-m-d H:i:s', time() - 10),
        ]);

        // Inject a fake adapter whose refresh() returns a new access token.
        Broker::$adapterResolver = fn(string $p) => new class {
            public function refresh(array $stored): ?array
            {
                return ['access_token' => 'NEW',
                        'expires_at' => date('Y-m-d H:i:s', time() + 3600)];
            }
        };

        $this->assertSame('NEW', Broker::accessToken(self::U, 'discord'));

        $row = Token::get(self::U, 'discord');
        $this->assertSame('NEW', $row['access_token']); // persisted
        $this->assertSame('RT', $row['refresh_token']);  // preserved
    }

    public function testConnectedProviders(): void
    {
        Token::store(self::U, 'discord', ['access_token' => 'a']);
        Token::store(self::U, 'github', ['access_token' => 'b']);
        $providers = Broker::connectedProviders(self::U);
        $this->assertContains('discord', $providers);
        $this->assertContains('github', $providers);
    }
}
