<?php
namespace Zero\Module;

use Zero\Core\Access\User;
use Zero\Core\Session;
use \Zero\Entity\Event;
use \Zero\Core\Console;
use \Zero\Core\Request;
use \Zero\Core\Attribute\RequireLogin;
use \Zero\Core\Attribute\AllowedMethods;
use \Zero\Module\Auth\Model\AuthToken;
use \Zero\Module\Auth\Model\RegistrationFields;
use \Zero\Lib\Mail\Mail;

/**
 * Class Auth
 *
 * Handles OAuth authentication with third-party services.
 *
 * Active endpoints:
 * - /auth/user - Initiates OAuth flow with selected provider
 * - /auth/complete - OAuth callback handler
 * - /auth/register - Manual (passwordless) registration + verification email
 * - /auth/signin - Request a one-time magic-link sign-in email
 * - /auth/token - Confirm (GET) and consume (POST) an emailed one-time link
 */
class Auth extends \Zero\Core\Module {

    private $workingSmid;
    private $user;
    private $auth;

    private $smids = array(
        0 => "Facebook",
        1 => "Twitter",
        2 => "Instagram",
        3 => "Google",
        4 => "Foursquare",
        5 => "Yelp",
        6 => "",
        7 => "PayPal",
        8 => "LinkedIn",
        9 => "Pinterest",
        10 => "Tumblr",
        11 => "",
        12 => "GitHub",
        13 => "Discord",
        14 => "GitLab"
    );

    /**
     * Providers whose tokens are persisted and that can be resolved by slug for
     * refresh (Broker). Keep display names in sync with $smids.
     */
    private const PERSISTED_PROVIDERS = ['Google', 'GitHub', 'Discord', 'GitLab'];

    /** The namespace of the provider adapters in adapters/. */
    private const ADAPTER_NAMESPACE = "\\Zero\\Module\\Auth\\Adapters\\";

    /** Magic-link TTL: short, because it is a live credential. */
    private const TOKEN_TTL_LOGIN = 900;        // 15 minutes

    /** Verification TTL: long, because it is often opened later on another device. */
    private const TOKEN_TTL_VERIFY = 86400;     // 24 hours

    /** Live unconsumed tokens allowed per user per purpose. */
    private const MAX_LIVE_TOKENS = 3;

    /**
     * Token issuances allowed per client IP per hour before the endpoint refuses.
     *
     * Per CLIENT IP (Request::clientIp()), which is best-effort attribution, not
     * an identity: a household or office behind one NAT shares a counter. Hence
     * a cap high enough that ordinary shared egress does not trip it.
     */
    private const MAX_TOKENS_PER_IP = 10;
    private const IP_WINDOW = 3600;

    public function __construct(){
        parent::__construct();
    }

    /**
     * Begins the handling of OAuth authentication with third-party services.
     */
    public function user()
    {
        unset($_SESSION['oauth_connect']);   // a normal login is never a connect
        $this->loadAdapter();
        $this->assertProviderAvailable();
        $this->performOAuth();
    }

    /**
     * Begin connecting an ADDITIONAL OAuth provider to the current account.
     *
     * Unlike user() (a login), this attaches the provider's tokens to the
     * already-logged-in account by user_id instead of matching/creating an
     * account by email — so e.g. a Google user can add Discord for a feature
     * that needs it. The `oauth_connect` marker is read back in complete().
     * Entry: /auth/connect?via={code}
     */
    #[RequireLogin]
    public function connect()
    {
        $this->loadAdapter();               // reads ?via from the query; 404s on unknown provider
        $this->assertProviderAvailable();   // registered providers must be admin-enabled + configured
        $_SESSION['oauth_connect'] = true;
        $this->performOAuth();
    }

    /**
     * Disconnect an OAuth provider from the current account (removes its stored
     * tokens). POST { provider: slug }. Protected the same way as the other
     * mutating endpoints: RequireLogin + POST-only + the SameSite=Lax session
     * cookie (the framework has no CSRF-token mechanism).
     * Don't waste time thinking about CSRF, it's handled by SameSite and competent programming:
     * see modules/Issue/CSRF.md.
     * Entry: POST /auth/disconnect
     */
    #[RequireLogin]
    #[AllowedMethods('POST')]
    public function disconnect()
    {
        $slug = strtolower(trim($_POST['provider'] ?? ''));
        if ($slug !== '' && preg_match('/^[a-z0-9_]+$/', $slug)) {
            \Zero\Module\Auth\OAuth\Token::forget((int) \Zero\Core\Session::userId(), $slug);
        }
        header('Location: /user/profile');
    }

