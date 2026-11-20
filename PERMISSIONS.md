# Sessions, Users, Groups and Permissions

These belong to core. A module never keeps a group or permission table of its own, never writes
SQL against core's, and never depends on the Admin module for them.

| Class | What it is | Tables | Schema |
|---|---|---|---|
| `Zero\Core\Session` | Who is making this request | none (`$_SESSION`) | |
| `Zero\Core\Access\User` | An account | `users`, `user_profile`, `user_settings` | `core/sql/core.sql` (design notes: `core/sql/migrations/users.sql`, `user_profile.sql`) |
| `Zero\Core\Access\Group` | A group of users | `user_group`, `user_group_member` | `core/sql/core.sql` (design notes: `core/sql/migrations/group.sql`) |
| `Zero\Core\Access\Permission` | Who may do what | `permission` | `core/sql/core.sql` (design notes: `core/sql/migrations/permission.sql`) |

`User` and `Group` are both `Zero\Core\Model` row objects, and both are a `Zero\Core\Access\Holder`:
something a permission can be granted to. The Admin module's screens edit users and groups
through these classes. A site without the Admin module loses those screens and nothing else.

## Session

Every method is static and answers for the current request.

```php
use Zero\Core\Session;

Session::userId();              // ?int, without building an object
Session::user();                // ?User
Session::isLoggedIn();          // logged in and verified
Session::can('allow.polly');    // does the logged-in user have the capability?

Session::establish($user);      // the single writer of the login session
Session::end();                 // the single place it is torn down
```

`User` knows nothing about the session: every `User` method answers for the account the object
was loaded from. See `core/modules/Auth/CLAUDE.md` for what `establish()` puts in `$_SESSION`.

## Two kinds of permission

Both are rows in the `permission` table.

**Capabilities**: "may this user use this feature at all?" A named key such as `allow.polly`,
gated with `#[RequirePermission('allow.polly')]` and read with `$user->can()`. See
[Capabilities](#capabilities) below and `core/docs/attributes/RequirePermission.md`.

**Sharing**: "may this user read, update or delete *this item*?" A key of `{module}.{entity}`
and an item id, read and written through `Zero\Core\Access\Permission`.

A user's settings (`auth.level`, a module's per-user preference) are values, not permissions.
They live in `user_settings` and are read with `$user->settings()`.

## The `permission` table

One row grants one holder rights under one key.

| Column | Meaning |
|---|---|
| `permission_key` | `{module}.{entity}` for sharing (`lists.list`, `kanban.board`); the capability's name for a capability (`allow.polly`) |
| `entity_id` | The item. `0` means every item under the key, and is where a capability is held. |
| `user_id` | The user granted, or `0` when the row is a group's |
| `group_id` | The group granted, or `0` when the row is a user's |
| `can_read`, `can_update`, `can_delete` | What the holder may do |
| `granted_by`, `granted_on` | Who made the grant and when |
| `updated_by`, `updated_on` | Who last changed its flags and when; `NULL` if never |

- The primary key is `(permission_key, entity_id, user_id, group_id)`.
- Unused key columns hold `0`, never `NULL`. A `CHECK` constraint requires exactly one of
  `user_id` and `group_id` to be non-zero.
- Rows only grant. There is no deny row, and no row with all three flags `0`: revoking deletes
  the row.
