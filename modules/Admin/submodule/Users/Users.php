<?php

namespace Zero\Module\Admin;

use \Zero\Core\AdminResponse;
use \Zero\Core\Attribute\RequirePermission;
use \Zero\Core\Attribute\{AllowedMethods, RequiredParams, AuditLog};
use \Zero\Core\Exception\HTTPError;
use \Zero\Core\Access\User;
use \Zero\Core\Access\Permission;
use \Zero\Core\Session;
use \Zero\Core\Console;
use \Zero\Module\Admin\Model\CapabilityKeys;
use \Zero\Module\Admin\Model\UserList;

/**
 * Admin's users screens: the list of users, and one user's capabilities and
 * settings.
 *
 * /admin/users lists them and /admin/users/edit/{id} shows one. A capability
 * (allow.*, a row in `permission`) changes through set-capability; a setting
 * (a value in user_settings) through set-setting and remove-setting; an
 * account is closed and opened again through disable and enable.
 *
 * An action that did not work is an HTTPError; one that worked ends in done().
 */
#[RequirePermission('allow.admin')]
class Users extends AdminResponse {

    /**
     * The list of users, a page at a time
     * GET /admin/users?page=2
     */
    public function index() {
        $page = $_GET['page'] ?? '1';
        $page = (is_string($page) && ctype_digit($page)) ? max(1, (int) $page) : 1;

        $list = UserList::page($page);
        $this->userRows = $list['rows'];
        $this->userTotal = $list['total'];
        $this->listPage = $list['page'];
        $this->listPages = $list['pages'];

        $this->build(__DIR__."/view/index.php");
    }

    /**
     * One user's capabilities and settings
     * GET /admin/users/edit/{id}
     */
    public function edit($id = null) {
        $user = $this->findUser($id, 404);

        $this->account = $user;

        // Capabilities: every key that can be granted, the ones this user holds
        // directly, and the ones they hold at all (their groups' included)
        $own = Permission::capabilities($user, true);
        $held = Permission::capabilities($user);
        $this->capabilityRows = [];
        foreach (CapabilityKeys::all() as $key) {
            $isOwn = in_array($key, $own, true);
            $this->capabilityRows[] = [
                'key' => $key,
                'own' => $isOwn,
                'throughGroup' => !$isOwn && in_array($key, $held, true),
            ];
        }

        // Settings: every key in use on the site, with this user's value where
        // they have one. A capability is not a setting, so an allow.* key left
        // in user_settings is not listed.
        $values = $user->settings();
        $dates = $user->settingDates();
        $this->settingRows = [];
        foreach (User::settingKeysInUse() as $key) {
            if (self::isCapabilityKey($key)) {
                continue;
            }
            $isSet = array_key_exists($key, $values) && $values[$key] !== null;
            $this->settingRows[] = [
                'key' => $key,
                'type' => self::settingType($key),
                'isSet' => $isSet,
                'value' => $isSet ? (string) $values[$key] : '',
                'updated' => ($isSet && !empty($dates[$key])) ? date('Y-m-d H:i', strtotime($dates[$key])) : null,
            ];
        }

        $this->addBreadcrumb((string) ($user->name ?: $user->email));
        $this->build(__DIR__."/view/edit.php");
    }

    /**
     * Grant a capability to a user, or take it away
     * POST /admin/users/set-capability
     *
     * `key` is an allow.* key. `value` "1" grants it; anything else, or no
     * value, revokes it. A capability the user has through a group stays
     * until the group loses it.
     */
    #[AuditLog('admin_permission_change', includeParams: true)]
    #[AllowedMethods('POST')]
    #[RequiredParams(['user_id', 'key'])]
    public function setCapability() {
        $user = $this->findUser($_POST['user_id'] ?? null);
        $key = $this->postedString('key');
        $grant = ($_POST['value'] ?? null) === '1';

        try {
            Permission::capabilityKey($key);
        } catch (\InvalidArgumentException $e) {
            throw new HTTPError(400, 'A capability key is allow. and then lowercase words joined by dots');
        }

        $ok = $grant ? $user->grant($key, Session::userId()) : $user->revoke($key);
        if (!$ok) {
            throw new HTTPError(500, "Could not save the capability {$key}");
        }

        $this->done($grant ? 'Capability granted' : 'Capability revoked', '/admin/users/edit/' . $user->id, [
            'user_id' => (int) $user->id,
            'key' => $key,
            'granted' => $grant,
        ]);
    }

    /**
     * Set one of a user's settings
     * POST /admin/users/set-setting
     *
     * `key` is a setting key (auth.level, limit.api.requests), never an
     * allow.* key: that is a capability. `value` is required but may be "0"
     * or empty, which is why it is checked here and not by RequiredParams.
     * A limit.* or auth.* setting is a whole number.
     */
    #[AuditLog('user_setting_change', includeParams: true)]
    #[AllowedMethods('POST')]
    #[RequiredParams(['user_id', 'key'])]
    public function setSetting() {
        $user = $this->findUser($_POST['user_id'] ?? null);
        $key = $this->settingKey();
        if (!isset($_POST['value'])) {
            throw new HTTPError(400, 'Missing required parameter(s): value');
        }
        $value = $this->postedString('value');

        if (self::settingType($key) === 'integer' && !preg_match('/^-?[0-9]{1,9}$/', $value)) {
            throw new HTTPError(400, "{$key} must be a whole number");
        }

        // The acting admin is recorded as updated_by via setSetting()'s
        // $actorId parameter.
        if (!$user->setSetting($key, $value, Session::userId())) {
            throw new HTTPError(500, "Could not save the setting {$key}");
        }

        $this->done('Setting saved', '/admin/users/edit/' . $user->id, [
            'user_id' => (int) $user->id,
            'key' => $key,
            'value' => $value,
        ]);
    }