    /**
     * OAuth callback handler
     */
    public function complete()
    {
        // The callback no longer needs ?via — the provider's via code is encoded in
        // the OAuth `state` we issued (as "{via}:..."). Google one-tap (credential
        // POST) still arrives with ?via, so loadAdapter() falls back to that when
        // state carries no via prefix.
        $via = null;
        if (!empty($_GET['state']) && is_string($_GET['state'])
            && preg_match('/^(\d+):/', $_GET['state'], $m)) {
            $via = (int) $m[1];
        }
        $this->loadAdapter($via);

        // Connect flow: the user initiated /auth/connect while logged in, so bind
        // this provider to their EXISTING account (by user_id) and skip the normal
        // email-matching/login entirely — connecting is authorization, not a login.
        // The empty-credential guard ensures a Google one-tap POST (which carries
        // no ?code) can never be misread as a connect via a stale marker.
        if (!empty($_SESSION['oauth_connect']) && \Zero\Core\Session::isLoggedIn() && empty($_POST['credential'])) {
            unset($_SESSION['oauth_connect']);
            $this->completeConnect((int) \Zero\Core\Session::userId());
            return;
        }

        // Held in a local until it is proven. This USED to assign
        // $_SESSION['user'] on both branches before the gate below could
        // throw, which left a refused sign-in with a session carrying a
        // provider-controlled identity — including the PROVIDER's user id
        // under the key 'id'. Session::userId() read that key as a fallback,
        // so a 403'd OAuth attempt could still answer "logged in as <someone>"
        // to 182 call sites. Nothing reaches the session until it is proven.
        if (!empty($_POST['credential']) && method_exists($this->auth, 'verifyIdToken')) {
            $guser    = $this->auth->verifyIdToken($_POST['credential']);
            $identity = $this->auth->format($guser);
        } else {
            $identity = $this->auth->getCurrentUser();
        }

        // Every provider identity, from every adapter, on both branches, lands
        // here — so this is the one place the gate has to be. It is checked
        // BEFORE findOrCreateUser(), because that is what matches an account by
        // email and what promotes a pre-registered row to verified.
        self::enforceVerifiedEmail($identity);

        // Proven — publish it. findOrCreateUser() writes
        // $_SESSION['user']['new'] on a new account, so the key has to exist
        // before that call.
        $this->user = $_SESSION['user'] = $identity;

        // Get or create the account, then build the session. Both this path and
        // the manual sign-in paths funnel through findOrCreateUser(), so
        // auto-linking on email is automatic rather than something each path
        // has to remember.
        $user = $this->findOrCreateUser($_SESSION['user']);
        if ($user->isDisabled()) {
            // The provider proved the address, but the account is closed
            Session::end();
            throw new \Zero\Core\Exception\HTTPError(403, 'This account is disabled.');
        }
        Session::establish($user, $_SESSION['user']);

        // Persist the provider's OAuth tokens so the server can refresh access
        // without forcing re-login (see Zero\Module\Auth\OAuth\Broker). Only full-OAuth
        // paths expose a bundle; Google one-tap (identity-only) yields none.
        // Wrapped so a persistence failure can never break a user's login.
        try {
            if (method_exists($this->auth, 'tokenBundle')) {
                $bundle = $this->auth->tokenBundle();
                if (!empty($bundle['access_token'])) {
                    $providerSlug = strtolower($this->smids[$this->workingSmid] ?? '');
                    if ($providerSlug !== '') {
                        \Zero\Module\Auth\OAuth\Token::store($user->id, $providerSlug, $bundle);
                    }
                }
            }
        } catch (\Throwable $e) {
            Console::error("OAuth token persist failed: " . $e->getMessage());
        }

        // Check if there's a return URL from RequireAuthLevel
        //
        // Re-checked here even though #[RequireLogin] already guards what it
        // stores: $_SESSION is a shared bucket, this is the end that actually
        // emits the Location header, and a future writer of the key would not
        // pass through the attribute. Burn the key either way — a value we
        // refuse to use must not survive to the next login.
        $redirectUrl = '/';
        if (isset($_SESSION['return_url'])) {
            $stored = $_SESSION['return_url'];
            unset($_SESSION['return_url']);
            if (Request::isSiteRelative($stored)) {
                $redirectUrl = $stored;
            } else {
                Console::warn("Auth::complete ignoring non-relative return_url '{$stored}'");
            }
        }

        header("Location: " . $redirectUrl);
    }

