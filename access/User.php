<?php

namespace Zero\Core\Access;
use Zero\Core\Model;
use Zero\Core\Database;
use Zero\Lib\Crypto;

/**
 * A site user — the row gateway for `users`, the user's settings and profile
 * store, and what the user may do.
 *
 * Every method answers for whichever row the instance was loaded or built
 * from: `$user->name`, `->email`, `->save()`, `->profile()`, `->can()`,
 * `->groups()`. Nothing here reads the session. "Who is making this request"
 * is Zero\Core\Session's question: Session::user(), Session::userId().
 *
 * A user is a Holder: permissions are granted to it (see Zero\Core\Access\Permission).
 *
 * Replaces the EAV `user` entity type. `users.email` carries a real UNIQUE
 * KEY, so a duplicate raises a PDOException that Model::isDuplicateKey()
 * recognises.
 *
 * @author James Pope
 */
class User extends Model implements Holder
{
    use HoldsCapabilities;

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

    /* ── Holder ── */

    public function holderColumn(): string
    {
        return 'user_id';
    }

    public function holderId(): int
    {
        return (int) $this->id;
    }

    /**
     * The groups this user belongs to.
     *
     * @param bool $activeOnly False to include inactive groups too. Only a
     *                         screen that manages groups wants those; an
     *                         inactive group grants nothing.
     * @return Group[] Each also carries role, added_at and member_count
     */
    public function groups(bool $activeOnly = true): array
    {
        return Group::forUser($this, $activeOnly);
    }

    /**
     * The users this user may see by name: for a mention, an invitation, a
     * share. They are the members of the active groups this user belongs to.
     * A user who holds `allow.admin` may see every user. This user is never
     * in the list.
     *
     * A module that has a narrower rule of its own (the readers of one
     * notebook) keeps that rule; this is the widest list a module shows.
     *
     * @param string|null $term  Text to find in the name or the email, without
     *                           regard to case. Null or '' gives the whole list.
     * @param int         $limit Most rows to give; 0 for no limit
     * @return array[] Rows of id, name, email, in name order
     */
    public function mentionableUsers(?string $term = null, int $limit = 0): array
    {
        if (!$this->hasUsableId()) {
            return [];
        }

        $sql    = "SELECT u.id, u.name, u.email FROM users u WHERE u.id != ?";
        $params = [(int) $this->id];

        if (!$this->can('allow.admin')) {
            $sql .= " AND u.id IN (SELECT m.user_id FROM user_group_member m WHERE m.group_id IN ("
                  . Group::activeIdsSql() . "))";
            $params[] = (int) $this->id;
        }

        $term = trim((string) $term);
        if ($term !== '') {
            // % and _ in the term are literal text, not wildcards
            $like = '%' . addcslashes($term, '\\%_') . '%';
            // The email column has a binary collation, thus LOWER() on both sides
            // makes the search ignore case.
            $sql .= " AND (LOWER(u.email) LIKE LOWER(?) OR u.name LIKE ?)";
            $params[] = $like;
            $params[] = $like;
        }

        $sql .= " ORDER BY u.name, u.email";
        if ($limit > 0) {
            $sql .= " LIMIT " . $limit;
        }

        $stmt = Database::getConnection()->prepare($sql);
        $stmt->execute($params);

        return array_map(
            fn(array $row) => ['id' => (int) $row['id'], 'name' => $row['name'], 'email' => $row['email']],
            $stmt->fetchAll(\PDO::FETCH_ASSOC)
        );
    }

    /**
     * Delete the account and everything core keeps about it: its group
     * memberships, the permissions granted to it, its settings and its
     * profile. No foreign key does any of this.
     *
     * What modules keep about a user (tokens, content) is not core's to
     * delete. The CALLER owns the transaction.
     *
     * Do not call it on Session::user(): the row goes and the session stays.
     */
    public function delete(): void
    {
        if (!$this->exists || !$this->hasUsableId()) {
            return;
        }
        Group::removeFromAll($this);
        Permission::forgetHolder($this);
        Database::getConnection()->prepare("DELETE FROM user_settings WHERE user_id = ?")->execute([$this->id]);
        $this->forgetProfile();

        parent::delete();
    }

    /** Settings for this user, loaded once per instance. */
    private ?array $settings = null;

    /**
     * Guards settings()/setSetting() and delete() —
     * the methods here that touch a table by this id — against a
     * non-positive $this->id.
     *
     * Session::userId() rejects a bad session id before a User is ever built from
     * it, but tests (and anything else constructing a User directly, e.g.
     * `new User(['id' => 999000077], true)` in UserProfileTest) bypass that
     * path entirely. id 0 in particular is not "no such user" here — in the
     * permission table it marks a row as a group's; reading or writing
     * through it would treat every group's grants as one user's. Negative ids
     * are simply meaningless. One helper rather than a duplicated
     * conditional in each method.
     */
    private function hasUsableId(): bool
    {
        return $this->id > 0;
    }

    /**
     * All of this user's settings, keyed by setting_key. Memoized.
     *
     * Settings are values (auth.level, index0.confirmations), stored in
     * user_settings. Capabilities are not here: see can() and capabilities().
     */
    public function settings(): array
    {
        if ($this->settings !== null) {
            return $this->settings;
        }
        if (!$this->hasUsableId()) {
            // id 0 is not a user and a negative id is meaningless. Neither
            // gets a query — see hasUsableId().
            return $this->settings = [];
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
            \Zero\Core\Console::error("User::settings() failed: " . $e->getMessage());
            return $this->settings = [];
        }
    }

    /**
     * auth.level as an integer.
     *
     * A setting, not a capability: it is a LEVEL, so it does not go through
     * can().
     */
    public function authLevel(): int
    {
        return (int) ($this->settings()['auth.level'] ?? 0);
    }

    /**
     * Set one setting. $actorId records who did it; null means self-service.
     * A capability is not a setting: use grant() and revoke().
     *
     * Drops the memo so a read later in the same request sees the write.
     */
    public function setSetting(string $key, $value, ?int $actorId = null): bool
    {
        if (!$this->hasUsableId()) {
            // Refuse rather than write under id 0 (or a meaningless
            // negative id) — see hasUsableId().
            return false;
        }
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
            \Zero\Core\Console::error("User::setSetting() failed: " . $e->getMessage());
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
     * itself. Shaped after Zero\Module\Auth\OAuth\Token's encrypt-on-write /
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
