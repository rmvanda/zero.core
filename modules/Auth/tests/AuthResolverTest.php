<?php

namespace Zero\Module\Auth\Tests;

use Zero\Tests\Core\ZeroTestCase;
use Zero\Module\Auth;


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
        $this->assertInstanceOf(\Zero\Module\Auth\Adapters\GitHubAdapter::class, $adapter);
    }

    public function testUnknownProviderReturnsNull(): void
    {
        $this->assertNull(Auth::adapterForProvider('myspace'));
    }
}