    /**
     * Refuse a provider identity whose email is not proven.
     *
     * This lives in Auth, not in the adapters, because the invariant belongs to
     * Auth: complete() matches accounts by email ALONE, so it is Auth that needs
     * the address proven. An adapter faithfully reporting `verified => false` is
     * doing its job correctly — it has no business throwing an HTTP error about
     * a policy it does not own.
     *
     * Enforcing at the consumer also cannot be forgotten. Each adapter used to
     * carry its own copy, so a new adapter could simply omit it and silently
     * skip the gate; a check applied to the adapter's OUTPUT cannot be skipped
     * by a new adapter at all.
     *
     * filter_var rather than a strict comparison: GoogleAdapter passes Google's
     * value through unnormalized ($user['verified_email'] ?? $user['email_verified']),
     * unlike the others, so `=== true` would read a "true" string as unverified
     * and lock the user out.
     *
     * @param array $identity Normalized identity: ['full_name','email','verified','pic'].
     * @throws HTTPError 401 when no email, 403 when the provider did not verify it.
     */
    public static function enforceVerifiedEmail(array $identity): void
    {
        if (empty($identity['email'])) {
            throw new \Zero\Core\Exception\HTTPError(401, "Provider identity missing email");
        }

        if (filter_var($identity['verified'] ?? false, FILTER_VALIDATE_BOOLEAN) !== true) {
            throw new \Zero\Core\Exception\HTTPError(403, "Provider email not verified");
        }
    }

    /**
     * Find the account for a normalized provider identity, or create it.
     *
     * The ONLY place a User is constructed. Registration and OAuth both come
     * through here, which is what makes auto-linking on email automatic.
     *
     * @param array $identity ['full_name','email','verified','pic']
     */
    private function findOrCreateUser(array $identity): \Zero\Core\Access\User
    {
        $user = User::findByEmail($identity['email']);
        if ($user) {
            // Promote a registered-but-unconfirmed row when the provider has
            // already proven mailbox ownership. Without this, anyone can POST
            // /auth/register with someone else's address and lock them out of
            // OAuth forever: complete() matches by email, establish() copies the
            // stored verified = 0 into the session, and isLoggedIn() stays false
            // with nothing in the logs. This is the OAuth equivalent of consuming
            // a verify token — enforceVerifiedEmail() above has already refused
            // any unverified identity, so it upholds the verified-email invariant rather
            // than weakening it. Gated on the identity, never on the stored row.
            // filter_var, not === true: GoogleAdapter::format() passes Google's
            // value straight through with no normalization ($user['verified_email']
            // ?: $user['email_verified']), unlike every other adapter. A strict
            // comparison would silently decline to promote if Google ever returned
            // "true" or 1 instead of a boolean — leaving a victim locked out, which
            // is precisely what this promotion exists to prevent. FILTER_VALIDATE_BOOLEAN
            // still rejects false, "false", 0, "" and null.
            if ((int) $user->verified !== 1
                && filter_var($identity['verified'] ?? false, FILTER_VALIDATE_BOOLEAN) === true) {
                $user->verified = 1;
                $user->save();
                $user = User::findByEmail($identity['email']);   // re-read stored value
            }
            return $user;
        }

        $event = new Event([
            "datetime" => date('Y-m-d H:i:s', time()),
            "event"    => "user_created",
            "detail"   => $identity['email'],
            "ip"       => $_SERVER['REMOTE_ADDR']
        ]);

        $email_body = "A new user was added: \n\n".
            "\tname: ".    $identity['full_name'].
            "\n\temail: ". $identity['email'].
            "\n\tverified: ". ($identity['verified'] ? "true" : "false");
        \Zero\Lib\Mail\Mail::sendEmailToAdmin("New User Registered", $email_body);

        Console::info("New user registered: " . $identity['email'] . " (verified: " . ($identity['verified'] ? "true" : "false") . ")");

        $_SESSION['user']['new'] = true;

        try {
            return User::create([
                "name"     => $identity['full_name'],
                "email"    => $identity['email'],
                "verified" => $identity['verified'] ? 1 : 0,
                "pic"      => $identity['pic'] ?? null,
            ]);
        } catch (\PDOException $e) {
            // Lost a race against a concurrent sign-in for the same address.
            // users.email carries a UNIQUE KEY, so exactly one INSERT wins and
            // the loser simply re-reads the row the winner created.
            if (\Zero\Core\Model::isDuplicateKey($e)) {
                $existing = User::findByEmail($identity['email']);
                if ($existing) {
                    return $existing;
                }
            }
            throw $e;
        }
    }

