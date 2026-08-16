<?php

namespace Zero\Core;

/**
 * A site user — the row gateway for `users`, the profile store, and the
 * session facade, in one class.
 *
 * The governing rule: instance methods are record data, statics are the
 * current request. `$user->name`, `->email`, `->save()`, `->profile()` and
 * friends answer for whichever row the instance was loaded or built from.
 * `isLoggedIn()`, `current()`, `establish()` and the rest answer for the
 * session only — none of them take a user to answer about, and none of them
 * ever will. That asymmetry is deliberate: it is what stops
 * `$someoneElse->isLoggedIn()` from ever compiling.
 *
 * Replaces the EAV `user` entity type. `users.email` carries a real UNIQUE
 * KEY, so a duplicate raises a PDOException that Model::isDuplicateKey()
 * recognises.
 *
 * @author James Pope
 */
class User extends Model
{
    protected static string $table = 'users';

    /** id/created_at/updated_at are owned by the DDL and deliberately absent. */
    protected static array $columns = ['name', 'email', 'verified', 'pic'];

    /**
     * Canonical form of an address, for both storage and lookup.
     *
     * `users.email` is `utf8mb4_bin`, so the database compares it byte-exactly.
     * That is deliberate: under the previous `utf8mb4_unicode_ci` collation,
     * Unicode folding made `gross@x.com` and `groß@x.com` the SAME address, so
     * whoever registered one silently blocked the other from ever signing up.
     *
     * The cost of byte-exactness is that case must be normalised in code
     * instead — hence this. Every provider treats the local part
     * case-insensitively in practice, whatever RFC 5321 says.
     */
    public static function normalizeEmail(?string $email): string
    {
        return mb_strtolower(trim((string) $email), 'UTF-8');
    }

    /**
     * Find an account by address, normalising first.
     *
     * ALWAYS use this rather than `findBy('email', …)`. With a byte-exact
     * collation a raw lookup for `Foo@x.com` will not match a stored
     * `foo@x.com`, and the miss is silent — it reads as "no such account",
     * which on a sign-in path means a working address quietly stops working.
     */
    public static function findByEmail(?string $email): ?static
    {
        $normalized = self::normalizeEmail($email);

        return $normalized === '' ? null : static::findBy('email', $normalized);
    }

    /**
     * Normalises the address before insert, so the stored form always matches
     * what findByEmail() will look for. The column owns its own canonical form
     * rather than trusting each call site to remember.
     *
     * NOTE: an email changed via property-set + save() bypasses this. Nothing
     * does that today; if something ever needs to, normalise at that call site.
     */
    public static function create(array $data): static
    {
        if (array_key_exists('email', $data)) {
            $data['email'] = self::normalizeEmail($data['email']);
        }

        return parent::create($data);
    }

    /**
     * Check if user is logged in and verified
     *
     * @return bool
     */
    public static function isLoggedIn(): bool
    {
        return isset($_SESSION['email']) &&
               isset($_SESSION['verified']) &&
               $_SESSION['verified'];
    }

    /** Memoized per request. Null between requests and after logout(). */
    private static ?self $current = null;

    /**
     * The user making this request, hydrated from $_SESSION — never a query.
     *
     * Safe to mutate and save(): Model::save() writes only columns explicitly
     * set since load, so a session-built instance cannot clobber the row with
     * a stale value. The session projection is exactly static::$columns plus
     * the pk, so every mapped column is present and no accessor can return
     * null merely because it "wasn't loaded".
     *
     * Checked with `instanceof static`, not `!== null`: $current is ONE
     * storage slot shared by every subclass — private static properties are
     * bound to the declaring class, not to static:: — but the return type is
     * `?static`. Zero\Model\User extends this class as a deprecated shim, so
     * `Zero\Core\User::current()` followed by `Zero\Model\User::current()`
     * would otherwise hand back a Zero\Core\User instance where a
     * `?Zero\Model\User` is required: a TypeError, not a quiet wrong answer.
     * Checking the type on read rebuilds instead of reusing whenever the
     * memoized instance is not of the class actually being asked for.
     */
    public static function current(): ?static
    {
        if (self::$current instanceof static) {
            return self::$current;
        }
        $id = self::currentId();
        if ($id === null) {
            return null;
        }
        // name/email/pic fall back to the $_SESSION['user'] array — see the
        // rationale on currentId() below. Note the array's key is full_name,
        // not name (establish() builds it that way when no $identity is
        // passed). verified deliberately has NO array fallback: the old
        // isVerified() never had one either, and isLoggedIn() gates on the
        // flat $_SESSION['verified'] alone, so adding one here would be a
        // behaviour change, not a restoration.
        return self::$current = new static([
            'id'       => $id,
            'name'     => $_SESSION['name']  ?? $_SESSION['user']['full_name'] ?? null,
            'email'    => $_SESSION['email'] ?? $_SESSION['user']['email']     ?? null,
            'verified' => $_SESSION['verified'] ?? 0,
            'pic'      => $_SESSION['pic']   ?? $_SESSION['user']['pic']       ?? null,
        ], true);
    }

