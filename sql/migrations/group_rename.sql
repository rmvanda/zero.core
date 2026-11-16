-- Renames the group tables so neither is a reserved word. One statement, so
-- no query sees one renamed and not the other. Foreign keys onto the tables
-- (ttrpg_campaigns.group_id) follow the rename.
RENAME TABLE `group` TO user_group, group_member TO user_group_member;