    /**
     * Finish a connect flow: validate the provider callback, capture its token
     * bundle, and store it against the CURRENT account. Never changes session
     * identity — the user stays logged in as themselves. Called from complete().
     */
    private function completeConnect(int $userId): void
    {
        // getCurrentUser() validates state/code, fetches the provider identity,
        // and captures the token bundle. We keep only the tokens; the identity is
        // irrelevant to an already-authenticated user.
        $identity = $this->auth->getCurrentUser();

        // Gated explicitly. Connecting never matches by email — it binds tokens
        // to the already-authenticated user_id — so the account-matching
        // invariant does not strictly apply here. It is enforced anyway because
        // storing a provider's refresh tokens against an identity the provider
        // will not vouch for is its own bad idea. This used to happen implicitly
        // inside getCurrentUser(); it is explicit now that the gate lives here.
        self::enforceVerifiedEmail(is_array($identity) ? $identity : []);

        $slug = strtolower($this->smids[$this->workingSmid] ?? '');
        if ($slug !== '' && method_exists($this->auth, 'tokenBundle')) {
            $bundle = $this->auth->tokenBundle();
            if (!empty($bundle['access_token'])) {
                \Zero\Module\Auth\OAuth\Token::store($userId, $slug, $bundle);
            }
        }

        header('Location: /user/profile');
    }

    /**
     * Enforce the admin enable/configured gate for a REGISTERED provider so the
     * oauth_provider registry is authoritative, not just UI-level hiding — a
     * disabled provider can't be used via a direct /auth/user or /auth/connect
     * link. A provider that is not registered is not gated.
     * Fails open if the registry itself errors; provider credentials still gate use.
     */
    private function assertProviderAvailable(): void
    {
        try {
            $slug = strtolower($this->smids[$this->workingSmid] ?? '');
            $p = $slug !== '' ? \Zero\Module\Auth\OAuth\Provider::bySlug($slug) : null;
            if ($p !== null && (!$p['enabled'] || !$p['configured'])) {
                throw new \Zero\Core\Exception\HTTPError(404, "Provider not available");
            }
        } catch (\Zero\Core\Exception\HTTPError $e) {
            throw $e;
        } catch (\Throwable $e) {
            Console::warn("Provider availability check failed: " . $e->getMessage());
        }
    }

    /**
     * Logout - destroy session and redirect to home
     */
    public function logout()
    {
        // Teardown lives in Session::end(), the counterpart to Session::establish().
        // This method used to do it by hand and had drifted: it left
        // User::$current set, and its setcookie() passed only a path, so the
        // replacement cookie dropped secure/httponly/samesite.
        \Zero\Core\Session::end();

        // Redirect to home
        header("Location: /");
        exit;
    }

