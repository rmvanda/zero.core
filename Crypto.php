<?php
namespace Zero\Core;

/**
 * Reusable authenticated at-rest encryption (AES-256-GCM).
 *
 * Table-agnostic: any column can store Crypto::encrypt($plain) and recover it
 * with Crypto::decrypt($blob). The IV and GCM tag travel inside the returned
 * string, so no companion columns are needed.
 *
 * Envelope: base64( version(1 byte) . iv(12) . tag(16) . ciphertext ).
 * The version byte reserves clean key rotation.
 *
 * Key: APP_ENCRYPTION_KEY (64 hex chars = 32 bytes) from app/config/crypto.ini,
 * auto-defined by Application::defineConstants(). Fail-closed: an undefined or
 * malformed key throws, so we never silently write unencrypted data.
 */
class Crypto
{
    private const VERSION = 1;
    private const CIPHER  = 'aes-256-gcm';
    private const IV_LEN  = 12;
    private const TAG_LEN = 16;

    /** Test seam: overrides APP_ENCRYPTION_KEY when set (64 hex chars). */
    public static ?string $keyOverride = null;

    public static function encrypt(string $plaintext): string
    {
        $iv  = random_bytes(self::IV_LEN);
        $tag = '';
        $ciphertext = openssl_encrypt(
            $plaintext, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv, $tag, '', self::TAG_LEN
        );
        if ($ciphertext === false) {
            throw new \RuntimeException('Crypto::encrypt failed');
        }
        return base64_encode(chr(self::VERSION) . $iv . $tag . $ciphertext);
    }

    public static function decrypt(string $blob): ?string
    {
        $raw = base64_decode($blob, true);
        if ($raw === false || strlen($raw) < 1 + self::IV_LEN + self::TAG_LEN) {
            return null;
        }
        if (ord($raw[0]) !== self::VERSION) {
            return null;
        }
        $iv  = substr($raw, 1, self::IV_LEN);
        $tag = substr($raw, 1 + self::IV_LEN, self::TAG_LEN);
        $ct  = substr($raw, 1 + self::IV_LEN + self::TAG_LEN);
        $plaintext = openssl_decrypt($ct, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv, $tag);
        return $plaintext === false ? null : $plaintext;
    }

    /** True if $s looks like one of our envelopes (best-effort; mixed-read safety). */
    public static function isEncrypted(string $s): bool
    {
        $raw = base64_decode($s, true);
        return $raw !== false
            && strlen($raw) >= 1 + self::IV_LEN + self::TAG_LEN
            && ord($raw[0]) === self::VERSION;
    }

    private static function key(): string
    {
        $hex = self::$keyOverride
            ?? (defined('APP_ENCRYPTION_KEY') ? APP_ENCRYPTION_KEY : null);
        if ($hex === null || $hex === '') {
            throw new \RuntimeException(
                'APP_ENCRYPTION_KEY is not defined (expected 64 hex chars in app/config/crypto.ini)'
            );
        }
        $key = @hex2bin($hex);
        if ($key === false || strlen($key) !== 32) {
            throw new \RuntimeException('APP_ENCRYPTION_KEY must be exactly 64 hex characters (32 bytes)');
        }
        return $key;
    }
}
