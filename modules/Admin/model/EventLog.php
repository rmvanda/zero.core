<?php
namespace Zero\Core\Module\Admin\Model;

use Zero\Core\Database;

/**
 * The event log: what #[AuditLog] and the framework's own events (Mail Sent,
 * user_created, ...) record, read from `event_view`, newest first.
 *
 * The events are entities of the type `event` (Zero\Entity\Event); the view
 * gives one row per event with id, datetime, event, detail and ip.
 */
class EventLog
{
    /** The page sizes the screen offers. */
    public const PER_PAGE = [25, 50, 100];

    public function __construct(private ?\PDO $db = null)
    {
        $this->db ??= Database::getConnection();
    }

    /**
     * Whether the site has an event log at all: the entity tables and the
     * view are part of core.sql, and a site may not have loaded them.
     */
    public function installed(): bool
    {
        try {
            $this->db->query("SELECT 1 FROM event_view LIMIT 0");
            return true;
        } catch (\PDOException $e) {
            if (($e->errorInfo[0] ?? $e->getCode()) === '42S02') {
                return false;
            }
            throw $e;
        }
    }

    /**
     * The filters of a query string, cleaned: what is not usable is dropped.
     *
     * @param array $query $_GET
     * @return array{event:string,ip:string,date_from:string,date_to:string,detail:string,user:string,per_page:int,page:int}
     */
    public static function filters(array $query): array
    {
        $text = fn(string $name) => is_string($query[$name] ?? null) ? trim($query[$name]) : '';
        $date = fn(string $name) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $text($name)) ? $text($name) : '';
        $perPage = (int) ($query['per_page'] ?? 0);

        return [
            'event' => $text('event'),
            'ip' => $text('ip'),
            'date_from' => $date('date_from'),
            'date_to' => $date('date_to'),
            'detail' => $text('detail'),
            'user' => ctype_digit($text('user')) ? $text('user') : '',
            'per_page' => in_array($perPage, self::PER_PAGE, true) ? $perPage : self::PER_PAGE[0],
            'page' => max(1, (int) (is_scalar($query['page'] ?? null) ? $query['page'] : 1)),
        ];
    }

    /**
     * One page of the events that match the filters.
     *
     * @param array $filters From filters()
     * @return array{rows: array[], total:int, page:int, pages:int, perPage:int}
     *         Each row has id, datetime, event, detail, ip
     */
    public function page(array $filters): array
    {
        [$where, $params] = self::where($filters);

        $count = $this->db->prepare("SELECT COUNT(*) FROM event_view {$where}");
        $count->execute($params);
        $total = (int) $count->fetchColumn();

        $perPage = (int) $filters['per_page'];
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min((int) $filters['page'], $pages);

        $stmt = $this->db->prepare(
            "SELECT id, datetime, event, detail, ip FROM event_view {$where} ORDER BY datetime DESC, id DESC LIMIT "
            . $perPage . " OFFSET " . (($page - 1) * $perPage)
        );
        $stmt->execute($params);

        return ['rows' => $stmt->fetchAll(\PDO::FETCH_ASSOC), 'total' => $total, 'page' => $page, 'pages' => $pages, 'perPage' => $perPage];
    }

    /**
     * Every event name that has been recorded, for the filter's list.
     *
     * @return string[]
     */
    public function eventNames(): array
    {
        return $this->db->query("SELECT DISTINCT event FROM event_view WHERE event IS NOT NULL ORDER BY event")
            ->fetchAll(\PDO::FETCH_COLUMN);
    }

    /**
     * The WHERE clause and its values for a set of filters.
     *
     * @return array{0:string,1:array}
     */
    private static function where(array $filters): array
    {
        // % and _ in a search are literal text, not wildcards
        $like = fn(string $text) => '%' . addcslashes($text, '\\%_') . '%';
        $clauses = [];
        $params = [];

        if ($filters['event'] !== '') {
            $clauses[] = "event = ?";
            $params[] = $filters['event'];
        }
        if ($filters['ip'] !== '') {
            $clauses[] = "ip LIKE ?";
            $params[] = $like($filters['ip']);
        }
        if ($filters['date_from'] !== '') {
            $clauses[] = "datetime >= ?";
            $params[] = $filters['date_from'] . ' 00:00:00';
        }
        if ($filters['date_to'] !== '') {
            $clauses[] = "datetime <= ?";
            $params[] = $filters['date_to'] . ' 23:59:59';
        }
        if ($filters['detail'] !== '') {
            $clauses[] = "detail LIKE ?";
            $params[] = $like($filters['detail']);
        }
        if ($filters['user'] !== '') {
            // #[AuditLog] writes "... | User ID: 5 | Method: ...": the id is followed by a space or ends the text
            $clauses[] = "(detail LIKE ? OR detail LIKE ?)";
            $params[] = '%User ID: ' . $filters['user'] . ' %';
            $params[] = '%User ID: ' . $filters['user'];
        }

        return [$clauses ? 'WHERE ' . implode(' AND ', $clauses) : '', $params];
    }

    /**
     * The id of the user an audit event names ("User ID: 5" in its detail),
     * or null: an event of another kind, or one nobody was signed in for.
     */
    public static function userId(?string $detail): ?int
    {
        return preg_match('/\bUser ID: (\d+)\b/', (string) $detail, $found) ? (int) $found[1] : null;
    }
}
