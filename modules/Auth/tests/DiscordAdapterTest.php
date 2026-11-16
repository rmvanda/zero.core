<?php

namespace Zero\Module\Auth\Tests;

use Zero\Tests\Core\ZeroTestCase;

require_once __DIR__ . '/../adapters/DiscordAdapter.php';

class DiscordAdapterTest extends ZeroTestCase
{
    private function config(): array
    {
        return [
            'client_id'     => 'dc-client',
            'client_secret' => 'shhh',
            'redirect_uri'  => 'https://unisolu.com/auth/complete?via=13',
        ];
    }

    private function rawUser(): array
    {
        return [
            'id'          => '80351110224678912',
            'username'    => 'nelly',
            'global_name' => 'Nelly',
            'email'       => 'nelly@discord.com',
            'verified'    => true,
            'avatar'      => '8342729096ea3675442027381ff50dfe',
        ];
    }

    public function testFormatMapsDiscordUserToStandardShape(): void
    {
        $adapter = new \DiscordAdapter($this->config());
        $fuser = $adapter->format($this->rawUser());

        $this->assertSame('Nelly', $fuser['full_name']);
        $this->assertSame('80351110224678912', $fuser['id']);
        $this->assertSame('nelly@discord.com', $fuser['email']);
        $this->assertTrue($fuser['verified']);
        $this->assertSame(
            'https://cdn.discordapp.com/avatars/80351110224678912/8342729096ea3675442027381ff50dfe.png',
            $fuser['pic']
        );
    }

    public function testFormatFallsBackToUsernameWhenGlobalNameMissing(): void
    {
        $adapter = new \DiscordAdapter($this->config());
        $raw = $this->rawUser();
        unset($raw['global_name']);

        $fuser = $adapter->format($raw);
        $this->assertSame('nelly', $fuser['full_name']);
    }

    public function testFormatMarksUnverifiedWhenVerifiedFalse(): void
    {
        $adapter = new \DiscordAdapter($this->config());
        $raw = $this->rawUser();
        $raw['verified'] = false;

        $fuser = $adapter->format($raw);
        $this->assertFalse($fuser['verified']);
    }

    public function testFormatPicNullWhenNoAvatar(): void
    {
        $adapter = new \DiscordAdapter($this->config());
        $raw = $this->rawUser();
        $raw['avatar'] = null;

        $fuser = $adapter->format($raw);
        $this->assertNull($fuser['pic']);
    }
}
