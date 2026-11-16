-- Permissions: the table behind Zero\Core\Permission
--
-- One row grants one subject (a user OR a group, never both) rights under one
-- permission_key. Unused key columns hold 0, never NULL, so the primary key
-- can tell two rows apart and an upsert can find the row it means.
--
--   permission_key  "{module}.{entity}" for a module's own things
--                   (lists.list, kanban.board), so one module's rows are
--                   permission_key LIKE 'lists.%'
--   entity_id       the item; 0 = every item under the key
--   user_id         the user granted, or 0 when the row is a group's
--   group_id        the group granted, or 0 when the row is a user's
--
-- Rows only grant. There is no deny row and no row with all three flags 0:
-- revoking deletes the row.

CREATE TABLE IF NOT EXISTS permission (
    permission_key VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    entity_id      INT UNSIGNED NOT NULL DEFAULT 0,
    user_id        INT UNSIGNED NOT NULL DEFAULT 0,
    group_id       INT UNSIGNED NOT NULL DEFAULT 0,
    can_read       TINYINT(1) NOT NULL DEFAULT 1,
    can_update     TINYINT(1) NOT NULL DEFAULT 0,
    can_delete     TINYINT(1) NOT NULL DEFAULT 0,
    granted_by     INT UNSIGNED DEFAULT NULL,
    granted_on     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_by     INT UNSIGNED DEFAULT NULL,
    updated_on     DATETIME DEFAULT NULL,
    PRIMARY KEY (permission_key, entity_id, user_id, group_id),
    KEY idx_user  (user_id, permission_key, entity_id),
    KEY idx_group (group_id, permission_key, entity_id),
    CONSTRAINT permission_one_subject CHECK ((user_id = 0) <> (group_id = 0))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
