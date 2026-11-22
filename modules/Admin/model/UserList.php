<?php
namespace Zero\Module\Admin\Model;

use Zero\Core\Access\User;
use Zero\Core\Access\Group;
use Zero\Core\Access\Permission;

/**
 * The users screen's list: one page of users, each with what the row shows
 * (how many capabilities the user holds and the groups they belong to).
 *
 * Everything is read through Zero\Core\Access. The users and the total are
 * one query each; the capabilities and the groups are one query each per
 * user on the page, because the Access API answers those for one user at a
 * time.
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
    public static function page(int $page, int $perPage = self::PER_PAGE): array
    {
        $perPage = max(1, $perPage);
        $total = User::total();
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min(max(1, $page), $pages);

        $rows = [];
        foreach (User::page($perPage, ($page - 1) * $perPage) as $user) {
            $rows[] = [
                'id' => (int) $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'pic' => $user->pic,
                'verified' => (bool) $user->verified,
                'disabled' => $user->isDisabled(),
                'capabilityCount' => count(Permission::capabilities($user)),
                // Inactive groups too: this screen manages them.
                'groups' => array_map(fn(Group $group) => $group->group_name, $user->groups(false)),
            ];
        }

        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => $pages, 'perPage' => $perPage];
    }
}
