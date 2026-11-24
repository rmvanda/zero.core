<?php
namespace Zero\Core\Module\Auth\Tests;

use Zero\Tests\Core\ZeroTestCase;
use Zero\Core\Module\Auth;
use Zero\Core\Exception\HTTPError;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The verified-email invariant, tested where it now lives.
 *
 * Auth::complete() matches accounts by email ALONE, so an unverified provider
 * identity would let anyone who can set an address at a sloppy provider take
 * over the matching account. Every adapter used to carry its own copy of this
 * check; they now report faithfully and Auth gates their output, so this is the
 * only place it needs testing — and a new adapter cannot skip it.
 *
 * Migrated from OAuth2AdapterTest, which could only ever cover one adapter.
 */
class VerifiedEmailTest extends ZeroTestCase
{
    public function testAcceptsAVerifiedIdentity(): void
    {
        Auth::enforceVerifiedEmail(['email' => 'a@b.com', 'verified' => true]);

        // No exception is the assertion; make it explicit for the reader.
        $this->addToAssertionCount(1);
    }

    /**
     * The gate must run against a LOCAL identity, before anything is published
     * to the session.
     *
     * complete() used to assign $_SESSION['user'] on both of its branches
     * before reaching this call, so a refused sign-in left the session holding
     * a provider-controlled identity — including the provider's own user id
     * under 'id', which Session::userId() then read as a fallback. This pins
     * the ordering that fix depends on: when the gate throws, nothing has been
     * written.
     *
     * complete() itself is not directly testable — it needs a live adapter and
     * a provider round-trip — so this covers the invariant rather than the
     * method. Re-check complete() by hand after touching its opening block.
     */
    public function testARefusedIdentityLeavesNoSessionUser(): void
    {
        $_SESSION = [];

        try {
            Auth::enforceVerifiedEmail(['email' => 'attacker@example.invalid', 'verified' => false]);
            $this->fail('an unverified identity must be refused');
        } catch (HTTPError $e) {
            $this->assertSame(403, $e->getCode());
        }

        $this->assertArrayNotHasKey('user', $_SESSION,
            'the gate must not have published a provider identity');
        $this->assertNull(\Zero\Core\Session::userId(),
            'a refused sign-in must not leave a resolvable user id behind');
    }

    public function testRejectsAMissingEmail(): void
    {
        $this->expectException(HTTPError::class);
        $this->expectExceptionCode(401);
        Auth::enforceVerifiedEmail(['email' => '', 'verified' => true]);
    }

    public function testRejectsAnAbsentEmailKey(): void
    {
        $this->expectException(HTTPError::class);
        $this->expectExceptionCode(401);
        Auth::enforceVerifiedEmail(['verified' => true]);
    }

    public function testRejectsAnUnverifiedEmail(): void
    {
        $this->expectException(HTTPError::class);
        $this->expectExceptionCode(403);
        Auth::enforceVerifiedEmail(['email' => 'a@b.com', 'verified' => false]);
    }

    public function testRejectsAnAbsentVerifiedKey(): void
    {
        // Fails closed: an adapter that forgets to report verification at all
        // must not be treated as having verified anything.
        $this->expectException(HTTPError::class);
        $this->expectExceptionCode(403);
        Auth::enforceVerifiedEmail(['email' => 'a@b.com']);
    }

    /**
     * GoogleAdapter passes Google's value through with only a filter_var
     * normalization, and Google reports verified_email on the userinfo path but
     * email_verified on the one-tap ID token. A strict === true comparison here
     * would read a "true" string as unverified and lock the user out — which is
     * a lockout, not merely a false negative, so it matters.
     *
     */
    #[DataProvider('truthyVerifiedValues')]
    public function testAcceptsAnyTruthyVerifiedValue($value): void
    {
        Auth::enforceVerifiedEmail(['email' => 'a@b.com', 'verified' => $value]);
        $this->addToAssertionCount(1);
    }

    public static function truthyVerifiedValues(): array
    {
        return [
            'bool true'   => [true],
            'string true' => ['true'],
            'int one'     => [1],
            'string one'  => ['1'],
        ];
    }

    #[DataProvider('falsyVerifiedValues')]
    public function testRejectsAnyFalsyVerifiedValue($value): void
    {
        $this->expectException(HTTPError::class);
        $this->expectExceptionCode(403);
        Auth::enforceVerifiedEmail(['email' => 'a@b.com', 'verified' => $value]);
    }

    public static function falsyVerifiedValues(): array
    {
        return [
            'bool false'   => [false],
            'string false' => ['false'],
            'int zero'     => [0],
            'string zero'  => ['0'],
            'empty string' => [''],
            'null'         => [null],
        ];
    }
}
