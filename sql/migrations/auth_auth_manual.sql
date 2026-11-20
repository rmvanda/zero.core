-- One-time tokens for magic-link sign-in, email verification, and (later)
-- password reset.
--
-- selector + validator rather than one opaque token: the selector is the indexed
-- lookup key, the validator is the secret and only its SHA-256 is stored. One
-- indexed lookup, a constant-time compare, and a DB leak grants no impersonation.
-- Same shape as the remember-me design in core/SESSION_IMPROVEMENTS.md.
--
-- consumed_at is set rather than the row deleted, so a replayed link can be told
-- apart from an expired one.
CREATE TABLE auth_token (
    -- utf8mb4_bin, not the table default: these hold base64url values over a
    -- CASE-SENSITIVE alphabet. Under a _ci collation two selectors differing
    -- only in case collide on the PRIMARY KEY, and a lookup matches the wrong
    -- case — folding the effective keyspace.
    selector    CHAR(22) COLLATE utf8mb4_bin NOT NULL PRIMARY KEY,
    validator   CHAR(64) COLLATE utf8mb4_bin NOT NULL,
    user_id     INT UNSIGNED NOT NULL,
    purpose     VARCHAR(24)  NOT NULL,
    expires_at  DATETIME     NOT NULL,
    consumed_at DATETIME     NULL,
    created_ip  VARCHAR(45)  NULL,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_user_purpose (user_id, purpose),
    KEY idx_expires (expires_at),
    KEY idx_ip_created (created_ip, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
