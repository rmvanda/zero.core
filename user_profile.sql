-- Values for the configurable registration fields (see
-- modules/Auth/registration-fields.json). One row per (user_id, field_key).
--
-- `encrypted` is stored PER ROW rather than inferred from the current field
-- config: if a field is later flipped from sensitive to plain, or the key is
-- rotated, existing rows stay correctly interpretable. Readers must trust this
-- column and never guess with Crypto::isEncrypted().
CREATE TABLE user_profile (
    user_id    INT UNSIGNED NOT NULL,
    field_key  VARCHAR(64)  NOT NULL,
    value      TEXT         NULL,
    encrypted  TINYINT(1)   NOT NULL DEFAULT 0,
    updated_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
                                       ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, field_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
