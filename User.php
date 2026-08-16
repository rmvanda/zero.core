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
     * `?static`. Checking the type on read rebuilds instead of reusing
     * whenever the memoized instance is not of the class actually being
     * asked for.
     *
     * `save()` is safe, as described above — `delete()` is NOT. It is
     * inherited from Model and compiles fine on the returned instance, but
     * calling it here issues `DELETE FROM users WHERE id = <session user>`
     * while leaving the session itself intact, silently orphaning it.
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

        // Permissions/settings are no longer cached into the session here —
        // User::can()/authLevel() read through to the database, memoized per
        // instance, so a permission revoked mid-session denies on the very
        // next request instead of waiting for logout. See
        // core/attribute/RequirePermission.php and RequireAuthLevel.php.

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

    /** Settings for this user, loaded once per instance. */
    private ?array $settings = null;

    /**
     * Does this user hold $key?
     *
     * Reads through to the database, memoized per instance. That is one query
     * per user per request — which is what buys mid-session revocation: a
     * permission removed in the DB denies on the very next request, with no
     * re-login, no TTL to tune and no flush API to build.
     *
     * Grant is a strict whitelist: '1', 1 and true. Everything else denies.
     * The looser check this replaces treated 'false', 'off' and 'no' as grants.
     */
    public function can(string $key): bool
    {
        $value = $this->settings()[$key] ?? null;
        return $value === '1' || $value === 1 || $value === true;
    }

    /**
     * All of this user's settings, keyed by setting_key. Memoized.
     *
     * Public (not just used internally by can()/authLevel()): callers that
     * need the whole map — the profile view splitting allow.* permissions
     * from other config — read it here rather than the session cache that
     * used to carry it.
     */
    public function settings(): array
    {
        if ($this->settings !== null) {
            return $this->settings;
        }
        try {
            $stmt = Database::getConnection()->prepare(
                "SELECT setting_key, setting_value FROM user_settings WHERE user_id = ?"
            );
            $stmt->execute([$this->id]);
            $out = [];
            foreach ($stmt->fetchAll() as $row) {
                $out[$row['setting_key']] = $row['setting_value'];
            }
            return $this->settings = $out;
        } catch (\Throwable $e) {
            // \Throwable, not \Exception: Database::getConnection() throws \Error
            // on a misconfiguration. A lookup failure must deny, never grant.
            error_log("User::settings() failed: " . $e->getMessage());
            return $this->settings = [];
        }
    }

    /**
     * auth.level as an integer.
     *
     * It shares user_settings with the booleans but is a LEVEL, so it must not
     * go through can(): under the strict whitelist a future auth.level of 2
     * would read as denied.
     */
    public function authLevel(): int
    {
        return (int) ($this->settings()['auth.level'] ?? 0);
    }

    /**
     * Set one setting. $actorId records who did it; null means self-service.
     *
     * Drops the memo so a read later in the same request sees the write.
     */
    public function setSetting(string $key, $value, ?int $actorId = null): bool
    {
        try {
            $ok = Database::getConnection()->prepare(
                "INSERT INTO user_settings (user_id, setting_key, setting_value, updated_by)
                 VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                 setting_value = VALUES(setting_value),
                 updated_by    = VALUES(updated_by),
                 updated_on    = CURRENT_TIMESTAMP"
            )->execute([$this->id, $key, $value, $actorId ?? $this->id]);
            $this->settings = null;
            return $ok;
        } catch (\Throwable $e) {
            // \Throwable, not \Exception: Database::getConnection() throws \Error
            // on a misconfiguration, same reasoning as settings() above — a
            // write failure must report false, not propagate.
            error_log("User::setSetting() failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Does the CURRENT user hold $key?
     *
     * A current-request question, so it stays static per the class rule. It is
     * the only permission static that survives; getPermission(),
     * getAllPermissions() and setPermission() had zero callers and are gone.
     *
     * Deliberately kept rather than replaced by can() at the call sites: this
     * framework serves the live site from the working tree, so deleting a
     * method with 12 live callers is an immediate outage.
     */
    public static function hasPermission(string $permission): bool
    {
        return static::current()?->can($permission) ?? false;
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
