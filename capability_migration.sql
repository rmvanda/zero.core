-- Moves capabilities out of user_settings into permission. A capability is a
-- row on entity_id 0 with can_read set, under the capability's own key.
--
-- Safe to run more than once: the copy is INSERT IGNORE and the delete only
-- removes what the copy covers.
--
-- Capabilities are every allow.* key and the three older keys named below.
-- Everything else in user_settings (auth.level, index0.confirmations) is a
-- setting and stays.

INSERT IGNORE INTO permission
    (permission_key, entity_id, user_id, group_id, can_read, can_update, can_delete, granted_by, granted_on)
SELECT s.setting_key, 0, s.user_id, 0, 1, 0, 0, NULLIF(s.updated_by, 0), s.created_on
FROM user_settings s
WHERE s.user_id > 0
  AND s.setting_value = '1'
  AND (s.setting_key LIKE 'allow.%' OR s.setting_key IN ('network.mapper', 'hello.world', 'posts.edit'));

-- Rows for user_id 0 were a list of known keys, not grants. They go too.
DELETE FROM user_settings
WHERE setting_key LIKE 'allow.%' OR setting_key IN ('network.mapper', 'hello.world', 'posts.edit');

-- Every capability key starts with allow. The two older keys in use gain the
-- prefix. Safe to run more than once.
UPDATE permission SET permission_key = CONCAT('allow.', permission_key)
WHERE permission_key IN ('network.mapper', 'hello.world');

UPDATE access_requests SET requested_permission = CONCAT('allow.', requested_permission)
WHERE requested_permission IN ('network.mapper', 'hello.world');
