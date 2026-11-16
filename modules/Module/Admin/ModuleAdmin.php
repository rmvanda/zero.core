<?php
/**
 * AdminResponse::getAdminModules() walks Modules::all() and lists a module
 * when this class (Zero\Module\{Module}\Admin\{Module}Admin) exists, the
 * module is switched on in app/modules.ini, and the user holds every key in
 * `permissions`.
 * URL: https://example.com/admin/module
 *
 * Note the namespace: Zero\Module\{Module}\Admin — the Admin segment is
 * appended, unlike a submodule.
 */
namespace Zero\Module\Module\Admin;

use Zero\Core\AdminResponse;
use Zero\Core\Attribute\RequirePermission;

class ModuleAdmin extends AdminResponse {

    #[RequirePermission('allow.admin')]
    public function index() {
        $this->respond($this->viewPath . 'index.php');
    }
}
