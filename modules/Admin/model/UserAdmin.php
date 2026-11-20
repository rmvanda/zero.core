<?php
namespace Zero\Module\Admin\Model;

/**
 * Administrative operations on arbitrary user accounts.
 *
 * Zero\Core\Access\User covers reading any user and mutating the current one. The
 * operations that act on SOMEONE ELSE's account live here, behind the Admin
 * module's permission gate — a boundary drawn by operation rather than by
 * subject, because reading other users (bylines, notification contacts,
 * invitees) is universal and must not require the Admin module.
 */
class UserAdmin
{
    /**
     * The auth and credential tables keyed on user_id that belong to modules,
     * cleared before the account row itself.
     *
     * Group memberships, permissions granted to the user, their settings and
     * their profile are core's tables and are cleared by
     * Zero\Core\Access\User::delete(), which delete() calls, not from this list.
     *
     * This is not the full picture: roughly 44 tables in the schema carry a
     * user key (user_id, owner_id, or created_by), and delete() clears 9 of
     * them (the 5 here and core's 4). Content rows — notes, lists, notebooks, ttrpg_*, pastes,
     * short_urls and others — deliberately survive account deletion; which
     * of the remaining ~36 tables should eventually join this list is a
     * product decision nobody has made yet. Do not read this list as "every
     * table keyed on user_id" — it isn't.
     */
    private const CASCADE = [
        'auth_token',
        'api_tokens',
        'oauth_token',
        'ws_tokens',
        'push_subscriptions',
    ];

    private \PDO $db;

    public function __construct(\PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Delete an account and everything keyed to it.
     *
     * The CALLER owns the transaction: this is one step of an admin action that
     * may need to succeed or fail alongside others, and a service that commits
     * on its own behalf cannot be composed. Zero\Core\Access\User::delete() runs on
     * the framework's connection (Zero\Core\Database::getConnection()), so the
     * transaction has to be on that connection to cover the whole delete.
     *
     * @throws \RuntimeException when no account matched.
     */
    public function delete(int $userId): void
    {
        $user = \Zero\Core\Access\User::find($userId);

        // This guard exists because a previous version of this code targeted the
        // EAV `entity` table (type 4 = user) instead of `users`. Deleting those
        // stale EAV rows left the real user record behind while still reporting
        // success. Do not remove this check.
        if (!$user) {
            throw new \RuntimeException("No account with id {$userId}");
        }

        foreach (self::CASCADE as $table) {
            // Table names are a private const, never caller input, so
            // interpolation here cannot be reached by a parameter.
            $this->db->prepare("DELETE FROM `{$table}` WHERE user_id = ?")
                     ->execute([$userId]);
        }

        // Core's own tables (memberships, permission grants, settings,
        // profile) and the `users` row itself.
        $user->delete();
    }
}