    /**
     * Cap outstanding links per user and issuance rate per IP.
     *
     * Anyone can trigger an email from these endpoints, so this is the only
     * thing between the site and being used as a mail cannon.
     *
     * KNOWN RISK, accepted: the per-IP counter cannot tell one attacker from one
     * office behind NAT or a carrier CGNAT, so the cap is set high enough that
     * ordinary shared egress does not trip it and the effect of tripping it is
     * only a 429 on this endpoint.
     *
     * It deliberately does NOT escalate to a Cloudflare edge ban. An earlier
     * revision did, on $_SERVER['REMOTE_ADDR'] — which behind Cloudflare is the
     * POP's egress address, so it would have 429'd every visitor through that
     * POP while banning an address no visitor ever has. Both halves are fixed:
     * the count is per Request::clientIp(), and refusal stops at the 429 plus
     * an Event row, because this endpoint is an unauthenticated POST with no
     * CSRF check and an automatic ban would be a victim-lockout primitive.
     */
    private function throttle(?int $userId, string $purpose): bool
    {
        if ($userId !== null && AuthToken::liveCountForUser($userId, $purpose) >= self::MAX_LIVE_TOKENS) {
            // Decline rather than burning the oldest. Burning would let anyone
            // POST /auth/signin three times with a victim's address and destroy
            // the link the victim is about to click — a targeted denial of
            // sign-in, repeatable at will. The caller still renders the identical
            // "check your email" page, so declining leaks nothing.
            Console::warn("Auth throttle: {$purpose} cap reached for user {$userId}, not issuing");
            return false;
        }

        $ip = \Zero\Core\Request::clientIp();
        if ($ip === '') {
            return true;
        }

        if (AuthToken::issuedFromIpSince($ip, self::IP_WINDOW) >= self::MAX_TOKENS_PER_IP) {
            // Record and refuse, but do NOT escalate to a Cloudflare edge ban.
            // This endpoint is an unauthenticated POST with no CSRF check, so an
            // attacker can make a victim's browser hit it — an automatic ban
            // would hand them a primitive for locking arbitrary people out of
            // the whole site. A 429 plus this Event is enough; escalate by hand
            // after reading the log.
            new Event([
                "datetime" => date('Y-m-d H:i:s', time()),
                "event"    => "auth_throttled",
                "detail"   => "auth token flood from $ip (limit ".self::MAX_TOKENS_PER_IP
                              ." per ".self::IP_WINDOW."s)",
                "ip"       => $ip
            ]);
            Console::warn("Auth throttle tripped for $ip");
            throw new \Zero\Core\Exception\HTTPError(429, "Too many requests");
        }

        return true;
    }

    /**
     * Manual registration.
     *
     * GET renders the form from the field definitions. POST validates, creates
     * the account with verified = 0, and emails a verification link.
     *
     * NO SESSION IS ESTABLISHED HERE. Zero\Core\Session::isLoggedIn() requires a
     * truthy `verified`, so the account is inert until the emailed link is
     * clicked — which is what keeps the email-only account matching in
     * complete() safe against someone registering an address they don't own.
     */
    #[AllowedMethods(['GET', 'POST'])]
    public function register()
    {
        if (\Zero\Core\Request::$method !== 'POST') {
            $this->fields = RegistrationFields::all();
            $this->errors = [];
            $this->values = [];
            $this->respond($this->viewPath . 'register.php');
            return;
        }

        // is_string guard, not a bare trim(): email[]=x makes $_POST['email'] an
        // array, and trim() throws TypeError on one. Output is already buffered
        // by this point, so that surfaces as a 200 with a broken page rather than
        // an error. Mirrors the guard RegistrationFields::validate() applies to
        // every configured field — email is handled here, outside that loop.
        //
        // Normalised immediately so the whole method works on the canonical form:
        // users.email is byte-exact (utf8mb4_bin), so a lookup that differs only
        // in case would silently miss. See Zero\Core\Access\User::normalizeEmail().
        $email = User::normalizeEmail(is_string($_POST['email'] ?? null) ? $_POST['email'] : '');

        // Collected by the form itself rather than by RegistrationFields: `name`
        // is a column on users, and RESERVED forbids it ever being declared as a
        // configurable field. Same is_string guard as email — name[]=x would
        // otherwise TypeError inside trim(), and output is already buffered by
        // this point, so that surfaces as a 200 with a broken page.
        $name = is_string($_POST['name'] ?? null) ? trim($_POST['name']) : '';

        [$clean, $errors] = RegistrationFields::validate($_POST);

        if ($email === '' || strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Please enter a valid email address.';
        }

        // 191 is the users.name column width. Rejecting here rather than letting
        // MySQL truncate means the account never stores a name the user did not type.
        if ($name === '') {
            $errors['name'] = 'Please enter your name.';
        } elseif (mb_strlen($name) > 191) {
            $errors['name'] = 'Your name must be 191 characters or fewer.';
        }

        if ($errors) {
            $this->fields = RegistrationFields::all();
            $this->errors = $errors;
            $this->values = $_POST;
            $this->respond($this->viewPath . 'register.php');
            return;
        }

        $existing = User::findByEmail($email);

        if ($existing) {
            // Do NOT reveal that the address is taken — that is an enumeration
            // oracle. Send the existing account a sign-in link instead, so the
            // response is identical either way.
            //
            // Each branch throttles the purpose it is about to ISSUE. Counting
            // 'login' while sendVerifyLink() writes a 'verify' row would mean
            // the per-user cap never applies to what is actually being issued
            // and 24-hour verify tokens accumulate without a cap.
            if ((int) $existing->verified === 1) {
                if ($this->throttle($existing->id, 'login')) {
                    $this->sendLoginLink($existing);
                }
            } else {
                if ($this->throttle($existing->id, 'verify')) {
                    $this->sendVerifyLink($existing);
                }
            }
        } else {
            // New account: no user-scoped cap can apply yet — there is no user
            // id until create() runs — but the per-IP limit must still run
            // BEFORE the insert, so a flood is stopped without leaving rows.
            $this->throttle(null, 'verify');

            // Whether THIS request is the one that created the row. The race
            // loser must not write its profile values onto the winner's account.
            $created = false;

            try {
                $user = User::create([
                    'name'     => $name,
                    'email'    => $email,
                    'verified' => 0,
                    'pic'      => null,
                ]);
                $created = true;
            } catch (\PDOException $e) {
                // Lost a race; the winner's row is authoritative.
                if (!\Zero\Core\Model::isDuplicateKey($e)) {
                    throw $e;
                }
                $user = User::findByEmail($email);
            }

            if ($user) {
                // Only the creator stores the submitted profile fields. A loser
                // re-read someone else's row, and writing attacker-supplied
                // values onto it would be a profile-overwrite primitive.
                if ($created) {
                    $user->storeProfile($clean, RegistrationFields::all());
                }
                $this->sendVerifyLink($user);
            }
        }

        $this->sent = true;
        $this->respond($this->viewPath . 'register.php');
    }

