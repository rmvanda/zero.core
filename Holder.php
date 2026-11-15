<?php
namespace Zero\Core;

/**
 * Something a permission can be granted to: a user or a group.
 *
 * The `permission` table has one column for each kind of holder (user_id,
 * group_id) and exactly one of them is set on a row. A holder says which
 * column is its own and what its id is; Zero\Core\Permission needs nothing
 * else to grant to it, revoke from it or forget it.
 */
interface Holder
{
    /** The `permission` column this kind of holder is stored in: 'user_id' or 'group_id'. */
    public function holderColumn(): string;

    /** This holder's id. Not positive means "not a real holder": nothing is granted to it. */
    public function holderId(): int;
}
