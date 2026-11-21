-- core.sql: every table and view that core and its bundled modules read or write,
-- as they are defined on the live database. Load it on an empty database, or again
-- on one that has some of the tables (CREATE TABLE IF NOT EXISTS, CREATE OR REPLACE
-- VIEW, seed rows added only when missing): it never drops, truncates or updates anything,
-- and a repeat load takes no auto-increment ids.
--
--   mysql <database> < core/sql/core.sql
--
-- Rows it inserts are the ones a table needs to function:
--   oauth_provider  the four sign-in providers Auth knows (google, github, discord, gitlab)
--   entity_type     the 'event' type, found by label: id 2 when free, else the next id
--   entity          the four schema rows (id 0) fixing the event type's attribute order
-- The 'entity' table is partitioned by type. It is created with the single partition e_type2;
-- the event type's partition is added when its id is not 2, and the entity framework adds one
-- partition for each further type a site defines. event_view reads the event type's id.
--
-- Order: tables first, views after the tables they read. See core/sql/README.md.

-- users: one row per account. Owner: core (Zero\Core\Access\User); see core/docs/PERMISSIONS.md
CREATE TABLE IF NOT EXISTS `users` (
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

-- user_profile: per-account profile fields (some encrypted). Owner: core (Zero\Core\Access\User)
CREATE TABLE IF NOT EXISTS `user_profile` (
  `user_id` int(10) unsigned NOT NULL,
  `field_key` varchar(64) NOT NULL,
  `value` text DEFAULT NULL,
  `encrypted` tinyint(1) NOT NULL DEFAULT 0,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`user_id`,`field_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- user_settings: per-account key/value settings. Owner: core (Zero\Core\Access\User)
CREATE TABLE IF NOT EXISTS `user_settings` (
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

-- user_group: groups of accounts. Owner: core (Zero\Core\Group)
CREATE TABLE IF NOT EXISTS `user_group` (
  `group_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `group_name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`group_id`),
  UNIQUE KEY `group_name` (`group_name`),
  KEY `idx_group_name` (`group_name`),
  KEY `idx_is_active` (`is_active`),
  KEY `idx_created_by` (`created_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- user_group_member: group membership; the key is the only foreign key core carries. Owner: core (Zero\Core\Group)
CREATE TABLE IF NOT EXISTS `user_group_member` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `group_id` int(10) unsigned NOT NULL,
  `user_id` int(10) unsigned NOT NULL,
  `role` varchar(50) DEFAULT 'member' COMMENT 'member, admin, owner',
  `added_by` int(10) unsigned DEFAULT NULL,
  `added_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_group_user` (`group_id`,`user_id`),
  KEY `idx_group_id` (`group_id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_role` (`role`),
  CONSTRAINT `user_group_member_ibfk_1` FOREIGN KEY (`group_id`) REFERENCES `user_group` (`group_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- permission: capability and sharing grants to an account or a group. Owner: core (Zero\Core\Permission)
CREATE TABLE IF NOT EXISTS `permission` (
  `permission_key` varchar(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `entity_id` int(10) unsigned NOT NULL DEFAULT 0,
  `user_id` int(10) unsigned NOT NULL DEFAULT 0,
  `group_id` int(10) unsigned NOT NULL DEFAULT 0,
  `can_read` tinyint(1) NOT NULL DEFAULT 1,
  `can_update` tinyint(1) NOT NULL DEFAULT 0,
  `can_delete` tinyint(1) NOT NULL DEFAULT 0,
  `granted_by` int(10) unsigned DEFAULT NULL,
  `granted_on` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_by` int(10) unsigned DEFAULT NULL,
  `updated_on` datetime DEFAULT NULL,
  PRIMARY KEY (`permission_key`,`entity_id`,`user_id`,`group_id`),
  KEY `idx_user` (`user_id`,`permission_key`,`entity_id`),
  KEY `idx_group` (`group_id`,`permission_key`,`entity_id`),
  CONSTRAINT `permission_one_subject` CHECK (`user_id` = 0 <> (`group_id` = 0))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- auth_token: sign-in, verification and reset tokens (selector/validator). Owner: Auth module
CREATE TABLE IF NOT EXISTS `auth_token` (
  `selector` char(22) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `validator` char(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `user_id` int(10) unsigned NOT NULL,
  `purpose` varchar(24) NOT NULL,
  `expires_at` datetime NOT NULL,
  `consumed_at` datetime DEFAULT NULL,
  `created_ip` varchar(45) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`selector`),
  KEY `idx_user_purpose` (`user_id`,`purpose`),
  KEY `idx_expires` (`expires_at`),
  KEY `idx_ip_created` (`created_ip`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- api_tokens: hashed bearer tokens for #[AllowWithToken]. Owner: User module (read by core/attribute/AllowWithToken.php)
CREATE TABLE IF NOT EXISTS `api_tokens` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `token_hash` varchar(64) NOT NULL,
  `label` varchar(100) NOT NULL DEFAULT '',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `last_used_at` timestamp NULL DEFAULT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_token_hash` (`token_hash`),
  KEY `idx_user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- oauth_provider: which OAuth providers exist and whether they are enabled. Owner: Auth module
CREATE TABLE IF NOT EXISTS `oauth_provider` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `slug` varchar(32) NOT NULL,
  `name` varchar(64) NOT NULL,
  `via_code` int(10) unsigned NOT NULL,
  `enabled` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` int(10) unsigned NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_slug` (`slug`),
  UNIQUE KEY `uniq_via` (`via_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed: the providers Auth ships. A provider whose slug or via_code is already there is left as it is
-- (an admin may have switched it off). Each row gets its id spelled out, so a repeat load takes no auto-increment ids.
INSERT INTO oauth_provider (id, slug, name, via_code, enabled, sort_order)
SELECT (SELECT COALESCE(MAX(id), 0) + 1 FROM oauth_provider), 'google', 'Google', 3, 1, 1 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM oauth_provider WHERE slug = 'google' OR via_code = 3);

INSERT INTO oauth_provider (id, slug, name, via_code, enabled, sort_order)
SELECT (SELECT COALESCE(MAX(id), 0) + 1 FROM oauth_provider), 'github', 'GitHub', 12, 1, 2 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM oauth_provider WHERE slug = 'github' OR via_code = 12);

INSERT INTO oauth_provider (id, slug, name, via_code, enabled, sort_order)
SELECT (SELECT COALESCE(MAX(id), 0) + 1 FROM oauth_provider), 'discord', 'Discord', 13, 1, 3 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM oauth_provider WHERE slug = 'discord' OR via_code = 13);

INSERT INTO oauth_provider (id, slug, name, via_code, enabled, sort_order)
SELECT (SELECT COALESCE(MAX(id), 0) + 1 FROM oauth_provider), 'gitlab', 'GitLab', 14, 1, 4 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM oauth_provider WHERE slug = 'gitlab' OR via_code = 14);

-- oauth_token: one OAuth token per (account, provider). Owner: Auth module
CREATE TABLE IF NOT EXISTS `oauth_token` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `provider` varchar(32) NOT NULL,
  `access_token` longtext DEFAULT NULL,
  `refresh_token` longtext DEFAULT NULL,
  `token_type` varchar(32) DEFAULT NULL,
  `scope` text DEFAULT NULL,
  `provider_user_id` varchar(191) DEFAULT NULL,
  `expires_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_user_provider` (`user_id`,`provider`),
  KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- access_requests: requests for a permission, approved by token. Owner: AccessRequest module
CREATE TABLE IF NOT EXISTS `access_requests` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `requested_permission` varchar(255) NOT NULL,
  `requested_url` varchar(512) NOT NULL,
  `reason` text NOT NULL,
  `status` enum('pending','approved','rejected') DEFAULT 'pending',
  `token` varchar(64) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `approved_at` timestamp NULL DEFAULT NULL,
  `approved_by` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `token` (`token`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_token` (`token`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- push_subscriptions: browser push endpoints per account. Owner: Push module
CREATE TABLE IF NOT EXISTS `push_subscriptions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `endpoint` text NOT NULL,
  `p256dh_key` varchar(255) NOT NULL,
  `auth_token` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_user_endpoint` (`user_id`,`endpoint`(255)),
  KEY `idx_user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- push_notifications: the notification log. Owner: Push module
CREATE TABLE IF NOT EXISTS `push_notifications` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL,
  `body` text NOT NULL,
  `icon` varchar(512) DEFAULT NULL,
  `image` varchar(512) DEFAULT NULL,
  `url` varchar(512) DEFAULT NULL,
  `target_type` enum('all','specific') NOT NULL DEFAULT 'specific',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `created_by` int(11) NOT NULL,
  `sent_at` timestamp NULL DEFAULT NULL,
  `recipient_count` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_created_by` (`created_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- push_notification_clicks: who opened which notification. Owner: Push module
CREATE TABLE IF NOT EXISTS `push_notification_clicks` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `notification_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `clicked_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_notification_user` (`notification_id`,`user_id`),
  KEY `idx_notification_id` (`notification_id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_clicked_at` (`clicked_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ws_tokens: short-lived websocket tokens. Written by a site's Ws module; named here because Admin's account delete clears an account's rows. Owner: site (Ws), read by Admin
CREATE TABLE IF NOT EXISTS `ws_tokens` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `token` varchar(64) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `expires_at` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY (`id`),
  UNIQUE KEY `token` (`token`),
  KEY `idx_token` (`token`),
  KEY `idx_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- entity_type: one row per entity type. Owner: entity framework (Zero\Entity\Entity)
CREATE TABLE IF NOT EXISTS `entity_type` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `label` varchar(255) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `label` (`label`),
  KEY `label_2` (`label`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;

-- entity: attribute rows of every entity, partitioned by type; id 0 rows are a type's schema. Owner: entity framework
CREATE TABLE IF NOT EXISTS `entity` (
  `type` int(11) NOT NULL,
  `id` int(11) NOT NULL,
  `attr` int(11) NOT NULL,
  `value` varchar(255) DEFAULT NULL,
  UNIQUE KEY `type` (`type`,`id`,`attr`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci
 PARTITION BY LIST (`type`)
(PARTITION `e_type2` VALUES IN (2) ENGINE = MyISAM);

-- Seed: the event type that Zero\Entity\Event uses, found by its label. It takes id 2 when that id is free;
-- when id 2 belongs to another type it takes the next id. A repeat load adds nothing and takes no ids.
INSERT INTO entity_type (id, label)
SELECT IF(EXISTS (SELECT 1 FROM entity_type WHERE id = 2), (SELECT MAX(id) + 1 FROM entity_type), 2), 'event' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM entity_type WHERE label = 'event');

-- The event type's partition, when its id is not one `entity` already has a partition for.
SET @zero_event_partition = (
  SELECT CONCAT('ALTER TABLE entity ADD PARTITION (PARTITION e_type', t.id, ' VALUES IN (', t.id, '))')
  FROM entity_type t
  WHERE t.label = 'event'
    AND NOT EXISTS (SELECT 1 FROM information_schema.PARTITIONS x
                    WHERE x.TABLE_SCHEMA = DATABASE() AND x.TABLE_NAME = 'entity'
                      AND x.PARTITION_NAME = CONCAT('e_type', t.id))
);
SET @zero_event_partition = IFNULL(@zero_event_partition, 'DO 0');
PREPARE zero_stmt FROM @zero_event_partition;
EXECUTE zero_stmt;
DEALLOCATE PREPARE zero_stmt;

-- The event type's schema rows (id 0; attr is the column index), under the event type's own id.
INSERT IGNORE INTO entity (type, id, attr, value)
SELECT t.id, 0, a.attr, a.value FROM entity_type t
JOIN (
  SELECT 0 AS attr, 'datetime' AS value
  UNION ALL SELECT 1, 'event'
  UNION ALL SELECT 2, 'detail'
  UNION ALL SELECT 3, 'ip'
) AS a
WHERE t.label = 'event';

-- event_view: the event type pivoted into columns. Owner: entity framework (rebuilt by Entity::updateView())
-- Built from the id the 'event' label has (2 on an empty database), the way the partition is added.
SET @zero_event_view = (
  SELECT CONCAT('CREATE OR REPLACE SQL SECURITY DEFINER VIEW `event_view` AS select `e`.`id` AS `id`,max(case when `e`.`attr` = 0 then `e`.`value` end) AS `datetime`,max(case when `e`.`attr` = 1 then `e`.`value` end) AS `event`,max(case when `e`.`attr` = 2 then `e`.`value` end) AS `detail`,max(case when `e`.`attr` = 3 then `e`.`value` end) AS `ip` from `entity` `e` where `e`.`type` = ', t.id, ' and `e`.`id` <> 0 group by `e`.`id`')
  FROM entity_type t
  WHERE t.label = 'event'
);
SET @zero_event_view = IFNULL(@zero_event_view, 'DO 0');
PREPARE zero_stmt FROM @zero_event_view;
EXECUTE zero_stmt;
DEALLOCATE PREPARE zero_stmt;

-- user_view: compatibility view over users for sites that still join it; nothing in core reads it. Owner: core
CREATE OR REPLACE SQL SECURITY INVOKER VIEW `user_view` AS select `users`.`id` AS `id`,`users`.`name` AS `name`,`users`.`email` AS `email`,`users`.`verified` AS `verified`,`users`.`pic` AS `pic` from `users`;
