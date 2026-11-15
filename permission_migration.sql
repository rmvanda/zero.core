-- Copies the grants in the old per-module tables into `permission`.
-- See DEPRECATING_CHANGES.md, "Permissions: one core table".
--
-- Safe to run more than once: a grant that is already there is left as it is.
-- A statement whose source table does not exist on this site fails on its
-- own; run the file with `mysql --force` to carry on past it.
-- It does not drop the old tables.

INSERT IGNORE INTO permission (permission_key, entity_id, group_id, can_read, can_update, can_delete, granted_on)
SELECT 'lists.list', list_id, group_id, can_read, can_update, can_delete, created_at
FROM list_permissions WHERE can_read = 1 OR can_update = 1 OR can_delete = 1;

INSERT IGNORE INTO permission (permission_key, entity_id, group_id, can_read, can_update, can_delete, granted_on)
SELECT 'notebook.notebook', notebook_id, group_id, IFNULL(can_read, 0), IFNULL(can_update, 0), IFNULL(can_delete, 0), created_at
FROM notebook_permissions WHERE can_read = 1 OR can_update = 1 OR can_delete = 1;

INSERT IGNORE INTO permission (permission_key, entity_id, group_id, can_read, can_update, can_delete, granted_on)
SELECT 'notes.note', note_id, group_id, IFNULL(can_read, 0), IFNULL(can_update, 0), IFNULL(can_delete, 0), created_at
FROM note_permissions WHERE can_read = 1 OR can_update = 1 OR can_delete = 1;

INSERT IGNORE INTO permission (permission_key, entity_id, group_id, can_read, can_update, can_delete, granted_on)
SELECT 'kanban.board', board_id, group_id, IFNULL(can_read, 0), IFNULL(can_update, 0), IFNULL(can_delete, 0), created_at
FROM kanban_permissions WHERE can_read = 1 OR can_update = 1 OR can_delete = 1;

-- A Comfy share was read-only and recorded who shared it.
INSERT IGNORE INTO permission (permission_key, entity_id, group_id, can_read, can_update, can_delete, granted_by, granted_on)
SELECT 'comfy.generation', generation_id, group_id, 1, 0, 0, shared_by, created_at
FROM comfy_share;
