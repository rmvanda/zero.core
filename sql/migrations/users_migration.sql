-- ============================================================================
-- SPENT. Applied 2026-08-08. DO NOT RE-RUN.
-- Kept for provenance only. The INSERT fails safely on the PK / UNIQUE email,
-- but the ALTER below is NOT idempotent — see the note on it.
-- ============================================================================
-- One-time data move: EAV `user` entity (entity.type = 4) -> `users` table.
-- Run AFTER users.sql. Non-destructive: reads user_view, writes users, and
-- leaves every EAV row untouched so rollback stays possible.
--
-- pic is copied verbatim — one row holds an empty string rather than NULL, and
-- this migration is deliberately lossless rather than tidy.
INSERT INTO users (id, name, email, verified, pic)
SELECT id,
       name,
       email,
       CASE WHEN verified = '1' THEN 1 ELSE 0 END,
       pic
FROM user_view;

-- Existing ids are contiguous 1-9; continue past them.
-- APPLIED ALREADY — deliberately commented out. Re-running this would clamp
-- AUTO_INCREMENT down to MAX(id)+1, rewinding it past ids the test suite has
-- already burned and allowing a new account to reuse a deleted user's id.
-- ALTER TABLE users AUTO_INCREMENT = 10;
