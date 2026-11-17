<?php

namespace Zero\Module\Auth\Tests;

use Zero\Tests\Core\ZeroTestCase;

require_once __DIR__ . '/../adapters/KeycloakAdapter.php';

class KeycloakAdapterTest extends ZeroTestCase
{
    /** Minimal valid config for constructing the adapter without the JSON file. */
    private function config(): array
    {
        return [
            'base_url'      => 'https://kc.example.com',
            'realm'         => 'unisolu',
            'client_id'     => 'unisolu-zero',
            'client_secret' => 'shhh',
            'redirect_uri'  => 'https://unisolu.com/auth/complete?via=11',
        ];
    }

    public function testBuildAuthorizationUrlContainsOidcParams(): void
    {
        $adapter = new \KeycloakAdapter($this->config());
        $url = $adapter->buildAuthorizationUrl();

        $this->assertStringStartsWith(
            'https://kc.example.com/realms/unisolu/protocol/openid-connect/auth?',
            $url
        );

        $query = [];
        parse_str(parse_url($url, PHP_URL_QUERY), $query);

        $this->assertSame('unisolu-zero', $query['client_id']);
        $this->assertSame('https://unisolu.com/auth/complete?via=11', $query['redirect_uri']);
        $this->assertSame('code', $query['response_type']);
        $this->assertSame('openid email profile', $query['scope']);
        $this->assertNotEmpty($query['state']);
        $this->assertNotEmpty($query['nonce']);
    }

    public function testBuildAuthorizationUrlStoresStateAndNonceInSession(): void
    {
        $adapter = new \KeycloakAdapter($this->config());
        $url = $adapter->buildAuthorizationUrl();

        $query = [];
        parse_str(parse_url($url, PHP_URL_QUERY), $query);

        $this->assertSame($_SESSION['kc_state'], $query['state']);
        $this->assertSame($_SESSION['kc_nonce'], $query['nonce']);
    }

    /** Build an RSA signing key with a fixed kid. */
    private function signingKey(): \Jose\Component\Core\JWK
    {
        return \Jose\Component\KeyManagement\JWKFactory::createRSAKey(
            2048,
            ['alg' => 'RS256', 'use' => 'sig', 'kid' => 'test-kid']
        );
    }

    /** JWKS JSON (public half) for the given signing key. */
    private function jwksJson(\Jose\Component\Core\JWK $key): string
    {
        return json_encode(['keys' => [$key->toPublic()->jsonSerialize()]]);
    }

    /** Sign a compact RS256 JWS for the given claims. */
    private function signToken(\Jose\Component\Core\JWK $key, array $claims): string
    {
        $algManager = new \Jose\Component\Core\AlgorithmManager([
            new \Jose\Component\Signature\Algorithm\RS256(),
        ]);
        $builder = new \Jose\Component\Signature\JWSBuilder($algManager);
        $jws = $builder->create()
            ->withPayload(json_encode($claims))
            ->addSignature($key, ['alg' => 'RS256', 'kid' => 'test-kid'])
            ->build();

        return (new \Jose\Component\Signature\Serializer\CompactSerializer())->serialize($jws, 0);
    }

    /** Valid claim set matching the test config + session nonce. */
    private function validClaims(): array
    {
        return [
            'iss'            => 'https://kc.example.com/realms/unisolu',
            'aud'            => 'unisolu-zero',
            'exp'            => time() + 300,
            'nonce'          => 'test-nonce',
            'sub'            => 'kc-user-123',
            'email'          => 'jdoe@example.com',
            'email_verified' => true,
            'name'           => 'Jane Doe',
            'given_name'     => 'Jane',
            'family_name'    => 'Doe',
            'picture'        => 'https://kc.example.com/avatar.png',
        ];
    }

    public function testValidateIdTokenReturnsClaimsForValidToken(): void
    {
        $_SESSION['kc_nonce'] = 'test-nonce';
        $key = $this->signingKey();
        $token = $this->signToken($key, $this->validClaims());

        $adapter = new \KeycloakAdapter($this->config());
        $claims = $adapter->validateIdToken($token, $this->jwksJson($key));

        $this->assertSame('jdoe@example.com', $claims['email']);
        $this->assertSame('kc-user-123', $claims['sub']);
    }

    public function testValidateIdTokenRejectsWrongIssuer(): void
    {
        $_SESSION['kc_nonce'] = 'test-nonce';
        $key = $this->signingKey();
        $claims = $this->validClaims();
        $claims['iss'] = 'https://evil.example.com/realms/unisolu';
        $token = $this->signToken($key, $claims);

        $adapter = new \KeycloakAdapter($this->config());

        $this->expectException(\Zero\Core\Exception\HTTPError::class);
        $adapter->validateIdToken($token, $this->jwksJson($key));
    }

    public function testValidateIdTokenRejectsExpiredToken(): void
    {
        $_SESSION['kc_nonce'] = 'test-nonce';
        $key = $this->signingKey();
        $claims = $this->validClaims();
        $claims['exp'] = time() - 10;
        $token = $this->signToken($key, $claims);

        $adapter = new \KeycloakAdapter($this->config());

        $this->expectException(\Zero\Core\Exception\HTTPError::class);
        $adapter->validateIdToken($token, $this->jwksJson($key));
    }

    public function testValidateIdTokenRejectsBadSignature(): void
    {
        $_SESSION['kc_nonce'] = 'test-nonce';
        $signingKey = $this->signingKey();
        $token = $this->signToken($signingKey, $this->validClaims());

        // Present a DIFFERENT key in the JWKS so the signature cannot verify.
        $otherKey = \Jose\Component\KeyManagement\JWKFactory::createRSAKey(
            2048,
            ['alg' => 'RS256', 'use' => 'sig', 'kid' => 'test-kid']
        );

        $adapter = new \KeycloakAdapter($this->config());

        $this->expectException(\Zero\Core\Exception\HTTPError::class);
        $adapter->validateIdToken($token, $this->jwksJson($otherKey));
    }

