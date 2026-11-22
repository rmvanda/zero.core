<?php

namespace Zero\Module\Admin;

use \Zero\Core\AdminResponse;
use \Zero\Core\Session;
use \Zero\Core\Access\Group;
use \Zero\Core\Access\Permission;
use \Zero\Core\Access\User;
use \Zero\Core\Attribute\RequirePermission;
use \Zero\Core\Attribute\{AllowedMethods, RequiredParams, AuditLog};
use \Zero\Core\Exception\HTTPError;
use \Zero\Module\Admin\Model\CapabilityKeys;

/**
 * Admin's permissions screens: every capability (an allow.* key) with who
 * holds it, and granting or revoking one for several users and groups at once.
 *
 * The users screen answers "what may this user do"; this one answers "who may
 * do this". Both write the same rows, through Zero\Core\Access.
 *
 * A key is written in a URL with slashes for its dots
 * (/admin/permissions/holders/allow/polly), so no admin URL carries a dotted
 * word the ban check could take for a file name.
 */
#[RequirePermission('allow.admin')]
class Permissions extends AdminResponse {

    /**
     * Every capability, with how many users and groups hold it
     * GET /admin/permissions
     */
    public function index() {
        $counts = Permission::capabilityHolderCounts();

        $this->capabilityRows = [];
        foreach (CapabilityKeys::all() as $key) {
            $this->capabilityRows[] = [
                'key' => $key,
                'url' => self::holdersUrl($key),
                'users' => $counts[$key]['users'] ?? 0,
                'groups' => $counts[$key]['groups'] ?? 0,
            ];
        }

        $this->build(__DIR__."/view/index.php");
    }

    /**
     * Who holds one capability, and the forms that grant and revoke it
     * GET /admin/permissions/holders/allow/polly
     */
    public function holders(string ...$parts) {
        $key = $this->capabilityKey(implode('.', $parts), 404);
        $capability = Permission::capability($key);

        // The active groups granted it, and every user who has it: directly
        // (group_name null) or as a member of one of those groups.
        $groups = $capability->groups(0);
        $direct = [];
        $viaGroup = [];
        foreach ($capability->users(0) as $row) {
            $id = (int) $row['user_id'];
            if ($row['group_name'] === null) {
                $direct[$id] = ['id' => $id, 'name' => $row['name'], 'email' => $row['email']];
            } else {
                $viaGroup[$id] ??= ['id' => $id, 'name' => $row['name'], 'email' => $row['email'], 'groups' => []];
                $viaGroup[$id]['groups'][] = $row['group_name'];
            }
        }

        // Who could be granted it: the active groups and the users not holding it themselves
        $holdingGroups = array_map('intval', array_column($groups, 'group_id'));
        $otherGroups = array_values(array_filter(
            Group::all(true),
            fn(Group $group) => !in_array((int) $group->group_id, $holdingGroups, true)
        ));
        $otherUsers = array_values(array_filter(
            $this->everyUser(),
            fn(array $user) => !isset($direct[$user['id']])
        ));

        $this->heldKey = $key;
        $this->holdingGroups = $groups;
        $this->directUsers = array_values($direct);
        $this->groupUsers = array_values($viaGroup);
        $this->otherGroups = $otherGroups;
        $this->otherUsers = $otherUsers;
        $this->selfId = (int) Session::userId();

        $this->addBreadcrumb($key);
        $this->build(__DIR__."/view/holders.php");
    }

    /**
     * Grant one capability to several users and groups
     * POST /admin/permissions/grant
     *
     * `key` is the capability; `user_ids[]` and `group_ids[]` name who gets
     * it. Nothing is granted unless every id names a user or a group.
     */
    #[AuditLog('permission_bulk_grant', includeParams: true)]
    #[AllowedMethods('POST')]
    #[RequiredParams(['key'])]
    public function grant() {
        $this->change(true);
    }

    /**
     * Revoke one capability from several users and groups
     * POST /admin/permissions/revoke
     *
     * The same fields as grant. A user who has the capability through a group
     * keeps it until the group loses it. An admin cannot revoke allow.admin
     * from the account they are signed in with.
     */
    #[AuditLog('permission_bulk_revoke', includeParams: true)]
    #[AllowedMethods('POST')]
    #[RequiredParams(['key'])]
    public function revoke() {
        $this->change(false);
    }

    /** The work of grant() and revoke(). Protected: it is not an endpoint. */
    protected function change(bool $grant): void {
        $key = $this->capabilityKey($_POST['key'] ?? null, 400);

        // Every holder is found before anything is written, so a bad id changes nothing.
        $holders = [];
        foreach ($this->postedIds('user_ids') as $id) {
            $user = User::find($id);
            if (!$user) {
                throw new HTTPError(404, "User {$id} not found");
            }
            if (!$grant && $key === 'allow.admin' && $id === (int) Session::userId()) {
                throw new HTTPError(409, 'You cannot revoke allow.admin from your own account');
            }
            $holders[] = $user;
        }
        foreach ($this->postedIds('group_ids') as $id) {
            $group = Group::find($id);
            if (!$group) {
                throw new HTTPError(404, "Group {$id} not found");
            }
            $holders[] = $group;
        }
        if (!$holders) {
            throw new HTTPError(400, 'Choose at least one user or group');
        }

        $actor = Session::userId();
        foreach ($holders as $holder) {
            $ok = $grant ? $holder->grant($key, $actor) : $holder->revoke($key);
            if (!$ok) {
                $who = $holder instanceof Group ? "group {$holder->group_id}" : "user {$holder->id}";
                throw new HTTPError(500, "Could not save {$key} for {$who}; the ones before it were saved");
            }
        }

        $count = count($holders);
        $this->done(
            ($grant ? 'Granted to ' : 'Revoked from ') . $count . ($count === 1 ? ' holder' : ' holders'),
            self::holdersUrl($key),
            ['key' => $key, 'granted' => $grant, 'holders' => $count]
        );
    }

    /**
     * A capability key from a URL or a form, once it is known to be one.
     *
     * @param mixed $key
     * @param int $code The status when it is not a capability key
     */
    protected function capabilityKey($key, int $code): string {
        try {
            return Permission::capabilityKey(is_string($key) ? $key : '');
        } catch (\InvalidArgumentException $e) {
            throw new HTTPError($code, 'A capability key is allow. and then lowercase words joined by dots');
        }
    }

    /**
     * The ids in one posted list (user_ids[], group_ids[]), each once.
     *
     * @return int[]
     */
    protected function postedIds(string $name): array {
        $posted = $_POST[$name] ?? [];
        if (!is_array($posted)) {
            throw new HTTPError(400, "{$name} must be a list");
        }
        $ids = [];
        foreach ($posted as $id) {
            if (!(is_int($id) || is_string($id)) || !ctype_digit((string) $id) || (int) $id < 1) {
                throw new HTTPError(400, "{$name} must hold whole numbers");
            }
            $ids[(int) $id] = (int) $id;
        }

        return array_values($ids);
    }

    /**
     * Every user, in name order: rows of id, name, email. The signed-in admin
     * first, since User::mentionableUsers() leaves them out.
     */
    protected function everyUser(): array {
        $self = Session::user();
        if ($self === null) {
            return [];
        }

        return array_merge(
            [['id' => (int) $self->id, 'name' => $self->name, 'email' => $self->email]],
            $self->mentionableUsers()
        );
    }

    /** The URL of a capability's holders screen: its dots written as slashes. */
    protected static function holdersUrl(string $key): string {
        return '/admin/permissions/holders/' . str_replace('.', '/', $key);
    }
}
