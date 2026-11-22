<?php

namespace Zero\Module\Admin;

use \Zero\Core\AdminResponse;
use \Zero\Core\Console;
use \Zero\Core\Attribute\RequirePermission;
use \Zero\Core\Attribute\AllowedMethods;
use \Zero\Core\Attribute\AuditLog;
use \Zero\Core\Exception\HTTPError;
use \Zero\Lib\CloudFlare\CloudFlare;

/**
 * Admin submodule for managing CloudFlare IP block rules
 */
#[RequirePermission('allow.admin')]
class Firewall extends AdminResponse {

    /**
     * Main firewall view — lists blocked IPs
     */
    public function index() {
        $this->respond($this->viewPath . 'index.php');
    }

    /**
     * API: Get blocked IPs with search and pagination
     */
    public function list() {
        $page = (int) ($_GET['page'] ?? 1);
        $perPage = (int) ($_GET['per_page'] ?? 20);
        $search = trim($_GET['search'] ?? '');

        $filters = ['mode' => 'block'];

        // Cloudflare supports filtering by configuration.value (IP) and notes
        if ($search !== '') {
            $filters['configuration.value'] = $search;
        }

        $data = CloudFlare::list($filters, $page, $perPage);

        if ($data === null) {
            throw new HTTPError(502, 'Failed to fetch rules from CloudFlare');
        }

        $this->export([
            'success' => true,
            'rules' => $data['result'],
            'pagination' => $data['result_info'],
        ]);
    }

    /**
     * API: Unblock an IP address
     */
    #[AuditLog('firewall_unblock', includeParams: true)]
    #[AllowedMethods('POST')]
    public function unblock() {
        $ip = trim($_POST['ip'] ?? '');

        if (empty($ip)) {
            throw new HTTPError(400, 'No IP provided');
        }

        if (!CloudFlare::unblock($ip)) {
            throw new HTTPError(502, "Failed to unblock {$ip}");
        }

        $this->export([
            'success' => true,
            'ip' => $ip,
            'message' => "Unblocked {$ip}",
        ]);
    }
}