    /**
     * The current user's id, without building an object.
     *
     * Kept separate from current() because 107 call sites want only the id or
     * a logged-in check, and none of them should pay for object construction.
     *
     * Falls back to $_SESSION['user']['id'] for sessions built before
     * establish() existed: its own docblock records that complete() and
     * AllowWithToken used to build the session by hand and had already
     * drifted apart from each other. establish() itself always writes both
     * shapes, so this fallback matters only for sessions already sitting in
     * PHP's session store from before establish() unified them.
     */
    public static function currentId(): ?int
    {
        $id = $_SESSION['user_id'] ?? $_SESSION['user']['id'] ?? null;
        return $id === null ? null : (int) $id;
    }

    /**
     * Build the login session. THE single writer of the session contract
     * documented in modules/Auth/CLAUDE.md.
     *
     * Three callers: Auth::complete() (OAuth), the magic-link token consumer,
     * and core/attribute/AllowWithToken.php (API tokens). Before this existed,
     * complete() and AllowWithToken each built the session by hand and had
     * already drifted apart — AllowWithToken set neither created_at nor
     * login_provider and never rotated the session id.
     *
     * @param self             $user     The account being signed in.
     * @param array|null       $identity Normalized provider identity, when the
     *                                   caller has one richer than the stored
     *                                   row (OAuth). Null builds it from the row.
     */
    public static function establish(self $user, ?array $identity = null): void
    {
        // Clear the per-request memo so a sign-in following an earlier
        // current() call in the same request does not keep returning the
        // previous user.
        self::$current = null;

        // Rotate at the privilege boundary. Application::__construct() only
        // rotates on a timer, which leaves a session-fixation window at exactly
        // the moment it matters. Guarded because CLI/test contexts have no session.
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }

        $_SESSION['user'] = $identity ?? [
            'full_name' => $user->name,
            'email'     => $user->email,
            'verified'  => $user->verified,
            'pic'       => $user->pic,
            'id'        => $user->id,
        ];

        $_SESSION['user_id']  = $user->id;
        $_SESSION['name']     = $user->name;
        $_SESSION['email']    = $user->email;
        $_SESSION['verified'] = $user->verified;
        $_SESSION['pic']      = $user->pic;

        // Permissions/settings. A failure here must not break a sign-in.
        try {
            $stmt = Database::getConnection()->prepare(
                "SELECT setting_key, setting_value FROM user_settings WHERE user_id = ?"
            );
            $stmt->execute([$user->id]);

            $_SESSION['user_settings'] = [];
            foreach ($stmt->fetchAll() as $setting) {
                $_SESSION['user_settings'][$setting['setting_key']] = $setting['setting_value'];
            }
            $_SESSION['auth_level'] = (int) ($_SESSION['user_settings']['auth.level'] ?? 0);
        } catch (\Throwable $e) {
            // \Throwable, not \Exception: Database::getConnection() throws \Error
            // on a misconfiguration, which \Exception does not catch — and the
            // comment above says a failure here must not break a sign-in.
            error_log("User::establish failed to load settings: " . $e->getMessage());
            $_SESSION['user_settings'] = [];
            $_SESSION['auth_level']    = 0;
        }

        $_SESSION['created_at'] = time();

