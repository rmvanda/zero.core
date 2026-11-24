<?php

namespace Zero\Core\Module\Admin;

use \Zero\Core\AdminResponse;
use \Zero\Core\Attribute\RequirePermission;
use \Zero\Core\Module\Admin\Model\EventLog;

/**
 * Admin's audit log: every recorded event, newest first, with filters.
 * /admin/audit
 *
 * An endpoint with #[AuditLog] records who called it, how and from where; the
 * framework records a few events of its own (Mail Sent, user_created). All of
 * them are here.
 */
#[RequirePermission('allow.admin')]
class Audit extends AdminResponse {

    /**
     * The events, filtered by the query string
     * GET /admin/audit?event=&user=&ip=&date_from=&date_to=&detail=&per_page=&page=
     */
    public function index() {
        $log = $this->log();

        $this->installed = $log->installed();
        $this->filters = EventLog::filters($_GET);
        $this->events = ['rows' => [], 'total' => 0, 'page' => 1, 'pages' => 1, 'perPage' => $this->filters['per_page']];
        $this->eventNames = [];
        if ($this->installed) {
            $this->events = $log->page($this->filters);
            $this->eventNames = $log->eventNames();
        }

        $this->build(__DIR__."/view/index.php");
    }

    /**
     * The log over the site database
     */
    protected function log(): EventLog {
        return new EventLog();
    }
}
