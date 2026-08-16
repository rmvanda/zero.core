<?php
namespace Zero\Tests\Core;

use Zero\Core\Crypto;
use Zero\Core\Database;
use Zero\Core\User;

class UserProfileTest extends ZeroTestCase
{
    private const U = 999000077;

    /** Mirrors the shape RegistrationFields::all() returns. */
    private const DEFS = [
        ['key' => 'full_name', 'sensitive' => false],
        ['key' => 'phone',     'sensitive' => true],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->connectTestDatabase();
        // Set last: tearDown() does not run when setUp() throws, so if
        // forgetProfile() (a DB call) threw before this line, $keyOverride
        // would be left set for every later test in the run and silently
        // break Crypto::decrypt() there.
        $this->user()->forgetProfile();
        Crypto::$keyOverride = str_repeat('b', 64);
    }

    protected function tearDown(): void
    {
        $this->user()->forgetProfile();
        Crypto::$keyOverride = null;
        parent::tearDown();
    }

    /**
     * A User instance for the fixture id, without a query — self::U is not
     * backed by a real row in `users`, and profile()/storeProfile()/
     * forgetProfile() only ever touch `user_profile` by id.
     */
    private function user(): User
    {
        return new User(['id' => self::U], true);
    }

    public function testPlainValueRoundTrip(): void
    {
        $this->user()->storeProfile(['full_name' => 'Ada Lovelace'], self::DEFS);
        $this->assertSame('Ada Lovelace', $this->user()->profile()['full_name']);
    }

    public function testSensitiveValueRoundTripsAndIsEncryptedAtRest(): void
    {
        $this->user()->storeProfile(['phone' => '+1 555 0100'], self::DEFS);

        $this->assertSame('+1 555 0100', $this->user()->profile()['phone']);

        $stmt = Database::getConnection()->prepare(
            "SELECT value, encrypted FROM user_profile WHERE user_id = ? AND field_key = ?"
        );
        $stmt->execute([self::U, 'phone']);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        $this->assertSame(1, (int) $row['encrypted']);
        $this->assertNotSame('+1 555 0100', $row['value'], 'ciphertext must differ from plaintext');
    }

    public function testNonSensitiveValueIsStoredAsPlaintextAtRest(): void
    {
        $this->user()->storeProfile(['full_name' => 'Ada Lovelace'], self::DEFS);

        $stmt = Database::getConnection()->prepare(
            "SELECT value, encrypted FROM user_profile WHERE user_id = ? AND field_key = ?"
        );
        $stmt->execute([self::U, 'full_name']);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        // Without this assertion an implementation that ignored `sensitive` and
        // encrypted everything would pass every other test in this file: get()
        // decrypts from the stored flag, so the round trip looks identical.
        $this->assertSame(0, (int) $row['encrypted']);
        $this->assertSame('Ada Lovelace', $row['value']);
    }

    public function testBlankSensitiveValueIsStoredUnencrypted(): void
    {
        $this->user()->storeProfile(['phone' => ''], self::DEFS);

        $stmt = Database::getConnection()->prepare(
            "SELECT value, encrypted FROM user_profile WHERE user_id = ? AND field_key = ?"
        );
        $stmt->execute([self::U, 'phone']);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        // Encrypting '' yields a non-empty ciphertext, which would make a field
        // left blank look identical at rest to one that was filled in. This rule
        // is load-bearing and was previously asserted only by a one-off manual run.
        $this->assertSame(0, (int) $row['encrypted']);
        $this->assertSame('', $row['value']);
    }

    public function testUnknownFieldKeyIsDropped(): void
    {
        $this->user()->storeProfile(['full_name' => 'Ada', 'not_a_field' => 'x'], self::DEFS);
        $this->assertArrayNotHasKey('not_a_field', $this->user()->profile());
    }

    public function testStoreUpserts(): void
    {
        $this->user()->storeProfile(['full_name' => 'First'], self::DEFS);
        $this->user()->storeProfile(['full_name' => 'Second'], self::DEFS);

        $all = $this->user()->profile();
        $this->assertSame('Second', $all['full_name']);
        $this->assertCount(1, $all);
    }

    public function testCorruptCiphertextYieldsNullRatherThanThrowing(): void
    {
        $this->user()->storeProfile(['phone' => '+1 555 0100'], self::DEFS);
        Database::getConnection()->prepare(
            "UPDATE user_profile SET value = 'not-a-valid-envelope' WHERE user_id = ? AND field_key = ?"
        )->execute([self::U, 'phone']);

        // A corrupted phone number must not 500 the profile page.
        $p = $this->user()->profile();
        // Without this, the assertion below would pass vacuously if profile()
        // returned [] entirely: an undefined array key also evaluates to
        // null (plus a Warning), which looks identical to a real decrypt
        // failure yielding null.
        $this->assertArrayHasKey('phone', $p);
        $this->assertNull($p['phone']);
    }

    public function testForgetRemovesEverythingForTheUser(): void
    {
        $this->user()->storeProfile(['full_name' => 'Ada', 'phone' => '+1'], self::DEFS);
        $this->user()->forgetProfile();
        $this->assertSame([], $this->user()->profile());
    }
}
