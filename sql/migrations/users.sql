-- Zero\Core\User backing table.
-- Replaces the EAV `user` entity type (entity.type = 4). See
-- docs/superpowers/specs/2026-08-07-zero-model-user-migration-design.md
--
-- DEFAULT CHARSET is stated explicitly on purpose: the database default is
-- latin1, and every modern table here overrides it.
CREATE TABLE users (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name       VARCHAR(191)  NULL,
    -- utf8mb4_bin, not the table default: under utf8mb4_unicode_ci, Unicode
    -- folding made gross@x.com and groß@x.com the SAME address, so whoever
    -- registered one silently blocked the other from ever signing up. Byte-exact
    -- comparison ends that. The cost is that case must be normalised in code
    -- instead — Zero\Core\User::normalizeEmail() does it on create(), and
    -- findByEmail() does it on lookup. A raw findBy('email', …) will MISS on a
    -- case difference, silently.
    email      VARCHAR(254)  COLLATE utf8mb4_bin NOT NULL,
    verified   TINYINT(1)    NOT NULL DEFAULT 0,
    pic        VARCHAR(512)  NULL,
    created_at DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP
                                       ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_email (email),
    KEY idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
