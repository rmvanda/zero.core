-- Compatibility view: keeps the 57 existing `JOIN user_view` call sites across
-- 19 files working unchanged after the move off EAV.
--
-- The column projection is deliberately IDENTICAL to the old EAV view —
-- created_at/updated_at are excluded. core/attribute/AllowWithToken.php does
-- `SELECT * FROM user_view`, so adding columns here would change what that
-- sees. Read the new columns from Zero\Model\User or from `users` directly.
--
-- CREATE OR REPLACE rather than DROP + CREATE: no window where the view is absent.
--
-- SQL SECURITY INVOKER: the view runs with the querying user's privileges rather
-- than a fixed DEFINER account, so it has no dependency on any specific account
-- existing on the host (a dump/restore onto a host without that DEFINER account
-- would otherwise fail every read across all 57 call sites).
CREATE OR REPLACE SQL SECURITY INVOKER VIEW user_view AS
    SELECT id, name, email, verified, pic FROM users;