- There are no foreign keys. Nothing cascades in the database; the classes clean up (see
  [Deleting](#deleting)).

### Keys

A module's key is its name as it appears in its URL (lowercase, words joined by hyphens), a dot,
and the entity: `lists.list`, `tucker-tapper.score`. `Permission::key()` builds and validates it,
so all of one module's rows are `permission_key LIKE 'lists.%'`. `allow` is refused as a module
name.

Keys in use:

| Key | Item | Module |
|---|---|---|
| `lists.list` | `lists.list_id` | Lists |
| `notebook.notebook` | `notebooks.id` | Notebook |
| `notes.note` | `notes.note_id` | Notes |
| `kanban.board` | `kanban_boards.board_id` | Kanban |
| `comfy.generation` | `comfy_generation.id` | Comfy |

## Holders

A `Holder` is what a permission is granted to. `User` and `Group` are the two holders. Both have
the same capability methods, and `Permission` takes either wherever it grants or revokes.

```php
$user  = User::find($userId);
$group = Group::find($groupId);

$user->grant('allow.polly');     $group->grant('allow.polly');
$perm->grant($listId, $user);    $perm->grant($listId, $group);
```

## Capabilities

A capability is a row on `entity_id` 0 with `can_read` set, under the capability's own key. A
group has the capabilities granted to it. A user has their own and those of every active group
they belong to.

```php
$user->can('allow.polly');               // does this user have it?
Session::can('allow.polly');             // does the logged-in user?
$user->capabilities();                   // every key the user has

$user->grant('allow.polly', $adminId);   // $adminId is recorded as granted_by
$user->revoke('allow.polly');

$group->grant('allow.polly');            // every active member has it
$group->revoke('allow.polly');
$group->can('allow.polly');              // was it granted to the group itself?
```

- A key is `allow.` and then lowercase words joined by dots: `allow.polly`,
  `allow.receipt.view`. No module's sharing key can start with `allow.`, and `grant()` refuses
  a key that does not.
- `can()` reads the database once per object, so a grant or revocation takes effect on the
  user's next request. A failed lookup denies.
- `revoke()` removes the holder's own row. A capability a user has through a group stays until
  the group loses it.
- The Admin module's user screen lists every key named in a `#[RequirePermission]` attribute
  in the modules' source, plus every key somebody has, and grants or revokes with a tick box.
- A user asking for a capability they lack goes through the AccessRequest module, which calls
  `grant()` when an admin approves.

## Who holds a sharing permission

A user holds a permission on an item when any of these rows grants it:

- the user's own row for the item,
- the row of an **active** group the user belongs to,
- either of those on `entity_id` 0.

A deactivated group grants nothing and is not listed in sharing forms. `Group::activeIdsSql()` is
the one definition of "the groups this user is in"; `Permission` uses it for every check.

**A user shares only with a group they belong to.** `grant()` to a group throws
`\DomainException` unless the granter (`$by`, or the logged-in user) is a member of the group
while it is active, or has `allow.admin`. `$group->usableBy($user)` is that test; a form checks
it first and answers 403. With nobody granting (no `$by` and no session) the caller is the
system itself, such as a migration or a script, and the grant is made. Granting to a user and
revoking are not restricted.

**Ownership is the module's business.** `Permission` never reads a module's tables. The owner of
an item needs no row; the module checks "is the owner, or `can()`".

## Sharing in a module

```php
use Zero\Core\Access\Group;
use Zero\Core\Access\Permission;

$perm = new Permission('lists', 'list');

// One item
$allowed = $list['user_id'] == $userId || $perm->can($listId, $userId, 'update');

// A listing: every list the user owns or can read
[$canRead, $params] = $perm->sqlCan('l.list_id', $userId, 'read');
$stmt = $db->prepare("SELECT l.* FROM lists l WHERE l.user_id = ? OR $canRead");
$stmt->execute([$userId, ...$params]);

// Sharing with a group chosen in a form
$group = Group::find($groupId) ?? throw new HTTPError(404, 'Group not found');
if (!$group->usableBy($userId)) {
    throw new HTTPError(403, 'You can only share with a group you belong to');
}
$perm->grant($listId, $group, true, true, false);    // read + update
$perm->grant($listId, $otherUser);                   // one user: read
$perm->revoke($listId, $group);

// Deleting the item
$perm->forget($listId);
```

| Method | Answers |
|---|---|
| `can($id, $user, $action)` | Does the user hold `read` / `update` / `delete` on the item? |
| `sqlCan($idColumn, $user, $action)` | `[sql, params]`: an `EXISTS (...)` condition for the module's own query |
| `ids($user, $action)` | Ids of the items granted one by one (not owned ones, not "every item") |
| `canAll($user, $action)` | Does the user hold it on every item (a row on entity 0)? |
| `grant($id, $holder, $read, $update, $delete, $by)` | Insert or replace a holder's rights |
| `batchGrant($id, [groupId => flags])` | The same for several groups from a form |
| `revoke($id, $holder)` | Remove a holder's row |
| `forget($id)` | Remove every grant on an item |
| `groups($id)` | The active groups granted, with flags, name and member count |
| `find($id, $group)` | One group's grant |
| `availableGroups($id, $user)` | The user's active groups with no grant on the item yet |
| `users($id)` | Everyone with rights: members of granted groups, and users granted directly |

Things to know:

- Wherever a user is expected, an `int` id or a `User` works. A holder is always an object.
- `sqlCan()` uses positional `?` placeholders, so the query it goes into must too. Splice its
  parameters in at the fragment's position.
- Granting again replaces the flags. Granting with all three flags false removes the row.
- A module that deletes an item must call `forget()`.
- `can()` and the listing methods return `false` / `[]` on a database error and log it; the
  writing methods throw.
- A module's own `.sql` does not create a permission table. `permission` comes from core.

## Groups

`Group` is a row object for `user_group`, with the group's members as methods.

```php
use Zero\Core\Access\Group;

Group::forUser($userId);          // the user's active groups; each has ->role, ->member_count
$user->groups();                  // the same
$user->groups(false);             // inactive ones too, for a screen that manages groups
Group::idsForUser($userId);
Group::all();                     // every group; each has ->member_count, ->created_by_username

$group = Group::create(['group_name' => 'Campaign 12']);   // created_by: the logged-in user
$group = Group::find($groupId);                             // ?Group
$group->group_name = 'Campaign 13';
$group->save();

$group->add($userId, 'owner');
$group->setRole($userId, 'admin');
$group->has($userId);             // a member, and the group is active
$group->usableBy($userId);        // may share with it: has(), or an admin
$group->role($userId);            // ?string
$group->members();                // membership rows with username and email
$group->remove($userId);

$group->deactivate();             // stops granting; rows stay
$group->activate();
$group->delete();                 // gone, with its members and every grant made to it
```

- `find()`, `all()` and `members()` include inactive groups so the Admin screens can show and
  reactivate them. `forUser()`, `idsForUser()` and `has()` leave them out.
- `add()`, `remove()` and `setRole()` throw `\DomainException` with a message fit to show when
  the user already is, or is not, a member. A duplicate `group_name` is a `\PDOException` that
  `Model::isDuplicateKey()` recognises.

## Deleting

No foreign key removes anything. The objects do:

- `$user->delete()` removes the user's group memberships, the permissions granted to them, their
  settings and their profile. What modules keep about a user is the module's to remove.
- `$group->delete()` removes its memberships and every permission granted to it.
- A module deleting an item calls `$perm->forget($id)`.

The caller owns the transaction.

## Testing a module's sharing

Test the shared path with a real group, a real member and a real grant, and never let that test
skip because the environment has no group: a sharing test that skipped once let a regression
ship that locked every non-owner out of Notebook. `core/tests/PermissionTest.php` shows the
fixture: create a group and member through `Group`, grant through `Permission`, assert, and
delete everything the test created in both `setUp()` and `tearDown()`.

## Outside the framework

`swoole/ws-server.php` runs as its own process with its own database connections and no
autoloader. It loads the core class files it needs and passes its own PDO:
`new Permission('notebook', 'notebook', $pdo)` and `Permission::capability('allow.admin', $pdo)`.
It has no copy of any rule. It holds the classes it loaded until it is restarted.