        // Non-expiring "probably a returning user" hint. NOT authentication —
        // it holds a plaintext id and must never be trusted as proof of identity.
        setcookie('unisolu_user_id', (string) $user->id, 2147483647, '/', '', true, true);
    }

    /**
     * Logout user by clearing session
     *
     * @return void
     */
    public static function logout(): void
    {
        self::$current = null;
        session_unset();
        session_destroy();
    }

    /** @deprecated Use currentId(). Retained so this task does not touch 68 call sites. */
    public static function getId()
    {
        return self::currentId();
    }

    /**
     * Check if user has a specific permission
     *
     * @param string $permission Permission key to check
     * @return bool True if user has permission and it's enabled
     */
    public static function hasPermission(string $permission): bool
    {
        $userId = self::getId();
        if (!$userId) {
            return false;
        }

        try {
            $db = Database::getConnection();

            $stmt = $db->prepare(
                "SELECT setting_value FROM user_settings WHERE user_id = ? AND setting_key = ?"
            );
            $stmt->execute([$userId, $permission]);
            $result = $stmt->fetch();

            // Permission exists and is truthy (1, true, "1", etc.)
            return $result && !empty($result['setting_value']) && $result['setting_value'] !== '0';
        } catch (\Exception $e) {
            error_log("User::hasPermission error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get a specific permission value
     *
     * @param string $permission Permission key
     * @return mixed Permission value or null if not found
     */
    public static function getPermission(string $permission)
    {
        $userId = self::getId();
        if (!$userId) {
            return null;
        }

        try {
            $db = Database::getConnection();

            $stmt = $db->prepare(
                "SELECT setting_value FROM user_settings WHERE user_id = ? AND setting_key = ?"
            );
            $stmt->execute([$userId, $permission]);
            $result = $stmt->fetch();

            return $result ? $result['setting_value'] : null;
        } catch (\Exception $e) {
            error_log("User::getPermission error: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Get all permissions for the current user
     *
     * @return array Associative array of permission => value
     */
    public static function getAllPermissions(): array
    {
        $userId = self::getId();
        if (!$userId) {
            return [];
        }

        try {
            $db = Database::getConnection();

            $stmt = $db->prepare(
                "SELECT setting_key, setting_value FROM user_settings WHERE user_id = ?"
            );
            $stmt->execute([$userId]);
            $results = $stmt->fetchAll();

            $permissions = [];
            foreach ($results as $row) {
                $permissions[$row['setting_key']] = $row['setting_value'];
            }

            return $permissions;
        } catch (\Exception $e) {
            error_log("User::getAllPermissions error: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Set a permission for the current user
     *
     * @param string $permission Permission key
     * @param mixed $value Permission value
     * @return bool Success status
     */
    public static function setPermission(string $permission, $value): bool
    {
        $userId = self::getId();
        if (!$userId) {
            return false;
        }

        try {
            $db = Database::getConnection();

            $stmt = $db->prepare(
                "INSERT INTO user_settings (user_id, setting_key, setting_value, updated_by)
                 VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                 setting_value = VALUES(setting_value),
                 updated_by = VALUES(updated_by),
                 updated_on = CURRENT_TIMESTAMP"
            );

            return $stmt->execute([$userId, $permission, $value, $userId]);
        } catch (\Exception $e) {
            error_log("User::setPermission error: " . $e->getMessage());
            return false;
        }
    }

    /*
     * Profile storage: values for the configurable registration fields. One
     * row per (user_id, field_key) in `user_profile`, with per-row
     * encryption for fields marked sensitive.
     *
     * `user_profile`'s primary key is composite (user_id, field_key), and
     * Model assumes a single auto-increment pk with lastInsertId()
     * semantics — that mismatch is why these three methods talk to
     * `user_profile` directly with hand-written SQL rather than through the
     * generic column-whitelist machinery this class inherits for `users`
     * itself. Shaped after Zero\Entity\OAuthToken's encrypt-on-write /
     * decrypt-on-read instead.
     */

    /**
     * Upsert the supplied values. Keys absent from $fieldDefs are silently
     * dropped — a field that is not configured is not storable.
     *
     * @param array $values    field_key => plaintext value
     * @param array $fieldDefs from RegistrationFields::all(); each has 'key' and 'sensitive'
     */
    public function storeProfile(array $values, array $fieldDefs): void
    {
        $sensitive = [];
        foreach ($fieldDefs as $def) {
            $sensitive[$def['key']] = !empty($def['sensitive']);
        }

        $stmt = Database::getConnection()->prepare(
            "INSERT INTO user_profile (user_id, field_key, value, encrypted)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE value = VALUES(value), encrypted = VALUES(encrypted)"
        );

        foreach ($values as $key => $value) {
            if (!array_key_exists($key, $sensitive)) {
                continue;   // not a configured field
            }
            $encrypt = $sensitive[$key] && $value !== null && $value !== '';
            $stmt->execute([
                $this->id,
                $key,
                $encrypt ? Crypto::encrypt((string) $value) : $value,
                $encrypt ? 1 : 0,
            ]);
        }
    }

    /** All stored fields for a user, decrypted, keyed by field_key. */
    public function profile(): array
    {
        $stmt = Database::getConnection()->prepare(
            "SELECT field_key, value, encrypted FROM user_profile WHERE user_id = ?"
        );
        $stmt->execute([$this->id]);

        $out = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            // Trust the stored flag, never guess. A decrypt failure yields null
            // rather than throwing — a corrupt field must not break the page.
            $out[$row['field_key']] = ((int) $row['encrypted'] === 1 && $row['value'] !== null)
                ? Crypto::decrypt($row['value'])
                : $row['value'];
        }
        return $out;
    }

    /** Remove every stored field for a user. Called when an account is deleted. */
    public function forgetProfile(): void
    {
        Database::getConnection()->prepare("DELETE FROM user_profile WHERE user_id = ?")->execute([$this->id]);
    }
}
