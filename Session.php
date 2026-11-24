<?php

namespace Zero\Core;
use Zero\Core\Access\User;

/**
 * The login session: who is making this request.
 *
 * Every method is static and answers for the current request only — none of
 * them take a user to answer about. Zero\Core\Access\User is the account; this is
 * the session an account is signed in to. That split is what stops
 * `$someoneElse->isLoggedIn()` from ever compiling.
 *
 * establish() is the single writer of the session and end() the single place
 * it is torn down.
 */
final class Session
{
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

    /** Memoized per request. Null between requests and after end(). */
    private static ?User $user = null;

    /**
     * The user making this request, hydrated from $_SESSION — never a query.
     *
     * Safe to mutate and save(): Model::save() writes only columns explicitly
     * set since load, so a session-built instance cannot clobber the row with
     * a stale value. The session projection is exactly User's columns plus
     * the pk, so every mapped column is present and no accessor can return
     * null merely because it "wasn't loaded".
     *
     * `save()` is safe, as described above — `delete()` is NOT. It is
     * inherited from Model and compiles fine on the returned instance, but
     * calling it here issues `DELETE FROM users WHERE id = <session user>`
     * while leaving the session itself intact, silently orphaning it.
     */
    public static function user(): ?User
    {
        if (self::$user !== null) {
            return self::$user;
        }
        $id = self::userId();
        if ($id === null) {
            return null;
        }
        // name/email/pic fall back to the $_SESSION['user'] array — see the
        // rationale on userId() below. Note the array's key is full_name,
        // not name (establish() builds it that way when no $identity is
        // passed). verified deliberately has NO array fallback: the old
        // isVerified() never had one either, and isLoggedIn() gates on the
        // flat $_SESSION['verified'] alone, so adding one here would be a
        // behaviour change, not a restoration.
        return self::$user = new User([
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
     * Kept separate from user() because most call sites want only the id or
     * a logged-in check, and none of them should pay for object construction.
     *
     * Reads $_SESSION['user_id'] and NOTHING ELSE. There used to be a fallback
     * to $_SESSION['user']['id'], for sessions built before establish()
     * existed. It was a liability rather than a convenience: $_SESSION['user']
     * holds the raw provider identity, and every adapter's format() puts the
     * PROVIDER's user id under 'id' — a foreign number in the same shape as a
     * local users.id. Auth::complete() wrote that array before its
     * verified-email gate could throw, so a refused sign-in left a session
     * whose userId() answered with a provider-scoped id. That is the same
     * bug class as the Notes bypass recorded in
     * core/attribute/AUTH_GATE_CONSISTENCY.md, and the filter_var hardening
     * below could not catch it: a GitHub or GitLab id is a perfectly valid
     * positive integer.
     *
     * establish() is the single session writer and always sets user_id, so
     * dropping the fallback costs only a one-time re-login for any session
     * still in PHP's store from before it landed. Do NOT restore it.
     *
     * Validated with filter_var(), never a bare (int) cast: the cast
     * silently turns 'abc' into 0, and 0 is not "nobody" — the permission
     * table uses user_id = 0 to mean "this row is a group's". A User
     * instance built on id 0 would have can() read every group's
     * capabilities as its own, and grant() would write a row with no
     * subject. filter_var also rejects arrays outright, which
     * matters because $_SESSION is attacker-influenced in shape as well as
     * value. Rejecting anything that is not a clean positive integer
     * degrades to "not logged in" — the safe direction — rather than
     * coercing to a usable-looking id. Do NOT simplify this back to (int).
     */
    public static function userId(): ?int
    {
        $id = $_SESSION['user_id'] ?? null;
        if ($id === null) {
            return null;
        }
        $id = filter_var($id, FILTER_VALIDATE_INT);
        return ($id !== false && $id > 0) ? $id : null;
    }

    /**
     * Build the login session. THE single writer of the session contract
     * documented in core/modules/Auth/CLAUDE.md.
     *
     * Three callers: Auth::complete() (OAuth), the magic-link token consumer,
     * and core/attribute/AllowWithToken.php (API tokens). Before this existed,
     * complete() and AllowWithToken each built the session by hand and had
     * already drifted apart — AllowWithToken set neither created_at nor
     * login_provider and never rotated the session id.
     *
     * @param User             $user     The account being signed in.
     * @param array|null       $identity Normalized provider identity, when the
     *                                   caller has one richer than the stored
     *                                   row (OAuth). Null builds it from the row.
     */
    public static function establish(User $user, ?array $identity = null): void
    {
        // Clear the per-request memo so a sign-in following an earlier
        // user() call in the same request does not keep returning the
        // previous user.
        self::$user = null;

        // Rotate at the privilege boundary. Application only rotates on a
        // timer (regenerateAgedSession()), which leaves a session-fixation
        // window at exactly the moment it matters. Guarded because CLI/test
        // contexts have no session.
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }

        // A sign-in that arrived on a session in its grace period (its id was
        // just replaced by the timer) gets a session of its own here, and that
        // one is not obsolete.
        unset($_SESSION['obsolete_at']);

        $_SESSION['user'] = $identity ?? [
            'full_name' => $user->name,
            'email'     => $user->email,
            'verified'  => $user->verified,
            'pic'       => $user->pic,
            'id'        => $user->id,
        ];

        // OUR id, never the provider's. A supplied $identity comes from an
        // adapter's format(), and all five put the provider's user id under
        // 'id' — a GitHub or GitLab integer, a Discord snowflake. Left alone,
        // the two branches of the assignment above disagree about what 'id'
        // means: a local users.id when built from the row, a foreign one when
        // the caller passed an identity. Overwriting here makes the key mean
        // one thing.
        //
        // Set at the writer rather than stripped in the adapters: GoogleAdapter
        // reads $this->user['id'] itself, for normalizeGoogleToken(), before it
        // ever returns the identity.
        $_SESSION['user']['id'] = $user->id;

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
    }

    /**
     * Tear down the login session. THE counterpart to establish(), and the only
     * place session teardown should live.
     *
     * There were three implementations of this before, and all three were
     * incomplete in different ways — the drift establish() was created to stop,
     * repeated on the way out:
     *
     *   Auth::logout()       cleared $_SESSION and expired the cookie, but left
     *                        self::$current set and passed only a path to
     *                        setcookie(), dropping secure/httponly/samesite.
     *   Jackpack::signout()  called session_destroy() alone: $_SESSION stayed
     *                        populated for the rest of the request and the
     *                        cookie was never cleared.
     *   this method          cleared the memo and the session but never touched
     *                        the cookie — and had ZERO callers, so the most
     *                        correct of the three was the one nothing used.
     *
     * The cookie attributes come from session_get_cookie_params() rather than
     * being written out by hand. A browser matches a replacement cookie on
     * name + path + domain, so a mismatch on either leaves the original in
     * place and the client keeps presenting a session id we destroyed.
     *
     * Safe to call with no active session (CLI, tests): it clears the memo and
     * $_SESSION, then returns before the calls that would warn.
     */
    public static function end(): void
    {
        self::$user = null;
        $_SESSION      = [];

        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }

        session_unset();

        if (ini_get('session.use_cookies') && isset($_COOKIE[session_name()])) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires'  => time() - 42000,
                'path'     => $p['path'],
                'domain'   => $p['domain'],
                'secure'   => $p['secure'],
                'httponly' => $p['httponly'],
                'samesite' => $p['samesite'] ?: 'Lax',
            ]);
            unset($_COOKIE[session_name()]);
        }

        session_destroy();
    }

    /**
     * End the session when its account is disabled. Application::run() calls
     * this once for each request, before the module is built, so an account an
     * admin disables is logged out at its next request. One query, and only
     * for a request that carries a login. When the database cannot be read
     * the session stays and the failure is logged.
     */
    public static function endIfDisabled(): void
    {
        $id = self::userId();
        if ($id === null) {
            return;
        }
        try {
            $disabled = User::isDisabledId($id);
        } catch (\Throwable $e) {
            // No database to ask (a site without one, or an outage): the session
            // stays. Every permission check reads the same database and fails there.
            Console::error("Session::endIfDisabled() could not read account $id: " . $e->getMessage());
            return;
        }
        if ($disabled) {
            Console::info("Session ended: account $id is disabled");
            self::end();
        }
    }

    /**
     * Does the logged-in user have the capability $key? False when nobody is
     * logged in.
     */
    public static function can(string $key): bool
    {
        return self::user()?->can($key) ?? false;
    }
}
