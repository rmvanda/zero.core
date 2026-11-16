<?php
namespace Zero\Module\Auth\Tests;

use Zero\Tests\Core\ZeroTestCase;
use Zero\Module\Auth\Model\RegistrationFields;
use Zero\Core\HTTPError;

class RegistrationFieldsTest extends ZeroTestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir() . '/regfields_' . spl_object_id($this) . '.json';
        RegistrationFields::$pathOverride = $this->tmp;
        RegistrationFields::reset();
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tmp)) { unlink($this->tmp); }
        RegistrationFields::$pathOverride = null;
        RegistrationFields::reset();
        parent::tearDown();
    }

    private function write(array $defs): void
    {
        file_put_contents($this->tmp, json_encode($defs));
        RegistrationFields::reset();
    }

    public function testFieldsAreReturnedInOrder(): void
    {
        $this->write([
            ['key' => 'b', 'label' => 'B', 'type' => 'text', 'required' => false, 'sensitive' => false, 'order' => 20],
            ['key' => 'a', 'label' => 'A', 'type' => 'text', 'required' => false, 'sensitive' => false, 'order' => 10],
        ]);
        $this->assertSame(['a', 'b'], array_column(RegistrationFields::all(), 'key'));
    }

    public function testMalformedJsonThrows(): void
    {
        file_put_contents($this->tmp, '{not json');
        RegistrationFields::reset();
        $this->expectException(HTTPError::class);
        RegistrationFields::all();
    }

    public function testUnknownTypeThrows(): void
    {
        $this->write([['key' => 'a', 'label' => 'A', 'type' => 'nonsense', 'required' => false, 'sensitive' => false, 'order' => 1]]);
        $this->expectException(HTTPError::class);
        RegistrationFields::all();
    }

    public function testDuplicateKeyThrows(): void
    {
        $this->write([
            ['key' => 'a', 'label' => 'A', 'type' => 'text', 'required' => false, 'sensitive' => false, 'order' => 1],
            ['key' => 'a', 'label' => 'A2', 'type' => 'text', 'required' => false, 'sensitive' => false, 'order' => 2],
        ]);
        $this->expectException(HTTPError::class);
        RegistrationFields::all();
    }

    public function testReservedKeyEmailThrows(): void
    {
        // email is owned by the users table and always collected — it must not
        // be configurable as a profile field.
        $this->write([['key' => 'email', 'label' => 'E', 'type' => 'email', 'required' => true, 'sensitive' => false, 'order' => 1]]);
        $this->expectException(HTTPError::class);
        RegistrationFields::all();
    }

    public function testRequiredFieldIsEnforced(): void
    {
        $this->write([['key' => 'bio', 'label' => 'Bio', 'type' => 'text', 'required' => true, 'sensitive' => false, 'order' => 1]]);
        [$clean, $errors] = RegistrationFields::validate(['bio' => '   ']);
        $this->assertArrayHasKey('bio', $errors);
        $this->assertSame([], $clean);
    }

    public function testPatternIsEnforcedServerSide(): void
    {
        $this->write([['key' => 'phone', 'label' => 'Phone', 'type' => 'tel', 'required' => false,
                       'sensitive' => true, 'order' => 1, 'pattern' => '^[0-9]{3}$']]);

        [, $bad]  = RegistrationFields::validate(['phone' => 'abc']);
        $this->assertArrayHasKey('phone', $bad);

        [$good, $none] = RegistrationFields::validate(['phone' => '123']);
        $this->assertSame([], $none);
        $this->assertSame('123', $good['phone']);
    }

    public function testUnconfiguredPostKeysAreDropped(): void
    {
        $this->write([['key' => 'bio', 'label' => 'F', 'type' => 'text', 'required' => false, 'sensitive' => false, 'order' => 1]]);
        [$clean, ] = RegistrationFields::validate(['bio' => 'Ada', 'is_admin' => '1']);
        $this->assertArrayNotHasKey('is_admin', $clean);
    }

    public function testOptionalEmptyFieldIsNotAnError(): void
    {
        $this->write([['key' => 'phone', 'label' => 'P', 'type' => 'tel', 'required' => false, 'sensitive' => true, 'order' => 1]]);
        [$clean, $errors] = RegistrationFields::validate(['phone' => '']);
        $this->assertSame([], $errors);
        $this->assertArrayNotHasKey('phone', $clean);
    }

    public function testSelectRequiresOptions(): void
    {
        $this->write([['key' => 'plan', 'label' => 'Plan', 'type' => 'select', 'required' => false, 'sensitive' => false, 'order' => 1]]);
        $this->expectException(HTTPError::class);
        RegistrationFields::all();
    }

    public function testSelectRejectsAValueNotInOptions(): void
    {
        $this->write([['key' => 'plan', 'label' => 'Plan', 'type' => 'select', 'required' => false,
                       'sensitive' => false, 'order' => 1,
                       'options' => [['value' => 'free', 'label' => 'Free']]]]);
        [, $errors] = RegistrationFields::validate(['plan' => 'enterprise']);
        $this->assertArrayHasKey('plan', $errors);
    }

    public function testUnanchoredPatternThrows(): void
    {
        // preg_match is a contains-test and validate() stores the raw value, so
        // an unanchored pattern would accept and store surrounding junk.
        $this->write([['key' => 'phone', 'label' => 'Phone', 'type' => 'tel', 'required' => false,
                       'sensitive' => true, 'order' => 1, 'pattern' => '[0-9]{3}']]);
        $this->expectException(HTTPError::class);
        RegistrationFields::all();
    }

    public function testAnchoredPatternIsAccepted(): void
    {
        $this->write([['key' => 'phone', 'label' => 'Phone', 'type' => 'tel', 'required' => false,
                       'sensitive' => true, 'order' => 1, 'pattern' => '^[0-9]{3}$']]);
        $this->assertSame('phone', RegistrationFields::all()[0]['key']);
    }

    public function testEveryReservedKeyIsRejected(): void
    {
        // Guards against the list being silently trimmed: a mutation dropping
        // everything but 'email' previously survived the whole suite.
        foreach (['email', 'id', 'verified', 'pic', 'name', 'created_at', 'updated_at', 'full_name'] as $reserved) {
            $this->write([['key' => $reserved, 'label' => 'X', 'type' => 'text',
                           'required' => false, 'sensitive' => false, 'order' => 1]]);
            try {
                RegistrationFields::all();
                $this->fail("reserved key '$reserved' was accepted");
            } catch (HTTPError $e) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testByKeyReturnsTheDefinitionOrNull(): void
    {
        $this->write([['key' => 'phone', 'label' => 'Phone', 'type' => 'tel',
                       'required' => false, 'sensitive' => true, 'order' => 1]]);
        $this->assertSame('Phone', RegistrationFields::byKey('phone')['label']);
        $this->assertNull(RegistrationFields::byKey('nope'));
    }

    public function testShippedConfigDeclaresNoCustomFields(): void
    {
        RegistrationFields::$pathOverride = null;
        RegistrationFields::reset();

        $this->assertSame([], RegistrationFields::all(),
            'registration-fields.json must ship empty: name and email are columns on users, '
            . 'collected by the form itself, not configurable profile fields.');
    }
}
