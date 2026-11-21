# User improvements (set aside)

Two changes to how a module gets the current user were scoped in November 2026 and set
aside. Neither is started. This document records what they are, what they touch and why
they wait.

1. **`USER_CLASS`**: a site supplies its own subclass of `User`.
2. **`$this->user`**: the base `Module` gives each module the current user as a property.

`USER_CLASS` is small and stands alone. `$this->user` has obstacles and little benefit
today. If only one is done, do `USER_CLASS`.

## The classes today

- `Zero\Core\Session` answers "who makes this request?". All its methods are static.
- `Zero\Core\Access\User` is a row of `users` (`Zero\Core\Model`). It holds capabilities
  through `HoldsCapabilities`.
- `Session::user()` builds the current user from the session and keeps it for the request.
  It returns null when nobody is logged in.

## 1. `USER_CLASS`

### What it is

A constant that names the class of a user. Core supplies the default,
`Zero\Core\Access\User`. A site sets its own in `app/config/constants.ini`:

```ini
USER_CLASS = "Zero\App\Lib\Member"
```

The site's class extends `Zero\Core\Access\User`.

### Where core reads it

| File | Today | With `USER_CLASS` |
|---|---|---|
| `core/Session.php`, `user()` | `new User([...], true)` | `new (USER_CLASS)([...], true)` |
| `core/access/Group.php`, `usableBy()` | `User::find($user)` | `(USER_CLASS)::find($user)` |
| `core/attribute/AllowWithToken.php` | `User::find($id)` | `(USER_CLASS)::find($id)` |

`Session::user()` is the one place that creates the current user. The other two fetch a
user by id.

No module changes. `Model::find()`, `findBy()`, `allBy()` and `create()`, and
`User::findByEmail()`, return `static`, so a call on the site's class returns the site's
class.

### What it needs

- A default for the constant when a site does not set it.
- A check, at the point of use, that the named class exists and extends
  `Zero\Core\Access\User`. A wrong value must fail with a clear message, not with a fatal
  error on the first request.
- A test with a subclass: `Session::user()` returns it, and `find()` on it returns it.

### What it does not do

Module code that calls `User::find()` or `User::create()` by name gets core's class, not
the site's. A site that extends the user and needs its own type from those calls names
its own class there.

## 2. `$this->user`

### What it is

A property on the base `Module`. It gives the object that `Session::user()` returns, or
null when nobody is logged in.

It must be lazy: the property reads `Session::user()` when a module asks for it.
`Application` must not set it before the module runs, because Auth establishes the
session in the middle of a request and a value set earlier would be stale.

### What a module gets from it

| On `$this->user` | From |
|---|---|
| `->id`, `->name`, `->email`, `->verified`, `->pic` | the row |
| `->can($key)`, `->capabilities()`, `->grant()`, `->revoke()` | `HoldsCapabilities` |
| `->groups()`, `->mentionableUsers()` | `User` |
| `->settings()`, `->setSetting()`, `->authLevel()` | `User` |
| `->profile()`, `->storeProfile()`, `->forgetProfile()` | `User` |
| `->save()`, `->delete()`, `->toArray()` | `Model` |

### What stays static

| Call | Why |
|---|---|
| `User::find()`, `findBy()`, `allBy()`, `findByEmail()` | They fetch a different user |
| `User::create()` | The user does not exist yet |
| `User::normalizeEmail()` | A helper; it needs no user |
| `Session::isLoggedIn()`, `userId()`, `user()`, `can()` | Attributes, models and scripts have no module |
| `Session::establish()`, `end()` | They start and stop the login |

Module code has 29 static `User::find` / `User::create` calls. Those files name the class
with or without `$this->user`.

### Why it waits

- **Three modules use the name already, with three meanings.**
  - `modules/Slots/Slots.php` sets `$this->user` to the `User` object.
  - `core/modules/User/User.php` sets `$this->user` to the `$_SESSION['user']` array, and
    `view/profile.php` reads it as an array.
  - `core/modules/Auth/Auth.php` has a private `$user` that holds the identity a provider
    returned.
  Each must be renamed or made to agree before the base class can own the name.
- **It saves little.** `$this->user` and `Session::user()` are the same object.
- **It makes two ways to do one thing.** Models, attributes and scripts keep `Session`, so
  a reader must know both.
- **It does not reduce renames.** The files that name `User` are the ones with the static
  calls above.

### What was considered and rejected

Putting `Session`, `Group` and `Permission` inside the user
(`$this->user->session`, `$this->user->group`, `$this->user->permission`):

- **`Session` inside `User` is the wrong way round.** The session decides who the user is,
  and a session exists when nobody is logged in. `$this->user->session` on such a request
  is a call on null, unless a guest user object is added, which changes every
  `Session::isLoggedIn()` check.
- **`Permission` is not a part of a user.** It is built from a module and an item type,
  and the user is an argument: `$perm->can($itemId, $user)`.
- **`Group` as a whole is not a part of a user.** `$user->groups()` gives a user's groups
  already. `Group::find()`, `Group::all()` and `Group::create()` are about groups, not
  about one user.

## When to take this up again

- **`USER_CLASS`**: when a site needs its own user class. It is about four core files and
  a test.
- **`$this->user`**: when the core `User` module is reworked, because its array-shaped
  `$this->user` is the main obstacle. Do `USER_CLASS` first, so the property gives the
  site's class.
