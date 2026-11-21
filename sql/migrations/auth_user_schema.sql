-- SNAPSHOT — REFERENCE ONLY. DO NOT RUN THIS FILE.
--
-- The live schema of the account tables Auth reads and writes, dumped from the
-- unisolu database so the shape is readable from inside this module without a
-- round trip to the DB. It is a photograph, not a migration.
--
-- AUTHORITATIVE SOURCE: core/*.sql, not this file. This is a manually
-- maintained reference snapshot living in Auth for local readability; if it
-- and core/*.sql ever disagree, core/*.sql wins — update this file to match,
-- not the other way around.
--
-- The canonical, runnable DDL lives in core/sql/ and is the only place to
-- change any of this:
--
--     users         ->  core/sql/migrations/users.sql
--     user_profile  ->  core/sql/migrations/user_profile.sql
--     user_view     ->  core/sql/migrations/user_view.sql
--     user_settings ->  (no DDL file anywhere; predates the model repo)
--
-- Those files carry the design rationale — why email is utf8mb4_bin, why the
-- view's column list is frozen, why `encrypted` is per row. Read them before
-- changing anything here. This file only records what the server currently has.
--
-- Auth's OWN tables are not repeated here; see core/sql/migrations/auth_auth_manual.sql
-- (auth_token) and core/sql/migrations/auth_oauth_token.sql (oauth_token).
--
-- Regenerate (mysqldump cannot read ~/.my.cnf — it only has a [mysql] group):
--
--     for t in users user_profile user_settings; do
--         mysql unisolu -e "SHOW CREATE TABLE $t\G"
--     done
--     mysql unisolu -e "SHOW CREATE VIEW user_view\G"
--
-- Snapshot taken 2026-08-15 against MariaDB 10.5.
--
-- NOTE: there is not a single FOREIGN KEY among these tables. Every user_id is
-- an unenforced reference, and the two int types do not even agree (see below).
-- Deleting an account is a hand-written cascade in Admin::deleteUser()
-- (core/modules/Admin/Admin.php): user_settings, user_group_member, api_tokens,
-- oauth_token, then the users row. It does NOT clear user_profile or auth_token
-- — both were added after that method was written. Orphans are possible and are
-- not the DB's problem.


-- ---------------------------------------------------------------------------
-- users — the account of record. Written by Zero\Core\Access\User.
--
-- Auth::findOrCreateUser() is the only code that creates rows here. `verified`
-- means the EMAIL is confirmed (by a provider or by a magic link), which is the
-- invariant the whole matching-by-email design rests on.
-- ---------------------------------------------------------------------------
CREATE TABLE `users` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(191) DEFAULT NULL,
  `email` varchar(254) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `verified` tinyint(1) NOT NULL DEFAULT 0,
  `pic` varchar(512) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_email` (`email`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------------
-- user_profile — values for the configurable registration fields declared in
-- core/modules/Auth/registration-fields.json. One row per (user_id, field_key).
--
-- Auth is the ONLY consumer, through Zero\Core\Access\User's ->storeProfile()/
-- ->profile() instance methods — there is no separate UserProfile class. No
-- other module touches it. `encrypted` is authoritative per row — trust the
-- column, never sniff the value.
-- ---------------------------------------------------------------------------
CREATE TABLE `user_profile` (
  `user_id` int(10) unsigned NOT NULL,
  `field_key` varchar(64) NOT NULL,
  `value` text DEFAULT NULL,
  `encrypted` tinyint(1) NOT NULL DEFAULT 0,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`user_id`,`field_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------------
-- user_settings — per-account key/value settings (auth.level). NOT an Auth
-- table: Auth neither reads nor writes it. Included here for completeness
-- because it is keyed by user_id.
--
-- Capabilities (allow.admin and the other allow.* keys) are not here: they are
-- rows in the core `permission` table (core/sql/migrations/permission.sql). Written by
-- core/User.php::setSetting() and core/modules/Admin.
--
-- Two mismatches worth knowing, both pre-existing:
--   * user_id is a SIGNED int(11) here, but users.id is int(10) UNSIGNED.
--   * updated_by is a user id with no constraint and no matching type either.
-- ---------------------------------------------------------------------------
CREATE TABLE `user_settings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `setting_key` varchar(255) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `created_on` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_on` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_user_setting` (`user_id`,`setting_key`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_setting_key` (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------------
-- user_view — compatibility projection over `users`, left behind by the move
-- off EAV. This originally was how the REST of the site read accounts: 60
-- references across 20 files, none of them in Auth. The user-consolidation
-- work has since moved every one of those readers off it, so the live
-- reference count is now zero — the view is retained anyway, deliberately,
-- with no readers, rather than dropped.
--
-- The column list is frozen on purpose. Adding a column here would change what
-- any future reader sees. New columns are read from Zero\Core\Access\User or from
-- `users` directly.
-- ---------------------------------------------------------------------------
CREATE OR REPLACE SQL SECURITY INVOKER VIEW `user_view` AS
    SELECT `users`.`id`       AS `id`,
           `users`.`name`     AS `name`,
           `users`.`email`    AS `email`,
           `users`.`verified` AS `verified`,
           `users`.`pic`      AS `pic`
    FROM `users`;
