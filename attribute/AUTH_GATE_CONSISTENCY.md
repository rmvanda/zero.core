# Authentication gates: `#[RequireLogin]` vs hand-rolled checks

**Status:** Settled 2026-08-16. The ruling below is now enforced in code.
**Files:** `core/attribute/RequireLogin.php`, `core/User.php:84-89`,
`core/Application.php:231-252`, `core/attribute/AllowWithToken.php:94-101`,
`core/tests/Attribute/RequireLoginTest.php`

## Why this exists

Someone noticed that `modules/TheButton/TheButton.php` calls `User::isLoggedIn()` **13 times**
inside a class already carrying `#[RequireLogin]`, and reasonably concluded those calls can never
return false — dead code to be deleted.

**That conclusion is wrong, and deleting them would remove a real gate.** The audit below explains
why, and turns up a second, larger problem pointing the opposite way.

## The two predicates are not the same check

```php
// core/attribute/RequireLogin.php:41 — what the attribute enforced
if (session_status() == PHP_SESSION_NONE || !isset($_SESSION['user_id'])) { … redirect … }

// core/User.php:86-88 — what isLoggedIn() answers
return isset($_SESSION['email'])
    && isset($_SESSION['verified'])
    && $_SESSION['verified'];
```

`#[RequireLogin]` asked **"is there a user id in the session?"**
`isLoggedIn()` asks **"is there a fully established session for a verified account?"**

Measured, 2026-08-16, before the fix:

| Session shape | `#[RequireLogin]` | `isLoggedIn()` | |
|---|---|---|---|
| `user_id` + `email` + `verified=1` | pass | true | agree |
| `user_id` + `email` + **`verified=0`** | **pass** | **false** | **disagree** |
| `user_id` only, no `email` | **pass** | **false** | **disagree** |
| `$_SESSION['user']` only, no `user_id` | fail | false | agree |

So inside a `#[RequireLogin]` class, `isLoggedIn()` was not redundant — it was **strictly
stricter**. It additionally demanded a verified email.

## Is the disagreement reachable?

Yes, by design; no, by the data of the day.

`core/attribute/AllowWithToken.php:94-101` loads the token's owner and calls
`User::establish($user)` **without checking `verified`**. `establish()` writes
`$_SESSION['verified'] = $user->verified`, so an API token belonging to an unverified account
produces exactly the second row of that table: `#[RequireLogin]` passes, `isLoggedIn()` denies.

At the time that could not happen — all 9 rows in `users` had `verified = 1`, and all 3
`api_tokens` belonged to verified accounts. **The 13 checks were unreachable by coincidence of
data, not by construction.** One unverified account with an API token would have made them live
again.

`AllowWithToken` still establishes a session for an unverified owner. The attribute now refuses
that session downstream, which is the outcome that matters, but tightening `AllowWithToken`
itself remains worthwhile.

## The ruling

The owner has decided, 2026-08-16:

> **`#[RequireLogin]` is the correct way to gate an endpoint.** `isLoggedIn()` is for cases where
> behaviour genuinely *differs* between a logged-in and an anonymous visitor — conditional
> branches, not gates.

and directed that `RequireLogin` be changed to enforce `isLoggedIn()`'s predicate, carrying
`// TODO: make this configurable on whether verified should be a gate or not`.

**That change is done** (`core/attribute/RequireLogin.php`). The two predicates no longer
disagree, which settles item 1 below: TheButton's 13 in-body checks are now genuinely dead and
can be deleted safely. It also tightens every gated endpoint — a session holding `user_id`
without a truthy `verified` is now redirected to login rather than admitted.

`core/tests/Attribute/RequireLoginTest.php` pins both disagreeing rows of the table as denials.

### Two further changes the ruling required

**`#[RequireLogin]` takes an optional redirect.**

```php
#[RequireLogin]                     // → /user/login?r=y
#[RequireLogin('/notes/welcome')]   // → a landing page of the module's choosing
```

Site-relative paths only. Anything else is ignored with a `Console::warn()` and the default login
page is used — the value comes from source rather than from the request, so this is a typo guard,
but a stray absolute URL would still be an open redirect and falling back is the harmless failure.

