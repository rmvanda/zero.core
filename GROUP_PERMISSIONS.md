# Group Permissions — Standardization Handoff

## Context for the next session

This document is a **state-of-the-feature snapshot** plus a punch list for standardizing group-based permissions across Zero modules. The feature exists and is partially adopted, but conventions drifted over time — every module using it has something slightly different, and we just shipped (and reverted-via-fix) a production regression that would have been caught by proper standardization.

Use this as a working plan. Don't treat it as finished documentation.

## The class

`core/GroupPermission.php` — reusable group-permission handler. Takes an entity table name, a permission table name, column names, and exposes:

- `grant / revoke / update` — per-(entity, group) mutations
- `hasPermission(entityId, userId, 'read'|'update'|'delete')` — owner check + group-membership check
- `getByEntity / find / getAvailableGroups / getSharedUsers` — queries
- `batchGrant` — bulk grant helper

As of 2026-04-22 the constructor signature is:

```php
new GroupPermission(
    \PDO $db,
    string $entityTable,          // e.g. 'notes', 'kanban_boards'
    string $entityIdColumn,       // FK column in the permission table (e.g. 'note_id')
    string $permissionTable,      // e.g. 'note_permissions'
    string $entityName = 'item',  // human-readable, for error messages
    ?string $entityPkColumn = null // Entity-table PK; defaults to $entityIdColumn
);
```

The 6th arg was added 2026-04-22 because `notebooks.id` (entity PK) differs from `notebook_permissions.notebook_id` (perm-table FK). See `core` commit `b5ac4dd` for the fix, `modules` commit `9146e44` for the notebook call-site update.

## Schema contract the class expects

For a module to adopt `GroupPermission`, it needs:

### Entity table
- Some PK column (name is free-form; pass as `$entityPkColumn`)
- A `user_id` column identifying the owner (hard-coded in the class — owner has all permissions)

### Permission table
- A FK column referencing the entity (name is free-form; pass as `$entityIdColumn`)
- A `group_id` column referencing `group.group_id`
- Three boolean columns: `can_read`, `can_update`, `can_delete` (`tinyint(1)` in all current adopters)
- An `ON DUPLICATE KEY` target — either a composite unique index on `(entityIdColumn, group_id)` or a unique constraint the `grant()` INSERT can collide with

### `group_member` table (global)
- Has `group_id` and `user_id`. Used in the membership join in `hasPermission`. Owned by the Admin module.

## Current adopters (audited 2026-04-22)

| Module | Entity table.PK | Perm table.FK | Match | Instantiation |
|---|---|---|---|---|
| Notes | `notes.note_id` | `note_permissions.note_id` | ✅ | 5 args (implicit PK = FK) |
| Kanban | `kanban_boards.board_id` | `kanban_permissions.board_id` | ✅ | 5 args |
| Notebook | `notebooks.id` | `notebook_permissions.notebook_id` | ❌ | 6 args (explicit PK override) |
| **Lists** | `lists.list_id` | `list_permissions.list_id` | ✅ | **does not use `GroupPermission`** — has its own `ListPermission` class at `modules/Lists/model/ListPermission.php` (85 lines, verbatim-pattern duplicate) |

### Schema inconsistencies worth noting

Permission tables are almost uniform but not quite:

| Table | Has `permission_id` auto-increment PK? | Has `updated_at`? |
|---|---|---|
| `note_permissions` | yes | no |
| `list_permissions` | yes | no |
| `notebook_permissions` | yes | no |
| `kanban_permissions` | **no — composite PK on (`board_id, group_id`)** | **yes** |

`kanban_permissions` is the odd one out. If we want full uniformity, it should be migrated to match the others (or everyone else migrated to match it — composite PK is arguably cleaner, since it enforces the uniqueness constraint that `ON DUPLICATE KEY UPDATE` relies on). Either direction is fine; the decision is a standardization call, not a technical one.

The **notebooks entity table** is the only one that doesn't end its PK column with `_id` (it's just `id`). That's the root cause of the `$entityPkColumn` split. Either the schema gets migrated to rename it `notebook_id`, or the 6-arg form stays — it's a judgment call.

## What broke (brief)

Wave 2 (April 2026) swapped Notebook's hand-rolled `NotebookPermission` class for `GroupPermission`. The swap instantiated with `'id'` as both the perm-table FK and the entity-table PK. Because Notebook is the only module where those differ, every non-owner (shared) user suddenly got 403. Owners were unaffected. The sharing tests in the API suite silently skipped (no group in the test env), so CI went green.

**Post-mortem takeaways the next context should internalize:**

1. Before refactoring duplicated permission classes, diff the **schemas** — not just the code.
2. "All tests pass" is meaningless if the critical tests auto-skip. A module using `GroupPermission` needs tests that actually exercise the shared-access path with a real group.
3. The `#[RequirePermission]` attribute work in progress at `core/attribute/RequirePermission.php` (uncommitted at time of writing) is likely the intended long-term direction — declarative permission checks on endpoints. Coordinate with that effort.

## Standardization punch list

Ordered roughly from highest to lowest leverage. Each item is independent unless noted.

### 1. Delete `ListPermission`, switch Lists to `GroupPermission`

`modules/Lists/model/ListPermission.php` is 85 lines that reimplement what `GroupPermission` already parameterizes. `lists.list_id` matches `list_permissions.list_id`, so it's a same-name case — 5-arg instantiation, identical semantics. Follows the pattern Notes and Kanban already use.

