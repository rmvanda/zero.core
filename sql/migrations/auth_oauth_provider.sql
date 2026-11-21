-- OAuth provider registry — the agnostic, admin-toggleable source of truth for
-- which providers exist and whether they're available. The (future) admin
-- dashboard flips `enabled`; login buttons and /user/profile read from this.
CREATE TABLE IF NOT EXISTS oauth_provider (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  slug       VARCHAR(32)  NOT NULL,               -- 'google' | 'github' | 'discord' | 'gitlab'
  name       VARCHAR(64)  NOT NULL,               -- display name
  via_code   INT UNSIGNED NOT NULL,               -- Auth $smids code for /auth/user?via= and /auth/connect?via=
  enabled    TINYINT(1)   NOT NULL DEFAULT 1,     -- admin availability toggle
  sort_order INT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_slug (slug),
  UNIQUE KEY uniq_via (via_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed the current providers. Idempotent: re-running updates metadata but
-- deliberately does NOT touch `enabled`, so it can't undo an admin's toggle.
INSERT INTO oauth_provider (slug, name, via_code, enabled, sort_order) VALUES
  ('google',  'Google',  3,  1, 1),
  ('github',  'GitHub',  12, 1, 2),
  ('discord', 'Discord', 13, 1, 3),
  ('gitlab',  'GitLab',  14, 1, 4)
ON DUPLICATE KEY UPDATE
  name = VALUES(name), via_code = VALUES(via_code), sort_order = VALUES(sort_order);