**JSON callers get a 401, not a 302.** `RequireLogin` throws
`HTTPError(401, "Authentication required")` when `Request::$acceptsJSON`. An XHR follows a 302
transparently and hands its caller the login page's HTML, which then fails to parse as JSON — the
failure surfaces as a parse error rather than "you are not logged in". This is what made it
possible to put the attribute on AJAX endpoints that were previously stuck hand-rolling
`$this->error('Authentication required')`.

## Audit: both directions

Every module class (`extends …Module`) that performs an in-body auth check —
`isLoggedIn()`, `isset`/`empty($_SESSION['user_id'])`, or `currentId() === null`.

### Gated classes that also check by hand — looks redundant, isn't quite

| File | Class-level gate | In-body checks |
|---|---|---|
| `modules/TheButton/TheButton.php` | `#[RequireLogin]` | **13** |

One file. Of 28 classes carrying a class-level gate attribute, this is the only one that also
checks by hand. The pattern is **not** widespread.

**Resolved, but not by deleting all 13.** Three of them were behaviour branches, and the
class-level attribute turned out to be the actual bug — see below.

### Ungated classes relying entirely on hand-rolled checks — the real risk

| File | Class-level gate | In-body checks |
|---|---|---|
| `modules/Notes/Notes.php` | **none** | 8 |
| `modules/Paste/Paste.php` | **none** | 8 |
| `modules/AccessRequest/AccessRequest.php` | **none** | 6 |
| `modules/Auth/Auth.php` | **none** | 3 |
| `modules/User/User.php` | method-level only | 2 |
| `modules/Ttrpg/submodule/Hexmap/Hexmap.php` | **none** | 2 |
| `modules/S/S.php` | **none** | 2 |
| `modules/Thingiverse/Thingiverse.php` | **none** | 1 |
| `modules/Ttrpg/Ttrpg.php` | **none** | 1 |

**9 files, 33 checks.** Here the hand-rolled check *is* the entire authentication boundary — there
is no attribute behind it. Get one wrong and the endpoint is open.

That is not hypothetical. `modules/Notes/Notes.php` had a **live authentication bypass** on
2026-08-16, introduced by migrating its eight `isset($_SESSION['user_id'])` gates to
`User::currentId() !== null`. `currentId()` falls back to `$_SESSION['user']['id']`, which
`modules/Auth/Auth.php:155` writes *before* `enforceVerifiedEmail()` at `:164` can throw — so an
ordinary OAuth attempt with an unverified provider email left a session that passed all eight
gates with a provider-controlled id. Fixed by gating on `isLoggedIn()` instead (`ff39479`), and
`currentId()` was separately hardened to reject non-positive and non-integer values (`6f4d5f9`).

**`modules/Paste/Paste.php` was the same shape** — no attribute, 8 hand-rolled checks — and was
examined next for exactly that reason. **No bypass.** All four of its gates (`edit`, `update`,
`delete`, `mine`) were correct, just written by hand; see "Paste, examined in full" below.

## What was done

### Correct — behaviour branches, left alone

| File | Calls | Why it is not a gate |
|---|---|---|
| `app/frontend/frame/header.php`, `footer.php` | 5 | Nav differs for signed-in visitors |
| `modules/Test/view/settings.php` | 3 | View branch |
| `modules/Ttrpg/view/index.php`, `submodule/Hexmap/frame/header.php` | 2 | View branch |
| `modules/S/S.php` | 2 | `index()` renders a landing page for anonymous; `__call()` logs a click with a user id or `null` |
| `modules/Ttrpg/Ttrpg.php` | 1 | `index()` adds recent activity when signed in |
| `modules/Ttrpg/submodule/Hexmap/Hexmap.php` | 2 | `index()`/`maps()` return an empty list for anonymous |
| `modules/Notes/Notes.php` | 3 | `index()`/`edit()`/`view()` are public — the editors work signed-out, notes just are not saved |
| `modules/TheButton/TheButton.php` | 3 | `status()` polling, `handleView()`, `handlePress()` — anonymous guests are a supported case |

`modules/S/S.php` is the reference implementation: method-level `#[RequireLogin]` on all eight
private endpoints, `isLoggedIn()` only in the two places where the response genuinely differs.

