<?php

namespace Zero\Module\Auth\Tests;

use Zero\Tests\Core\ZeroTestCase;
use Zero\Module\Auth;

require_once __DIR__ . '/../Auth.php';

class AuthResolverTest extends ZeroTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // OAuth2Adapter::loadConfig() reads PRIMARY_DOMAIN to build a redirect_uri.
        // Application::defineConstants() (app/config/config.ini) doesn't run under
        // PHPUnit, so provide the same value it would in production (see
        // ZeroTestCase::connectTestDatabase() for the equivalent DB-config gap).
        if (!defined('PRIMARY_DOMAIN')) {
            define('PRIMARY_DOMAIN', 'unisolu.com');
        }
    }

    public function testResolvesGithubAdapter(): void
    {
        $adapter = Auth::adapterForProvider('github');
        $this->assertInstanceOf(\GitHubAdapter::class, $adapter);
    }

    public function testUnknownProviderReturnsNull(): void
    {
        $this->assertNull(Auth::adapterForProvider('myspace'));
    }

    public function testKeycloakIsNotPersistCapable(): void
    {
        // Keycloak is a test-only OIDC surface, excluded from token persistence.
        $this->assertNull(Auth::adapterForProvider('keycloak'));
    }
}
