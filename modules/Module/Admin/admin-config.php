<?php
/**
 * Optional discovery metadata. AdminResponse::getAdminModules() reads this,
 * applies defaults for anything missing and lists the module only when the
 * module is switched on and the user holds every key in `permissions`.
 * Whether the module is on, and where its card sits in the dashboard, are
 * decided in app/modules.ini ([enabled] and [order]), not here.
 */
return [
    'label'       => 'Manage Module',
    'icon'        => 'extension',
    'description' => 'Admin interface for this module',
    'permissions' => ['allow.admin'],
];
