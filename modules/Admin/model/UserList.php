<?php
namespace Zero\Module\Admin\Model;

use Zero\Core\Database;

/**
 * The users screen's list: one page of users, each with what the row shows
 * (how many capabilities the user holds and the groups they belong to).
 *
 * This class runs its own SQL against `users`, `user_group`,
 * `user_group_member` and `permission`. That is a deliberate exception to the
 * rule that a module reads those tables through Zero\Core\Access: this is the
 * one screen that asks about every user at once, and the Access API answers
 * for one user at a time, which made two queries per row. A page is five
 * queries however many users it holds. A second screen with the same need is
 * the sign to give Zero\Core\Access a batch form instead.
 */
class UserList
{
    /** Users on one page. */
    public const PER_PAGE = 25;

    /**
     * @param int $page    The page asked for, from 1. One past the end gives the last page.
     * @param int $perPage Users on a page
     * @return array{rows: array<int,array{id:int,name:?string,email:?string,pic:?string,verified:bool,disabled:bool,capabilityCount:int,groups:string[]}>, total:int, page:int, pages:int, perPage:int}
     */
    public static function page(int $page, int $perPage = self::PER_PAGE, ?\PDO $db = null): array
    {
        $db ??= Database::getConnection();
        $perPage = max(1, $perPage);
        $total = (int) $db->query("SELECT COUNT(*) FROM users")->fetchColumn();
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min(max(1, $page), $pages);

        $users = $db->query(
            "SELECT id, name, email, pic, verified, disabled FROM users ORDER BY id ASC LIMIT "
            . $perPage . " OFFSET " . (($page - 1) * $perPage)
        )->fetchAll(\PDO::FETCH_ASSOC);

        $ids = array_map(fn(array $user) => (int) $user['id'], $users);
        [$groupNames, $activeGroups] = self::memberships($db, $ids);
        $keys = self::capabilityKeys($db, $ids, $activeGroups);

        $rows = [];
        foreach ($users as $user) {
            $id = (int) $user['id'];
            $rows[] = [
                'id' => $id,
                'name' => $user['name'],
                'email' => $user['email'],
                'pic' => $user['pic'],
                'verified' => (bool) $user['verified'],
                'disabled' => (int) $user['disabled'] === 1,
                'capabilityCount' => count($keys[$id] ?? []),
                // Inactive groups too: this screen manages them.
                'groups' => $groupNames[$id] ?? [],
            ];
        }

        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => $pages, 'perPage' => $perPage];
    }

    /**
     * The groups of these users.
     *
     * @param int[] $ids
     * @return array{0: array<int,string[]>, 1: array<int,int[]>} The names of
     *         every group of each user (in name order), and the ids of each
     *         user's active groups
     */
    private static function memberships(\PDO $db, array $ids): array
    {
        $names = [];
        $active = [];
        if (!$ids) {
            return [$names, $active];
        }
        $stmt = $db->prepare("
            SELECT gm.user_id, g.group_id, g.group_name, g.is_active
            FROM user_group_member gm
            JOIN user_group g ON g.group_id = gm.group_id
            WHERE gm.user_id IN (" . self::marks($ids) . ")
            ORDER BY g.group_name ASC
        ");
        $stmt->execute($ids);
        foreach ($stmt as $row) {
            $names[(int) $row['user_id']][] = $row['group_name'];
            if ((int) $row['is_active'] === 1) {
                $active[(int) $row['user_id']][] = (int) $row['group_id'];
            }
        }

        return [$names, $active];
    }

    /**
     * The capabilities of these users: each user's own and those of the
     * active groups they belong to, as Permission::capabilities() counts them.
     *
     * @param int[] $ids
     * @param array<int,int[]> $activeGroups user id => ids of their active groups
     * @return array<int,array<string,true>> user id => the keys they hold
     */
    private static function capabilityKeys(\PDO $db, array $ids, array $activeGroups): array
    {
        $keys = [];
        if (!$ids) {
            return $keys;
        }
        $capability = "permission_key LIKE 'allow.%' AND entity_id = 0 AND can_read = 1";

        $stmt = $db->prepare("SELECT user_id, permission_key FROM permission WHERE $capability AND user_id IN (" . self::marks($ids) . ")");
        $stmt->execute($ids);
        foreach ($stmt as $row) {
            $keys[(int) $row['user_id']][$row['permission_key']] = true;
        }

        $groupIds = array_values(array_unique(array_merge([], ...array_values($activeGroups))));
        if ($groupIds) {
            $stmt = $db->prepare("SELECT group_id, permission_key FROM permission WHERE $capability AND group_id IN (" . self::marks($groupIds) . ")");
            $stmt->execute($groupIds);
            $byGroup = [];
            foreach ($stmt as $row) {
                $byGroup[(int) $row['group_id']][] = $row['permission_key'];
            }
            foreach ($activeGroups as $userId => $groups) {
                foreach ($groups as $groupId) {
                    foreach ($byGroup[$groupId] ?? [] as $key) {
                        $keys[$userId][$key] = true;
                    }
                }
            }
        }

        return $keys;
    }

    /** One placeholder per value, for an IN (...) list. */
    private static function marks(array $values): string
    {
        return implode(',', array_fill(0, count($values), '?'));
    }
}