**Blockers:** None technical. Verify the call sites in `modules/Lists/Lists.php` and `modules/Lists/model/ListModel.php` all map cleanly (method names like `getByList` vs `getByEntity`, same as the Notebook swap).

**Tests to add first:** A sharing fixture in the Lists test suite that seeds a group + member and asserts the shared path actually works. Without that the swap is just as risky as the Notebook one was.

### 2. Add a reusable test fixture for shared access

The single biggest reason the Notebook regression shipped: sharing tests auto-skip when no group exists in the test environment. Make it trivial for any module's API tests to create a throwaway group + member + permission row for one test, then clean up.

Suggested shape (Playwright, TypeScript — lives in `modules/{Module}/tests/tests/fixtures/` or a shared location):

```ts
// tests/fixtures/sharing.ts (sketch)
export async function createSharedFixture(api, { entityType, entityId, perm }) {
  // 1. Create a group via an admin endpoint (or raw SQL through a test helper)
  // 2. Add the test user as a member
  // 3. Grant `perm` on `entityType` -> entityId via the module's share endpoint
  // Returns { groupId, teardown }
}
```

This is the most important item on the list because without it every future `GroupPermission` refactor has the same blind spot that bit us.

### 3. Decide + document the schema convention

Pick one and write it down in this file:

- **Option A (minimal change):** keep the mixed conventions; document that entity PKs can be named anything and the 6-arg form covers the mismatch case. Notebook's `id` column stays.
- **Option B (full uniformity):** migrate every entity table to `{module}_id` naming. Retire the `$entityPkColumn` parameter (or leave it as optional dead weight). Involves a Notebook schema migration: rename `notebooks.id` → `notebooks.notebook_id`, update every `SELECT ... FROM notebooks WHERE id = ?` in the module.

Option A is faster. Option B removes the last edge case that caused the regression. Either is defensible.

### 4. Standardize perm-table schema

Pick a canonical shape (probably: `permission_id` auto-increment PK, `(entity_id, group_id)` unique key, `can_read/can_update/can_delete`, `created_at`, `updated_at`) and migrate any outliers. Today the outlier is `kanban_permissions` (composite PK, has `updated_at`).

This isn't critical for correctness but makes the documentation much simpler and makes future queries across modules (e.g. "all entities shared with group X") predictable.

### 5. Consider: `GroupPermission` as an abstract base vs. helper

Today `GroupPermission` is a helper — modules hold an instance on the controller and delegate. That's fine. But a richer design would be:

- A `#[RequirePermission('read')]` endpoint attribute (in progress per the uncommitted `core/attribute/RequirePermission.php`)
- A declarative module-level config: "this module owns entity type X; shared permissions live in table Y." 
- The framework router uses that metadata to auto-check permissions before dispatching the endpoint.

This is a bigger design question. Don't start on it without first shipping items #1 and #2.

### 6. Document common pitfalls

Add to this file once the above is underway:

- "Don't pass `'id'` as the FK column unless your permission table literally has a column named `id`."
- "Remember `hasPermission` caches per instance lifetime — appropriate for per-request but not for long-lived processes."
- "`batchGrant` returns `{ success: [...], failed: [...] }` — failures don't throw."

## Files and locations

- `core/GroupPermission.php` — the class
- `core/attribute/RequirePermission.php` — related attribute work (was WIP uncommitted as of this writing — check its current state before planning)
- `modules/Notes/Notes.php:23` — reference adoption (same-name schema)
- `modules/Kanban/Kanban.php:30-32` — reference adoption (same-name schema)
- `modules/Notebook/Notebook.php:31` — adoption with PK/FK mismatch (6-arg form)
- `modules/Notebook/model/NotebookModel.php:16` — second instantiation in the same module (for the cache-wrapped `hasPermission` delegate; context in Wave 2 commits)
- `modules/Lists/model/ListPermission.php` — the remaining duplicate, candidate for deletion

## Relevant recent commits

- **core** `b5ac4dd` — adds `$entityPkColumn` sixth constructor arg
- **modules** `9146e44` — fixes Notebook instantiations to pass FK + PK separately
- **modules** `b7f0d76` (Wave 2 #1) — original swap from `NotebookPermission` to `GroupPermission` (the one that caused the regression)
- **modules** `25d13cf` (Wave 2 #2) — makes `NotebookModel::hasPermission` a cached wrapper over `GroupPermission::hasPermission`

## Quick verification query

To spot-check whether `hasPermission` works for a given (entity, user) pair without spinning up the app:

```bash
mysql unisolu -e "
SELECT '$ENTITY_ID is owned by $USER_ID?' AS q,
       (user_id = $USER_ID) AS yes
FROM $ENTITY_TABLE WHERE $PK_COL = $ENTITY_ID
UNION ALL
SELECT '$USER_ID has group access?',
       EXISTS(
         SELECT 1 FROM $PERM_TABLE p
         JOIN group_member gm ON p.group_id = gm.group_id
         WHERE p.$FK_COL = $ENTITY_ID AND gm.user_id = $USER_ID AND p.can_read = 1
       )
"
```

Substitute `$ENTITY_TABLE`, `$PK_COL`, `$PERM_TABLE`, `$FK_COL` for the module under test. Either `yes` column returning `1` means the user should be granted access.
