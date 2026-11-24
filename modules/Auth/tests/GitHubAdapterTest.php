<?php

namespace Zero\Core\Module\Auth\Tests;

use Zero\Tests\Core\ZeroTestCase;

use Zero\Core\Module\Auth\Adapters\GitHubAdapter;

class GitHubAdapterTest extends ZeroTestCase
{
    private function config(): array
    {
        return [
            'client_id'     => 'gh-client',
            'client_secret' => 'shhh',
            'redirect_uri'  => 'https://unisolu.com/auth/complete?via=12',
        ];
    }

    /** A /user payload already merged with the resolved verified email. */
    private function rawProfile(): array
    {
        return [
            'login'          => 'octocat',
            'id'             => 583231,
            'name'           => 'The Octocat',
            'avatar_url'     => 'https://avatars.githubusercontent.com/u/583231',
            'email'          => 'octo@github.com',
            'email_verified' => true,
        ];
    }

    public function testFormatMapsGithubProfileToStandardShape(): void
    {
        $adapter = new GitHubAdapter($this->config());
        $fuser = $adapter->format($this->rawProfile());

        $this->assertSame('The Octocat', $fuser['full_name']);
        $this->assertSame(583231, $fuser['id']);
        $this->assertSame('octo@github.com', $fuser['email']);
        $this->assertTrue($fuser['verified']);
        $this->assertSame('https://avatars.githubusercontent.com/u/583231', $fuser['pic']);
    }

    public function testFormatFallsBackToLoginWhenNameNull(): void
    {
        $adapter = new GitHubAdapter($this->config());
        $raw = $this->rawProfile();
        $raw['name'] = null;

        $fuser = $adapter->format($raw);
        $this->assertSame('octocat', $fuser['full_name']);
    }

    public function testSelectVerifiedPrimaryEmailReturnsPrimaryVerified(): void
    {
        $adapter = new GitHubAdapter($this->config());
        $emails = [
            ['email' => 'alt@github.com',  'primary' => false, 'verified' => true],
            ['email' => 'octo@github.com', 'primary' => true,  'verified' => true],
        ];

        $picked = $adapter->selectVerifiedPrimaryEmail($emails);
        $this->assertSame('octo@github.com', $picked['email']);
    }

    public function testSelectVerifiedPrimaryEmailReturnsNullWhenPrimaryUnverified(): void
    {
        $adapter = new GitHubAdapter($this->config());
        $emails = [
            ['email' => 'octo@github.com', 'primary' => true,  'verified' => false],
            ['email' => 'alt@github.com',  'primary' => false, 'verified' => true],
        ];

        $this->assertNull($adapter->selectVerifiedPrimaryEmail($emails));
    }

    public function testFormatMarksUnverifiedWhenNoPrimaryEmailResolved(): void
    {
        $adapter = new GitHubAdapter($this->config());
        $raw = $this->rawProfile();
        $raw['email'] = null;
        $raw['email_verified'] = false;

        $fuser = $adapter->format($raw);
        $this->assertFalse($fuser['verified']);
        $this->assertNull($fuser['email']);
    }
}
