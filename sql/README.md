# core/sql

## core.sql

`core.sql` creates every table and view that core and its bundled modules read or write, as they
are defined on the live database. A new site loads it once, after creating an empty
database:

```bash
mysql <database> < core/sql/core.sql
```

It can be loaded again, or onto a database that already has some of the tables: tables use
`CREATE TABLE IF NOT EXISTS` (an existing table is left as it is), views use `CREATE OR REPLACE`,
and each seed row is inserted only when it is missing, with its id spelled out, so a repeat load
takes no auto-increment ids and never changes a row. It never drops, truncates or updates.
Tables come first and views after the tables they read.

| Owner | Tables and views |
|---|---|
| Core (`Zero\Core\Access\User`, `Group`, `Permission`) | `users`, `user_profile`, `user_settings`, `user_group`, `user_group_member`, `permission`, `user_view` |
| Auth | `auth_token`, `oauth_provider`, `oauth_token` |
| User | `api_tokens` |
| AccessRequest | `access_requests` |
| Push | `push_subscriptions`, `push_notifications`, `push_notification_clicks` |
| Entity framework | `entity_type`, `entity`, `event_view` |
| Admin (clears them on account delete; a site's Ws module writes them) | `ws_tokens` |

`user_group_member` carries the one foreign key core has, to `user_group`.

Rows `core.sql` inserts, because the table does not work without them:

- `oauth_provider`: google, github, discord, gitlab (ids 1 to 4 on an empty table). The login buttons read the registry.
- `entity_type`: the `event` type, found by label. It takes id 2 when that id is free, otherwise the next id. `Zero\Entity\Event` looks the type up by label.
- `entity`: the four schema rows (`id = 0`) naming the event type's attributes in order: `datetime`, `event`, `detail`, `ip`, under whatever id the `event` label has.

The `entity` table is partitioned by type. It is created with the single partition `e_type2`. When the
`event` type has another id (id 2 was already another type on a database that had some tables), the load
adds that id's partition. The entity framework adds a partition for each further type a site defines.
`event_view` is built from the id the `event` label has (a prepared statement, as the partition is), so it reads
the event type's rows on such a database too.

`core/tests/CoreSqlTest.php` keeps the file honest: it compares the tables the PHP names with the
tables the file creates, loads the file twice into a throwaway database, and compares each table
with the live definition.

## migrations/

Scripts from before `core.sql`. A new site runs none of them: every table or view definition among
them is already in `core.sql`. A site whose database predates `core.sql` runs the ones it needs, by
hand, in this order. A module's file is prefixed with the lowercase module name.

| # | File | What it is |
|---|---|---|
| 1 | `group_rename.sql` | One-off for a database that still has the tables `group` and `group_member`: renames them to `user_group` and `user_group_member`. Run before 5. |
| 2 | `users.sql` | Table definition for `users`. For a database with no `users` table. |
| 3 | `user_profile.sql` | Table definition for `user_profile`. |
| 4 | `user_view.sql` | The `user_view` compatibility view. Re-run it to restore the view after an entity type named `user` replaced it. |
| 5 | `group.sql` | Table definitions for `user_group` and `user_group_member`. |
| 6 | `permission.sql` | Table definition for `permission`. |
| 7 | `users_migration.sql` | SPENT. One-off data move from the EAV `user` entity into `users`, applied 2026-08-08. Needs 2 and 4. Do not run again. |
| 8 | `permission_migration.sql` | One-off for a database that has the old per-module grant tables: copies their grants into `permission`. Needs 6. |
| 9 | `capability_migration.sql` | One-off for a database whose capabilities are still rows in `user_settings`: copies them into `permission`. Needs 6. |
| 10 | `auth_auth_manual.sql` | Table definition for `auth_token`. |
| 11 | `auth_oauth_provider.sql` | Table definition and seed for `oauth_provider`. |
| 12 | `auth_oauth_token.sql` | Table definition for `oauth_token`. |
| - | `user_view_eav_rollback.sql` | Rollback only: restores the EAV form of `user_view`. Not part of any sequence. |
| - | `auth_user_schema.sql` | A reference snapshot of the account tables. Not a script; do not run it. |