    /**
     * Remove one of a user's settings
     * POST /admin/users/remove-setting
     */
    #[AuditLog('user_setting_change', includeParams: true)]
    #[AllowedMethods('POST')]
    #[RequiredParams(['user_id', 'key'])]
    public function removeSetting() {
        $user = $this->findUser($_POST['user_id'] ?? null);
        $key = $this->settingKey();

        if (!array_key_exists($key, $user->settings())) {
            throw new HTTPError(404, "This user has no setting {$key}");
        }
        if (!$user->removeSetting($key)) {
            throw new HTTPError(500, "Could not remove the setting {$key}");
        }

        $this->done('Setting removed', '/admin/users/edit/' . $user->id, [
            'user_id' => (int) $user->id,
            'key' => $key,
        ]);
    }

    // ============================================================================
    // USER MANAGEMENT
    // ============================================================================

    /**
     * Disable a user: the account cannot sign in, and its open sessions end
     * at their next request. Nothing is deleted. An account is never deleted
     * from a page; its data stays.
     * POST /admin/users/disable
     */
    #[AuditLog('user_disable', includeParams: true)]
    #[AllowedMethods('POST')]
    #[RequiredParams(['user_id'])]
    public function disable() {
        $this->setUserDisabled(true);
    }

    /**
     * Let a disabled user sign in again
     * POST /admin/users/enable
     */
    #[AuditLog('user_enable', includeParams: true)]
    #[AllowedMethods('POST')]
    #[RequiredParams(['user_id'])]
    public function enable() {
        $this->setUserDisabled(false);
    }

    /** The work of disable() and enable(). Protected: it is not an endpoint. */
    protected function setUserDisabled(bool $disabled) {
        $user = $this->findUser($_POST['user_id'] ?? null);
        $userId = (int) $user->id;

        // Prevent disabling yourself
        if ($disabled && $userId === (int) Session::userId()) {
            throw new HTTPError(409, 'You cannot disable your own account');
        }

        try {
            $disabled ? $user->disable() : $user->enable();
        } catch (\Throwable $e) {
            Console::error("Error changing user $userId: " . $e->getMessage());
            throw new HTTPError(500, $disabled ? 'Could not disable the user' : 'Could not enable the user');
        }

        $this->done($disabled ? 'User disabled' : 'User enabled', '/admin/users/edit/' . $userId, [
            'user_id' => $userId,
            'disabled' => $disabled,
        ]);
    }

    /**
     * The user with this id.
     *
     * @param mixed $id      From the URL or a form
     * @param int   $badCode The status for an id that is not a whole number:
     *                       400 from a form, 404 from a URL
     * @throws HTTPError 404 when no user has that id
     */
    protected function findUser($id, int $badCode = 400): User {
        // A JSON body gives a number, a form gives a string: both are an id.
        $id = is_int($id) ? (string) $id : $id;
        if (!is_string($id) || !ctype_digit($id) || strlen($id) > 10 || (int) $id < 1) {
            throw new HTTPError($badCode, $badCode === 404 ? 'User not found' : 'user_id must be a whole number');
        }
        $user = User::find((int) $id);
        if (!$user) {
            throw new HTTPError(404, 'User not found');
        }

        return $user;
    }

    /**
     * One posted field as a trimmed string. An array is a 400.
     */
    protected function postedString(string $name): string {
        $value = $_POST[$name] ?? '';
        if (!is_string($value)) {
            throw new HTTPError(400, "{$name} must be a string");
        }

        return trim($value);
    }

    /**
     * The posted `key` as a setting key: letters, digits, dots, hyphens and
     * underscores, and not a capability's.
     */
    protected function settingKey(): string {
        $key = $this->postedString('key');
        if (self::isCapabilityKey($key)) {
            throw new HTTPError(400, 'allow.* is a capability, not a setting');
        }
        if (strlen($key) > 255 || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $key)) {
            throw new HTTPError(400, 'A setting key is letters, digits, dots, hyphens and underscores');
        }

        return $key;
    }

    /** Is this an allow.* key, which names a capability and never a setting? */
    protected static function isCapabilityKey(string $key): bool {
        return str_starts_with($key, 'allow.');
    }

    /**
     * What kind of value a setting holds, by naming convention: limit.* and
     * auth.* are whole numbers, anything else is text.
     */
    protected static function settingType(string $key): string {
        if (str_starts_with($key, 'limit.') || str_starts_with($key, 'auth.')) {
            return 'integer';
        }

        return 'text';
    }
}