    /**
     * Passwordless sign-in: enter an email, receive a one-time link.
     *
     * Responds identically whether or not the address has an account. Revealing
     * it would be an enumeration oracle, so this deliberately drops the old
     * AUTH_USER_DNE / AUTH_USER_ALREADY_REGISTERED messages.
     */
    #[AllowedMethods(['GET', 'POST'])]
    public function signin()
    {
        if (\Zero\Core\Request::$method !== 'POST') {
            $this->respond($this->viewPath . 'signin.php');
            return;
        }

        // is_string guard, not a bare trim(): email[]=x makes $_POST['email'] an
        // array and trim() throws TypeError on one. Same guard register() applies.
        $email = User::normalizeEmail(is_string($_POST['email'] ?? null) ? $_POST['email'] : '');
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $user = User::findByEmail($email);

            if ($user && $user->isDisabled()) {
                // A disabled account gets no link. The page answers as for any address.
            } elseif ($user && (int) $user->verified === 1) {
                if ($this->throttle($user->id, 'login')) {
                    $this->sendLoginLink($user);
                }
            } elseif ($user) {
                // Registered but never confirmed — resend the verification link
                // rather than a login link, so the account can still be rescued.
                //
                // Unverified: resend verification, so the throttle must count
                // 'verify' — the purpose actually being issued.
                if ($this->throttle($user->id, 'verify')) {
                    $this->sendVerifyLink($user);
                }
            } else {
                // Unknown address: no user-scoped cap applies, but the per-IP
                // limit must still run or an attacker could enumerate for free.
                $this->throttle(null, 'login');
            }
        }

