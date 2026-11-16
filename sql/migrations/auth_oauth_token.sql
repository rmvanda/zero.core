-- OAuth per-provider token store. One row per (user_id, provider).
-- Secret columns (access_token, refresh_token) hold a Zero\Core\Crypto envelope;
-- the rest is plaintext + queryable. See
-- docs/superpowers/specs/2026-07-07-oauth-token-persistence-design.md
CREATE TABLE IF NOT EXISTS oauth_token (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id          INT UNSIGNED NOT NULL,      -- logical FK -> users.id (not enforced; user_view is retained but has zero live readers, this column included)
  provider         VARCHAR(32)  NOT NULL,      -- 'google' | 'github' | 'discord' | 'gitlab'
  access_token     LONGTEXT     NULL,          -- encrypted
  refresh_token    LONGTEXT     NULL,          -- encrypted
  token_type       VARCHAR(32)  NULL,
  scope            TEXT         NULL,
  provider_user_id VARCHAR(191) NULL,
  expires_at       DATETIME     NULL,          -- NULL = non-expiring (GitHub)
  created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_user_provider (user_id, provider),
  KEY idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
