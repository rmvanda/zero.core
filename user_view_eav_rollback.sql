-- ROLLBACK ONLY — do not run as part of normal operation.
--
-- This restores user_view to its pre-migration form: an EAV pivot over
-- `entity WHERE type = 4`. It is the undo for model/user_view.sql.
--
-- PRECONDITION, and it is not a matter of elapsed time. ALL THREE must hold:
--     SELECT COUNT(*), MAX(id), MAX(updated_at) FROM users;
--   COUNT(*) must be 9  — a deleted user (ids 1-8) would be RESURRECTED by this
--                         rollback, since all 40 EAV rows are still intact,
--                         while its user_settings/group_member rows are gone.
--   MAX(id) must be 9   — a user created after cutover exists ONLY in `users`
--                         and has no EAV counterpart, so rollback loses them;
--                         their next login mints a fresh EAV id that will not
--                         match the id already recorded in user_settings,
--                         group_member, api_tokens, oauth_token, notes, kanban.
--   MAX(updated_at) must be at or before the cutover — any edit to the 9 rows
--                         would be silently reverted to the 2026-08-08 snapshot.
--
-- The matching code-side undo is `git revert` of the modules/ and entity/ cutover
-- commits, and the DB half must go FIRST.
CREATE OR REPLACE SQL SECURITY INVOKER VIEW user_view AS
    SELECT e.id AS id,
           MAX(CASE WHEN e.attr = 0 THEN e.value END) AS name,
           MAX(CASE WHEN e.attr = 1 THEN e.value END) AS email,
           MAX(CASE WHEN e.attr = 2 THEN e.value END) AS verified,
           MAX(CASE WHEN e.attr = 3 THEN e.value END) AS pic
    FROM entity e WHERE e.type = 4 AND e.id <> 0 GROUP BY e.id;

-- The entity_type row was removed at cutover; restore it too, or the generic
-- admin entity endpoints stay 404 for the user type.
INSERT INTO entity_type (id, label) VALUES (4, 'user');