    public function testValidateIdTokenRejectsWrongAudience(): void
    {
        $_SESSION['kc_nonce'] = 'test-nonce';
        $key = $this->signingKey();
        $claims = $this->validClaims();
        $claims['aud'] = 'wrong-client';
        $token = $this->signToken($key, $claims);

        $adapter = new \KeycloakAdapter($this->config());

        $this->expectException(\Zero\Core\Exception\HTTPError::class);
        $adapter->validateIdToken($token, $this->jwksJson($key));
    }

    public function testValidateIdTokenRejectsNonceMismatch(): void
    {
        $_SESSION['kc_nonce'] = 'test-nonce';
        $key = $this->signingKey();
        $claims = $this->validClaims();
        $claims['nonce'] = 'different-nonce';
        $token = $this->signToken($key, $claims);

        $adapter = new \KeycloakAdapter($this->config());

        $this->expectException(\Zero\Core\Exception\HTTPError::class);
        $adapter->validateIdToken($token, $this->jwksJson($key));
    }

    public function testValidateIdTokenRejectsMissingSessionNonce(): void
    {
        unset($_SESSION['kc_nonce']);
        $key = $this->signingKey();
        $token = $this->signToken($key, $this->validClaims());

        $adapter = new \KeycloakAdapter($this->config());

        $this->expectException(\Zero\Core\Exception\HTTPError::class);
        $adapter->validateIdToken($token, $this->jwksJson($key));
    }

    public function testValidateIdTokenRejectsMissingSessionNonceAndNonceLessToken(): void
    {
        unset($_SESSION['kc_nonce']);
        $key = $this->signingKey();
        $claims = $this->validClaims();
        unset($claims['nonce']);
        $token = $this->signToken($key, $claims);

        $adapter = new \KeycloakAdapter($this->config());

        $this->expectException(\Zero\Core\Exception\HTTPError::class);
        $adapter->validateIdToken($token, $this->jwksJson($key));
    }

    public function testValidateIdTokenRejectsMultiAudienceWithoutMatchingAzp(): void
    {
        $_SESSION['kc_nonce'] = 'test-nonce';
        $key = $this->signingKey();
        $claims = $this->validClaims();
        $claims['aud'] = ['unisolu-zero', 'other-client'];
        $claims['azp'] = 'other-client';
        $token = $this->signToken($key, $claims);

        $adapter = new \KeycloakAdapter($this->config());

        $this->expectException(\Zero\Core\Exception\HTTPError::class);
        $adapter->validateIdToken($token, $this->jwksJson($key));
    }

    public function testValidateIdTokenAcceptsMultiAudienceWithMatchingAzp(): void
    {
        $_SESSION['kc_nonce'] = 'test-nonce';
        $key = $this->signingKey();
        $claims = $this->validClaims();
        $claims['aud']   = ['unisolu-zero', 'other-client'];
        $claims['azp']   = 'unisolu-zero';
        $claims['nonce'] = 'test-nonce';
        $token = $this->signToken($key, $claims);

        $adapter = new \KeycloakAdapter($this->config());
        $result = $adapter->validateIdToken($token, $this->jwksJson($key));

        $this->assertSame('jdoe@example.com', $result['email']);
    }

    public function testGetCurrentUserRejectsStateMismatch(): void
    {
        $_SESSION['kc_state'] = 'expected-state';
        $_GET['state'] = 'attacker-state';
        $_GET['code'] = 'whatever';

        $adapter = new \KeycloakAdapter($this->config());

        $this->expectException(\Zero\Core\Exception\HTTPError::class);
        $adapter->getCurrentUser();
    }

    public function testGetCurrentUserRejectsMissingCode(): void
    {
        $_SESSION['kc_state'] = 'expected-state';
        $_GET['state'] = 'expected-state';
        unset($_GET['code']);

        $adapter = new \KeycloakAdapter($this->config());

        $this->expectException(\Zero\Core\Exception\HTTPError::class);
        $adapter->getCurrentUser();
    }

    public function testValidateIdTokenRejectsUnverifiedEmail(): void
    {
        $_SESSION['kc_nonce'] = 'test-nonce';
        $key = $this->signingKey();
        $claims = $this->validClaims();
        $claims['email_verified'] = false;
        $token = $this->signToken($key, $claims);

        $adapter = new \KeycloakAdapter($this->config());

        $this->expectException(\Zero\Core\Exception\HTTPError::class);
        $adapter->validateIdToken($token, $this->jwksJson($key));
    }

    public function testValidateIdTokenRejectsMissingEmail(): void
    {
        $_SESSION['kc_nonce'] = 'test-nonce';
        $key = $this->signingKey();
        $claims = $this->validClaims();
        unset($claims['email']);
        $token = $this->signToken($key, $claims);

        $adapter = new \KeycloakAdapter($this->config());

        $this->expectException(\Zero\Core\Exception\HTTPError::class);
        $adapter->validateIdToken($token, $this->jwksJson($key));
    }

    public function testFormatMapsKeycloakClaimsToStandardShape(): void
    {
        $adapter = new \KeycloakAdapter($this->config());
        $fuser = $adapter->format($this->validClaims());

        $this->assertSame('Jane Doe', $fuser['full_name']);
        $this->assertSame('kc-user-123', $fuser['id']);
        $this->assertSame('jdoe@example.com', $fuser['email']);
        $this->assertTrue($fuser['verified']);
        $this->assertSame('https://kc.example.com/avatar.png', $fuser['pic']);
    }
}
