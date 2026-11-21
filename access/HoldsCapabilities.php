<?php
namespace Zero\Core\Access;

/**
 * Capabilities for a Holder: can(), capabilities(), grant() and revoke().
 *
 * A capability ("may this holder use this feature at all?", allow.polly) is a
 * row in `permission` on entity 0. A group holds the ones granted to it. A
 * user holds their own and those of every active group they belong to.
 */
trait HoldsCapabilities
{
    /** Capability keys this holder has, loaded once per instance. */
    private ?array $capabilities = null;

    /**
     * Does this holder have the capability $key (allow.polly)?
     *
     * Reads through to the database, memoized per instance. That is one query
     * per holder per request — which is what buys mid-session revocation: a
     * capability removed in the DB denies on the very next request, with no
     * re-login, no TTL to tune and no flush API to build.
     *
     * A setting is not a capability: read one with User::settings().
     */
    public function can(string $key): bool
    {
        return in_array($key, $this->capabilities(), true);
    }

    /**
     * Every capability key this holder has. Memoized.
     *
     * @return string[]
     */
    public function capabilities(): array
    {
        if ($this->capabilities !== null) {
            return $this->capabilities;
        }
        if ($this->holderId() <= 0) {
            // 0 in a holder column of the permission table means "the row
            // belongs to the other kind of holder"; a negative id is
            // meaningless. Neither gets a query.
            return $this->capabilities = [];
        }
        try {
            return $this->capabilities = Permission::capabilities($this);
        } catch (\Throwable $e) {
            // \Throwable, not \Exception: Database::getConnection() throws \Error
            // on a misconfiguration. A lookup failure must deny, never grant.
            \Zero\Core\Console::error(static::class . "::capabilities() failed: " . $e->getMessage());
            return $this->capabilities = [];
        }
    }

    /**
     * Give this holder the capability $key. $actorId records who did it; null
     * means the logged-in user.
     *
     * Drops the memo so a read later in the same request sees the write.
     */
    public function grant(string $key, ?int $actorId = null): bool
    {
        return $this->writeCapability($key, true, $actorId);
    }

    /**
     * Take the capability $key away from this holder. A capability a user
     * has through a group stays until the group loses it.
     */
    public function revoke(string $key): bool
    {
        return $this->writeCapability($key, false, null);
    }

    private function writeCapability(string $key, bool $grant, ?int $actorId): bool
    {
        if ($this->holderId() <= 0) {
            return false;
        }
        try {
            $capability = Permission::capability($key);
            $ok = $grant
                ? $capability->grant(0, $this, true, false, false, $actorId)
                : $capability->revoke(0, $this);
            $this->capabilities = null;
            return $ok;
        } catch (\Throwable $e) {
            // A write failure reports false, the same as User::setSetting().
            \Zero\Core\Console::error(static::class . "::" . ($grant ? 'grant' : 'revoke') . "() failed: " . $e->getMessage());
            return false;
        }
    }
}
