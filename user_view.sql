-- Compatibility view: originally kept 57 existing `JOIN user_view` call sites
-- across 19 files working unchanged after the move off EAV. The user-
-- consolidation work has since moved every reader off it, so the live count
-- of JOIN user_view call sites is now zero — the view is retained anyway,
-- deliberately, with no readers, rather than dropped.
--
-- The column projection is deliberately IDENTICAL to the old EAV view —
-- created_at/updated_at are excluded. core/attribute/AllowWithToken.php used
-- to do `SELECT * FROM user_view`; it now reads through Zero\Core\User::find()
-- against `users` directly instead. Still, read any new columns from
-- Zero\Core\User or from `users` directly rather than adding them here.
--
-- CREATE OR REPLACE rather than DROP + CREATE: no window where the view is absent.
--
-- SQL SECURITY INVOKER: the view runs with the querying user's privileges rather
-- than a fixed DEFINER account, so it has no dependency on any specific account
-- existing on the host (a dump/restore onto a host without that DEFINER account
-- would otherwise fail reads, back when call sites depended on this view).
CREATE OR REPLACE SQL SECURITY INVOKER VIEW user_view AS
    SELECT id, name, email, verified, pic FROM users;
