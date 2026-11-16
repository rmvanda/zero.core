<?php
namespace Zero\Module\Auth\Tests;

use Zero\Tests\Core\ZeroTestCase;
use Zero\Module\Auth\Model\AuthToken;
use Zero\Core\Database;

class AuthTokenTest extends ZeroTestCase
{
    private const U = 999000042;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connectTestDatabase();
        $this->cleanup();
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    private function cleanup(): void
    {
        Database::getConnection()->prepare("DELETE FROM auth_token WHERE user_id = ?")->execute([self::U]);
    }

    public function testIssueAndConsumeRoundTrip(): void
    {
        $token = AuthToken::issue(self::U, 'login', 900);
        $this->assertStringContainsString(':', $token);
        $this->assertSame(self::U, AuthToken::consume($token, 'login'));
    }

    public function testSecondConsumeIsRejected(): void
    {
        $token = AuthToken::issue(self::U, 'login', 900);
        AuthToken::consume($token, 'login');
        $this->assertNull(AuthToken::consume($token, 'login'), 'replay must fail');
    }

    public function testWrongValidatorIsRejected(): void
    {
        $token = AuthToken::issue(self::U, 'login', 900);
        [$selector, ] = explode(':', $token, 2);
        $this->assertNull(AuthToken::consume($selector . ':wrongwrongwrong', 'login'));
    }

    public function testWrongPurposeIsRejected(): void
    {
        // A verify token must never be usable as a login token.
        $token = AuthToken::issue(self::U, 'verify', 900);
        $this->assertNull(AuthToken::consume($token, 'login'));
        $this->assertSame(self::U, AuthToken::consume($token, 'verify'));
    }

    public function testExpiredTokenIsRejected(): void
    {
        $token = AuthToken::issue(self::U, 'login', -10);   // already expired
        $this->assertNull(AuthToken::consume($token, 'login'));
    }

    public function testMalformedTokenIsRejected(): void
    {
        $this->assertNull(AuthToken::consume('no-colon-here', 'login'));
        $this->assertNull(AuthToken::consume('', 'login'));
    }

    public function testValidatorIsNotStoredInPlaintext(): void
    {
        $token = AuthToken::issue(self::U, 'login', 900);
        [$selector, $validator] = explode(':', $token, 2);

        $stmt = Database::getConnection()->prepare("SELECT validator FROM auth_token WHERE selector = ?");
        $stmt->execute([$selector]);
        $stored = $stmt->fetchColumn();

        $this->assertNotSame($validator, $stored);
        $this->assertSame(hash('sha256', $validator), $stored);
    }

    public function testLiveCountForUserIgnoresConsumedAndExpired(): void
    {
        $live = AuthToken::issue(self::U, 'login', 900);
        AuthToken::consume(AuthToken::issue(self::U, 'login', 900), 'login');
        AuthToken::issue(self::U, 'login', -10);

        $this->assertSame(1, AuthToken::liveCountForUser(self::U, 'login'));
        $this->assertNotNull($live);
    }

    public function testBurnOldestConsumesOneLiveTokenAndLeavesTheRest(): void
    {
        // The throttle relies on this to cap outstanding links: issuing a 4th
        // live token burns the oldest rather than letting them accumulate.
        $first  = AuthToken::issue(self::U, 'login', 900);
        $second = AuthToken::issue(self::U, 'login', 900);
        $third  = AuthToken::issue(self::U, 'login', 900);
        $this->assertSame(3, AuthToken::liveCountForUser(self::U, 'login'));

        AuthToken::burnOldest(self::U, 'login');

        $this->assertSame(2, AuthToken::liveCountForUser(self::U, 'login'));

        // Exactly one was burned, and the two survivors are still usable.
        $survivors = 0;
        foreach ([$first, $second, $third] as $token) {
            if (AuthToken::consume($token, 'login') !== null) {
                $survivors++;
            }
        }
        $this->assertSame(2, $survivors);
    }

    public function testBurnOldestOnAUserWithNoTokensIsANoOp(): void
    {
        AuthToken::burnOldest(self::U, 'login');
        $this->assertSame(0, AuthToken::liveCountForUser(self::U, 'login'));
    }

    public function testIssuedFromIpSinceCounts(): void
    {
        $_SERVER['REMOTE_ADDR'] = '203.0.113.99';
        AuthToken::issue(self::U, 'login', 900);
        AuthToken::issue(self::U, 'login', 900);

        $this->assertSame(2, AuthToken::issuedFromIpSince('203.0.113.99', 3600));
        $this->assertSame(0, AuthToken::issuedFromIpSince('203.0.113.1', 3600));
    }

    public function testPurgeExpiredRemovesOnlyExpiredRows(): void
    {
        AuthToken::issue(self::U, 'login', 900);
        AuthToken::issue(self::U, 'login', -10);

        AuthToken::purgeExpired();

        $stmt = Database::getConnection()->prepare("SELECT COUNT(*) FROM auth_token WHERE user_id = ?");
        $stmt->execute([self::U]);
        $this->assertSame(1, (int) $stmt->fetchColumn());
    }
}
