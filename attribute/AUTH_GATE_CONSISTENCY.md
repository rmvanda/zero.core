# Authentication gates: `#[RequireLogin]` vs hand-rolled checks

**Status:** Settled 2026-08-16 and enforced in code. The audit's fixes are done and are not
listed here; what is still open is under "Open" below. Checked against the code 2026-11-21.
**Code:** `core/attribute/RequireLogin.php`, `Session::isLoggedIn()` in `core/Session.php`,
`Application::resolveAttributes()`, `core/tests/Attribute/RequireLoginTest.php`

## The ruling

> **`#[RequireLogin]` is the correct way to gate an endpoint.** `Session::isLoggedIn()` is
> for cases where behaviour genuinely *differs* between a logged-in and an anonymous visitor
> — conditional branches, not gates.

If a branch on `isLoggedIn()` ends in a redirect, an error or an early `return`, it is a gate
and belongs in an attribute.

## One predicate

`#[RequireLogin]` means `Session::isLoggedIn()`: a session with `email` set and a truthy
`verified`. `RequirePermission::handler()` opens by calling
`(new RequireLogin())->handler()`, so it shares the predicate. `RequireAuthLevel` does not;
see "The third gate" below.

Why this matters: `RequireLogin` used to test `isset($_SESSION['user_id'])`, which is weaker.

| Session shape | old `RequireLogin` | `isLoggedIn()` |
|---|---|---|
| `user_id` + `email` + `verified=1` | pass | true |
| `user_id` + `email` + `verified=0` | **pass** | **false** |
| `user_id` only, no `email` | **pass** | **false** |

The second row is reachable: `AllowWithToken` calls `Session::establish()` for the token's
owner without checking `verified`. The gate now refuses that session.
`core/tests/Attribute/RequireLoginTest.php` pins both disagreeing rows as denials.

## Rules this produced

- **Gate with the attribute, not in the method body.** A hand-rolled check is the whole
  authentication boundary of its endpoint, with nothing behind it.
- **A class with any deliberately public endpoint gates per method**, not at class level.
  There is no way to drop a class-level gate for one endpoint (`ALLOW_PUBLIC.md` proposes
  one). `core/modules/Push` and the site's `modules/S` are the examples.
- **A class whose `__call()` serves anonymous traffic must not carry a class-level
  `#[RequireLogin]`.** A `__call`-dispatched endpoint has no `ReflectionMethod` of its own;
  attributes on `__call()` are read on its behalf but are additive on top of the class-level
  ones. A class-level `#[RequireLogin]` on `modules/TheButton` silently disabled its
  anonymous buttons and guest invite links until it was moved to the individual methods.
- **Never derive identity from anything but `$_SESSION['user_id']`.** See the next section.
- JSON callers get `HTTPError(401)` from `RequireLogin`, not a 302, so the attribute is
  usable on AJAX endpoints. Behaviour is documented in `core/docs/attributes/RequireLogin.md`.

## The Notes bypass (the record `Session::userId()` refers to)

`modules/Notes/Notes.php` had a live authentication bypass on 2026-08-16. Its eight
hand-written gates were migrated from `isset($_SESSION['user_id'])` to "current user id is
not null", and the id accessor then fell back to `$_SESSION['user']['id']`. `Auth::complete()`
wrote `$_SESSION['user']` — the provider's identity, carrying the provider's id — before the
verified-email check could throw. An OAuth attempt with an unverified provider email left a
session that passed all eight gates with a provider-controlled id.

What closed it, and must stay closed:

- Notes gates on `#[RequireLogin]`.
- `Session::userId()` reads `$_SESSION['user_id']` and nothing else, and rejects anything
  that is not a positive integer.
- `Session::establish()` is the single session writer and overwrites `$_SESSION['user']['id']`
  with the local id.

## The third gate: `RequireAuthLevel`

`RequireAuthLevel` is shelved (2026-08-16). It does not delegate to `RequireLogin`: it checks
`session_status()` and then `Session::user()?->authLevel()`, which reads
`user_settings['auth.level']` and never `users.verified`. It is the one gate an unverified
session can pass. Latent, not live: no module pairs it with `#[AllowWithToken]`, the only
producer of an unverified session.

`auth.level` is a separate `user_settings` key from the `allow.*` grants, so a level gate
beside a `#[RequirePermission]` vetoes permissions that were deliberately granted. Every such
pairing was removed on 2026-08-16. **Do not add new `#[RequireAuthLevel]` gates; use
`#[RequirePermission]`.**

Full reasoning and the un-shelving condition: `core/docs/attributes/RequireAuthLevel.md`.
The remaining bare uses and the work to retire them: `TODO.md`.

## Open

Each item is described in the file named.

1. `RequireAuthLevel`'s remaining bare gates — `TODO.md`.
2. The `// TODO` in `RequireLogin::handler()` on whether `verified` should be configurable —
   `TODO.md`.
3. Nothing enforces "no unverified user holds a permission" at `grant()` — `TODO.md`,
   "Verified-email invariant".
4. `#[RequireOwnership]` for the resolve → compare owner → reject preamble repeated in Paste
   and TheButton — `TODO.md`.
5. A class-level gate that one endpoint can opt out of — `ALLOW_PUBLIC.md`.

Not open: `AllowWithToken` refusing an unverified token owner was declined by the owner; see
the last entry of `TODO.md`.

## Reproducing the audit

From the site root:

```bash
# In-body login checks. Each hit should be a behaviour branch, not a gate.
/usr/bin/grep -rn "isLoggedIn()\|isset(\$_SESSION\['user_id'\])" --include=*.php modules/ core/modules/ | grep -v /tests/

# The gate's behaviour
./vendor/bin/phpunit core/tests/Attribute/RequireLoginTest.php --testdox
```

Use `/usr/bin/grep`, not the shell's `grep` — the latter is a shim that honours `.gitignore`
and under-reports.

As of 2026-11-21 the first command finds one hand-written gate left in a controller:
`User::permissions()` and `User::permission()` in `core/modules/User/User.php` each open with
`if (!isset($_SESSION['user_id'])) { $this->error(…); return; }` behind a `#[RequireLogin]`
that already guarantees it. Dead code; remove it with the `RequireAuthLevel` work in
`TODO.md`. The other hits (`Paste`, `TheButton`, `S`, `Notes`, `Slots`, `Ttrpg`, `Hexmap`,
`AccessRequest::permission()`, `Auth::complete()`) are behaviour branches, and the rest are
views.
