-- users.disabled: 1 = the account cannot sign in. An account is disabled, never deleted.
-- Run once on a site that was installed before this column existed.
ALTER TABLE `users` ADD COLUMN `disabled` tinyint(1) NOT NULL DEFAULT 0 AFTER `verified`;
