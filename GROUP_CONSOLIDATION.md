# Group Consolidation — Plan

Groups are a framework concept: every module that shares something shares it with groups. But
the framework has no code for groups. The `group` and `group_member` tables and the only classes
for them (`Zero\Module\Admin\Model\Group` and `GroupMember`) belong to the Admin module, so every
other module reaches into Admin, or into the tables directly. This document is the plan to move
groups into core. It is a plan, not a description of how things work today.

Related: `GROUP_PERMISSIONS.md` (this folder) covers the per-entity permission tables and
`GroupPermission`; this document covers the groups those tables point at.

## What is wrong today

### Modules call another module's model

`docs/ZERO.md` says a module uses another module's `lib/`, never its `model/`. Groups break that
rule everywhere, because there is nothing else to call:

| Caller | How it gets groups |
|---|---|
| Lists (`Lists.php`, `lib/ListService.php`) | `new Zero\Module\Admin\Model\Group($db)` → `getUserGroups()` |
| Notebook (`Notebook.php`) | the same |
| Kanban (`Kanban.php`) | the same |
| Notes (`Notes.php`) | the same |

`ListService` is the clearest case: it is the shared `lib/` class other modules are told to use,
and it depends on Admin's model to decide whose groups a List's sharing may name.

### Core depends on a module's tables

`core/GroupPermission.php` joins `group_member` and `group` in `hasPermission()`,
`getByEntity()`, `find()`, `getAvailableGroups()` and `getSharedUsers()`. Core cannot run without
tables that only the Admin module creates and documents.

### The same queries are written over and over

Raw SQL against `group` / `group_member` outside Admin: `core/GroupPermission.php`,
`Lists/model/ListModel.php`, `Lists/model/ListPermission.php`, `Notebook/model/NotebookModel.php`
(five joins), `Kanban/model/BoardModel.php`, `Kanban/model/KanbanDependency.php`,
`Notes/model/NoteModel.php`, and `Ttrpg/TtrpgModel.php`, which also *creates* groups and adds
members with its own `INSERT`s (a campaign gets a group).

### They disagree about inactive groups

`group.is_active` exists, and `Group::getUserGroups()` and the `getAvailableGroups()` queries
leave inactive groups out, so a deactivated group disappears from every sharing form. But
`GroupPermission::hasPermission()` and `ListModel::hasPermission()` join `group_member` alone, so
its members keep every permission it was granted, and nobody can see or revoke those grants in
the forms. One rule, in one place, would fix this.

### Admin can't be removed or replaced

Disabling the Admin module would break sharing in every module.

## The plan

### 1. `Zero\Core\Group` owns the group tables

A core class, `core/Group.php`, is the only code that reads or writes `group` and `group_member`.
What it offers is what the callers above need today:

- **Membership:** `forUser(int $userId): array` (the user's active groups, with role),
  `idsForUser(int $userId): int[]`, `isMember(int $groupId, int $userId): bool`,
  `members(int $groupId): array`.
- **Groups:** `find(int $groupId): ?array`, `all(bool $activeOnly)`, `create()`, `update()`,
  `deactivate()`.
- **Members:** `add()`, `remove()`, `setRole()`.

It takes a `\PDO` like every model, and holds the one definition of an active membership (see 3).

### 2. Admin keeps the screens, not the data

Admin's group pages call `Zero\Core\Group`. `Admin/model/Group.php` and `GroupMember.php` become
thin wrappers or go away; their methods map one-to-one onto the core class
(`getUserGroups()` → `forUser()`, `isMember()` → `isMember()`, …).

### 3. One rule for who is in a group

Decide once whether an inactive group still grants access. The recommendation is **no**: a
deactivated group grants nothing and shows nowhere. Core then exposes the rule as a SQL fragment
(or an `idsForUser()` list) that `GroupPermission` and every module's permission query use, so
the forms and the checks can't disagree again.

### 4. Callers move over

- `GroupPermission` uses `Zero\Core\Group` for membership instead of joining `group_member`.
- Lists, Notebook, Kanban and Notes replace `new Zero\Module\Admin\Model\Group($db)` with the
  core class; `ListService::forUser()` builds the core class too.
- The module models' own `group_member` joins go through the core rule (step 3). Where a query
  needs a join for speed, it uses the fragment core provides rather than writing its own.
- Ttrpg creates its campaign groups and members through the core class.
- `docs/ZERO.md` drops the "groups are the exception" note from its `lib/` section.

### 5. No schema change

The tables stay as they are. This is about which code owns them. No new foreign keys.

## Order of work

1. Write `core/Group.php` with PHPUnit tests (PDO stubs, as the module model tests do), and the
   decision from step 3.
2. Point Admin at it; Admin's own tests and pages must behave as before.
3. Move `GroupPermission`, then each module, one commit each, checking each module's sharing
   path with a real group and member (the shared-access fixture in `GROUP_PERMISSIONS.md` #2:
   tests that skip when no group exists hid a regression before).
4. Delete what is left of Admin's group models.

## Out of scope

- Merging the per-entity permission tables (`list_permissions`, `notebook_permissions`,
  `note_permissions`, `kanban_permissions`) into one is a separate question;
  `GROUP_PERMISSIONS.md` covers standardizing them.
- Per-user (not per-group) sharing.
