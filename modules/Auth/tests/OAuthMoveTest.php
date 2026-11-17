<?php
namespace Zero\Module\Auth\Tests;

use Zero\Tests\Core\ZeroTestCase;
use Zero\Module\Auth\OAuth\Token;
use Zero\Module\Auth\OAuth\Provider;
use Zero\Module\Auth\OAuth\Broker;
use Zero\Lib\Crypto;
use Zero\Core\Database;

/**
 * The OAuth storage classes live in the Auth module. Rows written by the old
 * Zero\Entity\OAuthToken code path stay readable.
 */
class OAuthMoveTest extends ZeroTestCase
{
    /** Sentinel user id unlikely to collide with real users. */
    private const U = 999000003;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connectTestDatabase();
        Crypto::$keyOverride = str_repeat('a', 64);
        $this->clean();
    }

    protected function tearDown(): void
    {
        $this->clean();
        Crypto::$keyOverride = null;
        parent::tearDown();
    }

    private function clean(): void
    {
        Database::getConnection()
            ->prepare("DELETE FROM oauth_token WHERE user_id = ? AND provider LIKE 'zerotest-%'")
            ->execute([self::U]);
    }

    /** Writes a row exactly as Zero\Entity\OAuthToken::store() did before the move. */
    private function storeTheOldWay(int $userId, string $provider, array $fields): void
    {
        $secret = ['access_token', 'refresh_token'];
        $cols   = array_keys($fields);
        $params = [$userId, $provider];
        foreach ($cols as $col) {
            $value    = (string) $fields[$col];
            $params[] = in_array($col, $secret, true) ? Crypto::encrypt($value) : $value;
        }
        $insertCols   = array_merge(['user_id', 'provider'], $cols);
        $placeholders = implode(', ', array_fill(0, count($insertCols), '?'));
        $updates      = implode(', ', array_map(fn($c) => "$c = VALUES($c)", $cols));
        Database::getConnection()->prepare(
            "INSERT INTO oauth_token (" . implode(', ', $insertCols) . ") VALUES ($placeholders) "
            . "ON DUPLICATE KEY UPDATE $updates"
        )->execute($params);
    }

    private function rawAccessToken(string $provider): ?string
    {
        $stmt = Database::getConnection()->prepare(
            "SELECT access_token FROM oauth_token WHERE user_id = ? AND provider = ?"
        );
        $stmt->execute([self::U, $provider]);
        $v = $stmt->fetchColumn();
        return $v === false ? null : $v;
    }

    public function testClassesLiveInAuth(): void
    {
        $this->assertTrue(class_exists(Token::class));
        $this->assertTrue(class_exists(Provider::class));
        $this->assertTrue(class_exists(Broker::class));
        $this->assertFalse(class_exists('Zero\Entity\OAuthToken'));
        $this->assertFalse(class_exists('Zero\Entity\OAuthProvider'));
        $this->assertFalse(class_exists('Zero\Core\TokenBroker'));
    }

    public function testReadsARowWrittenBeforeTheMove(): void
    {
        $bundle = ['access_token' => 'plain-new', 'refresh_token' => 'refresh-new', 'scope' => 'a b'];
        Token::store(self::U, 'zerotest-prov', $bundle);

        $raw = $this->rawAccessToken('zerotest-prov');
        $this->assertNotNull($raw);
        $this->assertNotSame('plain-new', $raw);

        $connections = Token::connectionsFor(self::U);
        $this->assertArrayHasKey('zerotest-prov', $connections);
        $row = Token::get(self::U, 'zerotest-prov');
        $this->assertSame('plain-new', $row['access_token']);
        $this->assertSame('refresh-new', $row['refresh_token']);
        $this->assertSame('a b', $row['scope']);

        $old = ['access_token' => 'plain-old', 'refresh_token' => 'refresh-old', 'scope' => 'c d'];
        $this->storeTheOldWay(self::U, 'zerotest-old', $old);
        $this->assertNotSame('plain-old', $this->rawAccessToken('zerotest-old'));
        $this->assertArrayHasKey('zerotest-old', Token::connectionsFor(self::U));
        $row = Token::get(self::U, 'zerotest-old');
        $this->assertSame('plain-old', $row['access_token']);
        $this->assertSame('refresh-old', $row['refresh_token']);
        $this->assertSame('c d', $row['scope']);
    }
}