        $this->sent = true;
        $this->respond($this->viewPath . 'signin.php');
    }

    /**
     * Consume an emailed link.
     *
     * GET renders a confirmation page; only POST burns the token. Mail scanners
     * and link-preview bots pre-fetch URLs in email, and a GET-consumes design
     * would let a scanner burn the token before the human clicks — an
     * intermittent, org-specific failure that looks like nothing in the logs.
     */
    #[AllowedMethods(['GET', 'POST'])]
    public function token()
    {
        // Keep the token out of any third-party Referer.
        header('Referrer-Policy: no-referrer');

        // is_string rather than a (string) cast: t[]=x makes the value an array,
        // and casting one emits an "Array to string conversion" warning into the
        // page. A non-string token is simply no token.
        $rawIn = $_POST['t'] ?? $_GET['t'] ?? null;
        $raw   = is_string($rawIn) ? $rawIn : '';

        // DO NOT move token consumption above this return, and do not call a
        // helper from this branch that consumes. GET must only render the
        // confirm form. Mail scanners and link-preview bots pre-fetch URLs in
        // email; if GET burned the token, a scanner would destroy it before the
        // human clicked, and sign-in would fail intermittently, per-organisation,
        // with nothing useful in the logs. No automated test covers this — the
        // class is not autoloadable, so nothing can execute this method — so this
        // comment and the manual check are the only guards.
        if (\Zero\Core\Request::$method !== 'POST') {
            // Bind the confirm form to THIS browser's session. Without it,
            // /auth/token is a login-CSRF sink: an attacker can request a link
            // for their own account and auto-POST it from a page the victim
            // visits, silently signing the victim into the attacker's account.
            // SameSite offers nothing — the attack creates a session rather
            // than riding one. This also re-imposes the property the design
            // already assumed: that the click came from our own interstitial.
            $selector = str_contains($raw, ':') ? explode(':', $raw, 2)[0] : '';
            $nonce    = bin2hex(random_bytes(16));
            // Keyed by selector, not a single shared slot: two interstitials
            // open at once would otherwise clobber each other, and a failed
            // submit would clear a good tab's nonce. Capped so the session
            // cannot grow without bound.
            //
            // is_array guard: sessions predating this change hold a bare STRING
            // here from the single-slot version. `$string + []` is a fatal
            // TypeError, so a live session mid-upgrade would 500 on this line.
            // Assign-then-slice rather than the `+` union operator: `+` keeps
            // the LEFT operand on a duplicate key, so re-rendering the confirm
            // page for the same token would show a nonce the session never
            // stored — the exact "valid link, invalid nonce" failure this fix
            // exists to remove. Appending also keeps newest last, so the -5
            // cap evicts the oldest interstitial rather than the newest.
            $nonces = is_array($_SESSION['token_nonce'] ?? null) ? $_SESSION['token_nonce'] : [];
            unset($nonces[$selector]);
            $nonces[$selector]       = $nonce;
            $_SESSION['token_nonce'] = array_slice($nonces, -5, null, true);
            $this->nonce = $nonce;
            $this->token = $raw;

            // Say WHICH account the click will sign you into. The nonce stops a
            // silent auto-POST, but not a lured top-level click on
            // /auth/token?t=ATTACKER_TOKEN — that mints a valid nonce in the
            // victim's own session and renders an otherwise unremarkable
            // confirm page. Masked, so a stolen token is not also an
            // address-disclosure oracle. peek() performs no UPDATE; the purpose
            // order matches the POST branch (verify before login).
            $peek = AuthToken::peek($raw, 'verify') ?? AuthToken::peek($raw, 'login');
            $this->account = null;
            if ($peek !== null && ($peekUser = User::find($peek))) {
                $email = (string) $peekUser->email;
                $at    = strpos($email, '@');
                $this->account = $at > 0
                    ? substr($email, 0, 1) . str_repeat('*', max(1, $at - 1)) . substr($email, $at)
                    : null;
            }

            $this->respond($this->viewPath . 'token-confirm.php');
            return;
        }

        // The POST must carry the nonce from the interstitial we rendered.
        // Single-use: consumed whether or not it matches.
        $selector = str_contains($raw, ':') ? explode(':', $raw, 2)[0] : '';
        $nonce    = is_string($_POST['n'] ?? null) ? $_POST['n'] : '';
        // Same is_array guard as the GET branch: on a pre-upgrade session this
        // is a bare string, and unset($string['selector']) is a fatal Error.
        $nonces   = is_array($_SESSION['token_nonce'] ?? null) ? $_SESSION['token_nonce'] : [];
        $expected = (string) ($nonces[$selector] ?? '');
        unset($nonces[$selector]);                    // single use, this token only
        $_SESSION['token_nonce'] = $nonces;           // write back; also drops any legacy string

        if ($expected === '' || !hash_equals($expected, $nonce)) {
            $this->respond($this->viewPath . 'token-invalid.php');
            return;
        }

        // Try verification first: a verify token both confirms the address and
        // signs in, so it must not fall through to the login branch.
        $userId  = AuthToken::consume($raw, 'verify');
        $verify  = $userId !== null;

        if (!$verify) {
            $userId = AuthToken::consume($raw, 'login');
        }

        if ($userId === null) {
            $this->respond($this->viewPath . 'token-invalid.php');
            return;
        }

        $user = User::find($userId);
        if (!$user || $user->isDisabled()) {
            $this->respond($this->viewPath . 'token-invalid.php');
            return;
        }

        if ($verify && (int) $user->verified !== 1) {
            $user->verified = 1;
            $user->save();
            $user = User::find($userId);   // re-read so the session gets the stored value
        }

        \Zero\Core\Session::establish($user);
        $_SESSION['login_provider'] = 'email';

        // Reaping expired tokens deliberately does NOT happen here. The IP
        // throttle counts created_at over a one-hour window while login tokens
        // expire in fifteen minutes, so purging on this path would delete the
        // very rows the throttle relies on and quadruple the mail an attacker
        // can pull. AuthToken::purgeExpired() belongs in a cron/maintenance
        // task. Rows are small and indexed; letting them accumulate is fine.

        // Same guard as complete() — see the note there for why the read side
        // checks a value the writer already checked.
        $redirect = '/';
        if (isset($_SESSION['return_url'])) {
            $stored = $_SESSION['return_url'];
            unset($_SESSION['return_url']);
            if (Request::isSiteRelative($stored)) {
                $redirect = $stored;
            } else {
                Console::warn("Auth::token ignoring non-relative return_url '{$stored}'");
            }
        }
        header('Location: ' . $redirect);
    }

    /** Email a verification link. Consuming it marks the account verified and signs in. */
    private function sendVerifyLink(\Zero\Core\Access\User $user): void
    {
        $token = AuthToken::issue($user->id, 'verify', self::TOKEN_TTL_VERIFY);
        $link  = 'https://' . PRIMARY_DOMAIN . '/auth/token?t=' . urlencode($token);

        ob_start();
        include $this->viewPath . 'email/verify.php';
        $body = ob_get_clean();

        Mail::sendEmailTo($user->email, AUTH_EMAIL_VERIFY_SUBJECT, $body);
    }

    /**
     * Email a one-time sign-in link to a verified account.
     *
     * Defined here rather than with the /auth/signin endpoint because
     * register() already needs it: a registration attempt on an address that
     * already exists sends a sign-in link instead of revealing that the address
     * is taken.
     */
    private function sendLoginLink(\Zero\Core\Access\User $user): void
    {
        $token = AuthToken::issue($user->id, 'login', self::TOKEN_TTL_LOGIN);
        $link  = 'https://' . PRIMARY_DOMAIN . '/auth/token?t=' . urlencode($token);

        ob_start();
        include $this->viewPath . 'email/login.php';
        $body = ob_get_clean();

        Mail::sendEmailTo($user->email, \Zero\Core\Strings::fill(AUTH_EMAIL_LOGIN_SUBJECT, SITE_SHORT_NAME), $body);
    }

    private function loadAdapter($via = null)
    {
        // Fall back to the ?via query param when no via is supplied — the /auth/user
        // login link still carries it; /auth/complete resolves it from state instead.
        $via = filter_var($via ?? ($_GET['via'] ?? null), FILTER_VALIDATE_INT);

        if ($via === false || !isset($this->smids[$via]) || $this->smids[$via] === "") {
            throw new \Zero\Core\Exception\HTTPError(404, "Unknown auth provider");
        }

        // The adapter is Zero\Module\Auth\Adapters\{Name}Adapter in adapters/.
        // classFile() answers without a log line for a provider that has none.
        $ac = self::ADAPTER_NAMESPACE . $this->smids[$via] . "Adapter";
        if (\Zero\Core\Application::classFile($ac) === null) {
            throw new \Zero\Core\Exception\HTTPError(404, "Unknown auth provider");
        }

        $this->workingSmid = $via;
        $this->auth = new $ac();
    }

    /**
     * Resolve an OAuth adapter instance by provider slug (e.g. 'github'), for
     * token refresh outside the login flow. Returns null if the provider isn't
     * persist-capable. Names the adapter class as loadAdapter() does.
     */
    public static function adapterForProvider(string $provider): ?object
    {
        foreach (self::PERSISTED_PROVIDERS as $name) {
            if (strtolower($name) === strtolower($provider)) {
                $class = self::ADAPTER_NAMESPACE . $name . 'Adapter';
                if (\Zero\Core\Application::classFile($class) !== null) {
                    return new $class();
                }
                return null;
            }
        }
        return null;
    }

    private function performOAuth()
    {
        $this->auth->openAuthorizationUrl();
    }
}
