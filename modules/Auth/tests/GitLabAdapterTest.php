<?php

namespace Zero\Module\Auth\Tests;

use Zero\Tests\Core\ZeroTestCase;

require_once __DIR__ . '/../adapters/GitLabAdapter.php';

class GitLabAdapterTest extends ZeroTestCase
{
    private function config(): array
    {
        return [
            'client_id'     => 'gl-client',
            'client_secret' => 'shhh',
            'redirect_uri'  => 'https://unisolu.com/auth/complete?via=14',
        ];
    }

    private function rawUser(): array
    {
        return [
            'id'           => 42,
            'username'     => 'jdoe',
            'name'         => 'Jane Doe',
            'email'        => 'jane@gitlab.com',
            'avatar_url'   => 'https://gitlab.com/uploads/avatar.png',
            'confirmed_at' => '2020-01-01T00:00:00Z',
        ];
    }

    public function testFormatMapsGitlabUserToStandardShape(): void
    {
        $adapter = new \GitLabAdapter($this->config());
        $fuser = $adapter->format($this->rawUser());

        $this->assertSame('Jane Doe', $fuser['full_name']);
        $this->assertSame(42, $fuser['id']);
        $this->assertSame('jane@gitlab.com', $fuser['email']);
        $this->assertTrue($fuser['verified']);
        $this->assertSame('https://gitlab.com/uploads/avatar.png', $fuser['pic']);
    }

    public function testFormatFallsBackToUsernameWhenNameMissing(): void
    {
        $adapter = new \GitLabAdapter($this->config());
        $raw = $this->rawUser();
        unset($raw['name']);

        $fuser = $adapter->format($raw);
        $this->assertSame('jdoe', $fuser['full_name']);
    }

    public function testFormatMarksUnverifiedWhenConfirmedAtEmpty(): void
    {
        $adapter = new \GitLabAdapter($this->config());
        $raw = $this->rawUser();
        $raw['confirmed_at'] = null;

        $fuser = $adapter->format($raw);
        $this->assertFalse($fuser['verified']);
    }
}
