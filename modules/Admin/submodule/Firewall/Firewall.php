<?php

namespace Zero\Module\Admin;

use \Zero\Core\AdminResponse;
use \Zero\Core\Console;
use \Zero\Core\Attribute\RequirePermission;
use \Zero\Core\Attribute\AllowedMethods;
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
            $this->export(['success' => false, 'error' => 'Failed to fetch rules from CloudFlare']);
            return;
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
    #[AllowedMethods('POST')]
    public function unblock() {
        $ip = trim($_POST['ip'] ?? '');

        if (empty($ip)) {
            $this->export(['success' => false, 'error' => 'No IP provided']);
            return;
        }

        $result = CloudFlare::unblock($ip);
        $this->export([
            'success' => $result,
            'ip' => $ip,
            'message' => $result ? "Unblocked {$ip}" : "Failed to unblock {$ip}",
        ]);
    }
}
