<?php
namespace Zero\Tests\Core;

use Zero\Core\Crypto;

class CryptoTest extends ZeroTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Crypto::$keyOverride = str_repeat('a', 64); // 64 hex = 32 bytes
    }

    protected function tearDown(): void
    {
        Crypto::$keyOverride = null;
        parent::tearDown();
    }

    public function testRoundTrip(): void
    {
        $plain = 'ya29.super-secret-token';
        $this->assertSame($plain, Crypto::decrypt(Crypto::encrypt($plain)));
    }

    public function testRoundTripHandlesLongValue(): void
    {
        $plain = str_repeat('x', 4000); // > 255, like a Google id_token blob
        $this->assertSame($plain, Crypto::decrypt(Crypto::encrypt($plain)));
    }

    public function testCiphertextIsRandomizedPerCall(): void
    {
        $a = Crypto::encrypt('same');
        $b = Crypto::encrypt('same');
        $this->assertNotSame('same', $a);
        $this->assertNotSame($a, $b); // random IV each call
    }

    public function testTamperedCiphertextReturnsNull(): void
    {
        $raw = base64_decode(Crypto::encrypt('secret'), true);
        $last = strlen($raw) - 1;
        $raw[$last] = ($raw[$last] === "\x00") ? "\x01" : "\x00"; // flip last byte
        $this->assertNull(Crypto::decrypt(base64_encode($raw)));
    }

    public function testWrongKeyReturnsNull(): void
    {
        $blob = Crypto::encrypt('secret');
        Crypto::$keyOverride = str_repeat('b', 64);
        $this->assertNull(Crypto::decrypt($blob));
    }

    public function testInvalidKeyThrows(): void
    {
        Crypto::$keyOverride = 'not-hex';
        $this->expectException(\RuntimeException::class);
        Crypto::encrypt('secret');
    }

    public function testGarbageInputReturnsNull(): void
    {
        $this->assertNull(Crypto::decrypt('!!!not base64!!!'));
        $this->assertNull(Crypto::decrypt(''));
    }

    public function testIsEncryptedDetectsEnvelope(): void
    {
        $this->assertTrue(Crypto::isEncrypted(Crypto::encrypt('x')));
        $this->assertFalse(Crypto::isEncrypted('plainstring'));
    }
}