### Fixed — gates written by hand

| File | Endpoints | Was | Now |
|---|---|---|---|
| `modules/Notes/Notes.php` | `listNotes`, `load`, `save`, `delete`, `updatePermissions` | `if (!isLoggedIn()) { $this->error(…); return; }` | `#[RequireLogin]` |
| `modules/TheButton/TheButton.php` | `index`, `create`, `edit` | `if (!isLoggedIn()) { header('Location: /user/login'); exit; }` — no `return_url` | `#[RequireLogin]` |
| `modules/TheButton/TheButton.php` | `delete`, `archive`, `hide`, `invite`, `addGuest`, `removeInvitee`, `users` | `if (!isLoggedIn()) { $this->export(['error' => 'Not authenticated']); return; }` | `#[RequireLogin]` |

`modules/Notes/view/index.php` and `view/editor.php` branched on `isset($_SESSION['user_id'])`
while the controller used `isLoggedIn()` — the same split predicate, one layer up. Both now call
`isLoggedIn()`.

### Fixed — a class-level gate that was too wide

`modules/TheButton` carried a **class-level** `#[RequireLogin]`, which also covered `__call()`.
`__call()` is what serves `/the-button/{name}`, and that route is deliberately anonymous-capable:
`allow_anonymous` buttons, and the guest invite links documented in `modules/TheButton/CLAUDE.md`.

Measured before the fix:

```
$ curl -o /dev/null -w "%{http_code} -> %{redirect_url}" https://unisolu.com/the-button/test
302 -> https://unisolu.com/user/login?r=y
```

Both features were dead, and had been for as long as the attribute was there. The 13 in-body
`isLoggedIn()` calls existed because the author had written the module for per-endpoint gating;
the class-level attribute was added over the top of them.

Now: the class-level attribute is gone, ten endpoints carry `#[RequireLogin]` individually, and
`status()` and `__call()` are public with `handleView()`/`handlePress()` doing the real
authorisation (owner, invitee, tracking hash, or nothing).

**The general rule this produced:** attributes cannot be attached to a `__call`-dispatched
endpoint. `Application::checkForAttributes()` resolves against the URL endpoint name, and a
`__call` endpoint has no `ReflectionMethod` of its own, so only class-level attributes apply and
nothing can override them per URL. **A class whose `__call()` serves anonymous traffic must not
carry a class-level `RequireLogin`.**

### Paste, examined in full

The file this audit called highest-risk, on the grounds that it matched `Notes.php` exactly: no
attribute, 8 hand-rolled checks. **No bypass.** All four gates — `edit`, `update`, `delete`,
`mine` — were correct; they were simply written by hand. They are now `#[RequireLogin]`, and
every `$_SESSION['user_id']` read went to `User::currentId()` / `User::isLoggedIn()`. The only
`$_SESSION` left in the file is `paste_verified`, which is per-paste password state, not identity.

Three unrelated bugs surfaced while reading it, all fixed:

**`create()`'s error path rendered the signed-out form to a signed-in user.** `index.php` reads
`$this->isLoggedIn`; `index()` and `fork()` set it, but `create()` set only a *local* `$isLoggedIn`
and then re-rendered `index.php` on failure. Fixed by hoisting the assignment into the
constructor, which is also where it belonged — one line for the class instead of one per endpoint.

**`update()` and `delete()` rendered blank pages on every rejection.** Both used
`$this->error(…); return;`, and `Response::error()` does nothing at all unless the request carried
`Accept: application/json` — but `edit.php` and `show.php` post plain HTML forms. Wrong id, absent
paste, wrong owner, immutable paste: all eight paths returned an empty 200. Now `HTTPError`.

**Error pages loaded the wrong module's assets.** Ten call sites used `new \Zero\Core\Error(…)`,
which `CLAUDE.md` already deprecates in favour of `HTTPError`. The concrete cost:
`Module::__construct()` derives asset paths from `get_class($this)`, so an `Error` instance
computed `substr('Zero\Core\Error', strlen('Zero\Module\'))` → `'rror'`, and Paste's own CSS/JS
never loaded on its own error pages. Verified after the conversion:

