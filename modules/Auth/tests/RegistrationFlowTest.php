<?php
namespace Zero\Module\Auth\Tests;

use Zero\Tests\Core\ZeroTestCase;
use Zero\Module\Auth\Model\AuthToken;
use Zero\Module\Auth\Model\RegistrationFields;
use Zero\Core\Access\User;
use Zero\Core\Database;

/**
 * The registration invariants, exercised at the model layer. The HTTP layer is
 * verified by hand — these cover the rules that must never regress.
 */
class RegistrationFlowTest extends ZeroTestCase
{
    private const E = 'zerotest-reg@example.invalid';

    protected function setUp(): void
    {
        parent::setUp();
        $this->connectTestDatabase();
        $this->cleanup();

        // testRegistrationStoresNameOnUsersAndWritesNoProfileRow reads the
        // shipped registration-fields.json via RegistrationFields::all().
        // That relies on RegistrationFields' static cache/override being
        // unset; reset explicitly here rather than depending on
        // RegistrationFieldsTest::tearDown() having already run.
        RegistrationFields::$pathOverride = null;
        RegistrationFields::reset();
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    private function cleanup(): void
    {
        $db = Database::getConnection();
        $u = User::findByEmail(self::E);
        if ($u) {
            $db->prepare("DELETE FROM auth_token WHERE user_id = ?")->execute([$u->id]);
            $u->forgetProfile();
        }
        $db->prepare("DELETE FROM users WHERE email = ?")->execute([self::E]);
    }

    public function testNewRegistrationIsCreatedUnverified(): void
    {
        $user = User::create(['name' => 'Reg Probe', 'email' => self::E, 'verified' => 0]);
        $this->assertSame(0, User::find($user->id)->verified);
    }

    public function testUnverifiedAccountCannotProduceALoggedInSession(): void
    {
        $user = User::create(['name' => 'Reg Probe', 'email' => self::E, 'verified' => 0]);
        $_SESSION = [];
        \Zero\Core\Session::establish($user);

        // The whole safety property: an account nobody proved they own is inert.
        $this->assertFalse(\Zero\Core\Session::isLoggedIn());
    }

    public function testVerifyTokenFlipsVerifiedAndThenSignsIn(): void
    {
        $user  = User::create(['name' => 'Reg Probe', 'email' => self::E, 'verified' => 0]);
        $token = AuthToken::issue($user->id, 'verify', 3600);

        $userId = AuthToken::consume($token, 'verify');
        $this->assertSame($user->id, $userId);

        $confirmed = User::find($userId);
        $confirmed->verified = 1;
        $confirmed->save();

        $_SESSION = [];
        \Zero\Core\Session::establish(User::find($userId));
        $this->assertTrue(\Zero\Core\Session::isLoggedIn());
    }

    public function testProfileFieldsArePersistedForTheNewUser(): void
    {
        // The shipped RegistrationFields config is now empty (name and email
        // are collected by the form, not as configurable profile fields), so
        // this exercises UserProfile::store()/get() directly against a
        // hand-built field definition rather than RegistrationFields::all().
        $user = User::create(['name' => 'Reg Probe', 'email' => self::E, 'verified' => 0]);
        $defs = [['key' => 'bio', 'label' => 'Bio', 'type' => 'text',
                  'required' => false, 'sensitive' => false, 'order' => 1]];
        $user->storeProfile(['bio' => 'Reg Probe'], $defs);

        $this->assertSame('Reg Probe', $user->profile()['bio']);
    }

    public function testIssuedFromIpSinceCountsPerIpWithinTheWindow(): void
    {
        // The throttle's only input. It counts created_at server-side, so it is
        // unaffected by the PHP/MySQL clock offset that bit expires_at.
        $u = User::create(['name' => 'IP Probe', 'email' => self::E, 'verified' => 1]);

        $_SERVER['REMOTE_ADDR'] = '203.0.113.7';
        AuthToken::issue($u->id, 'login', 900);
        AuthToken::issue($u->id, 'login', 900);

        $this->assertSame(2, AuthToken::issuedFromIpSince('203.0.113.7', 3600));
        $this->assertSame(0, AuthToken::issuedFromIpSince('203.0.113.8', 3600));
    }

    public function testDuplicateEmailIsRejectedByTheDatabase(): void
    {
        User::create(['name' => 'First', 'email' => self::E, 'verified' => 0]);
        try {
            User::create(['name' => 'Second', 'email' => self::E, 'verified' => 0]);
            $this->fail('expected a duplicate-key PDOException');
        } catch (\PDOException $e) {
            $this->assertTrue(\Zero\Core\Model::isDuplicateKey($e));
        }
    }

    public function testRegistrationStoresNameOnUsersAndWritesNoProfileRow(): void
    {
        $user = User::create(['name' => 'Reg Probe', 'email' => self::E, 'verified' => 0]);

        // Exactly what Auth::register() does at :570. With an empty field
        // config this must write nothing — that is the end of the dual-write.
        $user->storeProfile(['full_name' => 'Reg Probe'], RegistrationFields::all());

        $stmt = Database::getConnection()
            ->prepare("SELECT COUNT(*) FROM user_profile WHERE user_id = ?");
        $stmt->execute([$user->id]);

        $this->assertSame(0, (int) $stmt->fetchColumn(),
            'The name must live in users.name only; a user_profile row is the dual-write.');
        $this->assertSame('Reg Probe', User::find($user->id)->name);
    }
}
