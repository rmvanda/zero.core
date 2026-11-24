<?php
namespace Zero\Core\Module\Auth\Tests;

use Zero\Tests\Core\ZeroTestCase;
use Zero\Core\Module\Auth\OAuth\Token;
use Zero\Lib\Crypto;
use Zero\Core\Database;

class OAuthTokenTest extends ZeroTestCase
{
    /** Sentinel user id unlikely to collide with real users. */
    private const U = 999000001;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connectTestDatabase();
        Crypto::$keyOverride = str_repeat('a', 64);
        Token::forget(self::U, 'github');
        Token::forget(self::U, 'google');
    }

    protected function tearDown(): void
    {
        Token::forget(self::U, 'github');
        Token::forget(self::U, 'google');
        Crypto::$keyOverride = null;
        parent::tearDown();
    }

    public function testStoreAndGetRoundTrip(): void
    {
        Token::store(self::U, 'github', [
            'access_token'     => 'gho_secret',
            'refresh_token'    => 'r3fr3sh',
            'token_type'       => 'Bearer',
            'scope'            => 'read:user user:email',
            'provider_user_id' => '12345',
            'expires_at'       => null,
        ]);

        $row = Token::get(self::U, 'github');
        $this->assertSame('gho_secret', $row['access_token']);
        $this->assertSame('r3fr3sh', $row['refresh_token']);
        $this->assertSame('read:user user:email', $row['scope']);
        $this->assertSame('12345', $row['provider_user_id']);
    }

    public function testSecretsAreEncryptedAtRest(): void
    {
        Token::store(self::U, 'github', ['access_token' => 'gho_plaintext']);

        $stmt = Database::getConnection()->prepare(
            "SELECT access_token FROM oauth_token WHERE user_id = ? AND provider = ?"
        );
        $stmt->execute([self::U, 'github']);
        $raw = $stmt->fetchColumn();

        $this->assertNotSame('gho_plaintext', $raw);
        $this->assertTrue(Crypto::isEncrypted($raw));
    }

    public function testPartialUpdatePreservesOmittedColumns(): void
    {
        Token::store(self::U, 'github', [
            'access_token'  => 'first_access',
            'refresh_token' => 'keep_me',
        ]);
        // Simulate a refresh that returns only a new access token.
        Token::store(self::U, 'github', ['access_token' => 'second_access']);

        $row = Token::get(self::U, 'github');
        $this->assertSame('second_access', $row['access_token']);
        $this->assertSame('keep_me', $row['refresh_token']); // preserved
    }

    public function testProvidersForListsConnected(): void
    {
        Token::store(self::U, 'github', ['access_token' => 'a']);
        Token::store(self::U, 'google', ['access_token' => 'b']);
        $providers = Token::providersFor(self::U);
        $this->assertContains('github', $providers);
        $this->assertContains('google', $providers);
    }

    public function testGetReturnsNullWhenNotConnected(): void
    {
        $this->assertNull(Token::get(self::U, 'discord'));
    }

    public function testForgetRemovesRow(): void
    {
        Token::store(self::U, 'github', ['access_token' => 'a']);
        Token::forget(self::U, 'github');
        $this->assertNull(Token::get(self::U, 'github'));
    }
}