```
$ curl -s https://unisolu.com/paste/nope-not-here | grep -o 'assets/paste/[^"]*'
assets/paste/css/paste.css
assets/paste/js/paste.js
```

Also worth recording, because it is the next attribute worth building: `edit`/`update`/`delete`
each repeat the same resolve → compare `user_id` → reject preamble, and `modules/TheButton` has
six more of the same shape. That is nine endpoints across two modules waiting on
`#[RequireOwnership]` (see `TODO.md`), which is a much better fit than anything in
`TODO_BRANCH_ON_LOGIN.md`.

## What to do next

Roughly in order of value.

1. ~~Do not blanket-delete the 13 checks in TheButton.~~ **Superseded by the ruling, then done** —
   ten became `#[RequireLogin]`, three were behaviour branches and stayed.
2. ~~**Prefer the attribute over hand-rolled checks.**~~ **Done for every `isLoggedIn()` call
   site.** Watch for endpoints that are *deliberately* public with degraded behaviour — a
   class-level attribute would make those unreachable for anonymous visitors. Method-level
   attributes override class-level ones of the same type (`core/Application.php:243-252`), so
   mixed cases are expressible — but only for endpoints that have a real method.
3. ~~**Audit `modules/Paste/Paste.php` specifically**, as the closest match to the module that
   already had a bypass.~~ **Done** — no bypass; see "Paste, examined in full" above.
   `AccessRequest` (6 checks), `Auth` (3) and `Thingiverse` (1) are what remain.
4. ~~**Decide what `#[RequireLogin]` should mean** and write it down.~~ **Done** — it means
   `User::isLoggedIn()`: a fully established session for a verified account. Written down in
   `docs/attributes/RequireLogin.md` and `docs/ATTRIBUTES.md`.
5. **Make `AllowWithToken` refuse an unverified owner.** No longer a bypass — the gate catches it
   — but establishing a session it will not honour is still wrong.
6. **Resolve the TODO in `RequireLogin::handler()`**: whether `verified` should be configurable
   per endpoint. Nothing needs it yet.

## The third gate: `RequireAuthLevel`

This document unified `#[RequireLogin]` and `User::isLoggedIn()`, and `RequirePermission` is
already covered because `RequirePermission::handler():46` opens by delegating to
`(new RequireLogin())->handler()` — one predicate, three attributes.

**`RequireAuthLevel` is the exception, and it is now shelved** (2026-08-16). It does not
delegate: it checks `session_status()` then `User::current()?->authLevel()`, which reads
`user_settings['auth.level']` and never touches `users.verified`. So it is the one gate an
unverified session can still pass.

Latent, not live. Only `modules/Sitemap` and `modules/Test` gate on it alone; everywhere else it
sits beside `#[RequirePermission]`, which enforces the real check. No module pairs it with
`#[AllowWithToken]`, which is the only producer of an unverified session.

Full reasoning, the usage inventory, and the condition for un-shelving it are in
`docs/attributes/RequireAuthLevel.md`. **Do not add new bare `#[RequireAuthLevel]` gates** — use
`#[RequirePermission]`.

## Reproducing the audit

```bash
# Class-level gated files that also check by hand
# and ungated module classes that rely entirely on hand checks.
/usr/bin/grep -rn "isLoggedIn()\|isset(\$_SESSION\['user_id'\])" --include=*.php modules/ | grep -v /tests/
/usr/bin/grep -rln "#\[RequireLogin\]" --include=*.php modules/ | grep -v /tests/
```

Use `/usr/bin/grep`, not the shell's `grep` — the latter is a shim that honours `.gitignore` and
will quietly under-report.

The gate's behaviour is pinned by:

```bash
./vendor/bin/phpunit core/tests/Attribute/RequireLoginTest.php --testdox
```

The two predicates could be compared directly (before the fix they disagreed; now the attribute
simply calls the second one):

```bash
php -r 'require "core/tests/bootstrap.php";
  $_SESSION = ["user_id"=>42, "email"=>"a@x.invalid", "verified"=>0];
  var_dump(isset($_SESSION["user_id"]));            // old RequireLogin: true
  var_dump(\Zero\Core\User::isLoggedIn());          // isLoggedIn:       false'
```
