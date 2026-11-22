<?php

declare(strict_types=1);

namespace Zero\Module\Admin\Tests;

use PHPUnit\Framework\TestCase;
use Zero\Module\Admin\Model\EventLog;

/**
 * The Admin audit log: its filters, its query and the screen without the tables.
 */
class AuditLogTest extends TestCase
{
    protected function setUp(): void
    {
        require_once dirname(__DIR__) . '/model/EventLog.php';
    }

    public function testFiltersKeepWhatIsUsableAndDropTheRest(): void
    {
        $filters = EventLog::filters([
            'event' => ' group_delete ', 'ip' => ['x'], 'date_from' => '2026-11-01', 'date_to' => 'yesterday',
            'detail' => 'abc', 'user' => '5; drop', 'per_page' => '7', 'page' => '-3',
        ]);

        $this->assertSame('group_delete', $filters['event']);
        $this->assertSame('', $filters['ip'], 'an array is not a search');
        $this->assertSame('2026-11-01', $filters['date_from']);
        $this->assertSame('', $filters['date_to'], 'not a date');
        $this->assertSame('', $filters['user'], 'not a number');
        $this->assertSame(25, $filters['per_page'], 'a size that is not offered is the smallest');
        $this->assertSame(1, $filters['page']);

        $this->assertSame('5', EventLog::filters(['user' => '5'])['user']);
        $this->assertSame(100, EventLog::filters(['per_page' => '100'])['per_page']);
    }

    public function testTheUserOfAnAuditEventIsReadFromItsDetail(): void
    {
        $this->assertSame(5, EventLog::userId('Endpoint: admin/users | User ID: 5 | Method: POST'));
        $this->assertSame(18652, EventLog::userId('Endpoint: x | User ID: 18652'));
        $this->assertNull(EventLog::userId('{"to":"a@example.test","subject":"User ID: none"}'));
        $this->assertNull(EventLog::userId(null));
    }

    public function testTheQueryCarriesEveryFilterAsAValue(): void
    {
        $seen = [];
        $pdo = $this->createStub(\PDO::class);
        $pdo->method('prepare')->willReturnCallback(function (string $sql) use (&$seen) {
            $stmt = $this->createStub(\PDOStatement::class);
            $stmt->method('execute')->willReturnCallback(function (array $params) use (&$seen, $sql) {
                $seen[] = [$sql, $params];
                return true;
            });
            $stmt->method('fetchColumn')->willReturn(120);
            $stmt->method('fetchAll')->willReturn([]);
            return $stmt;
        });

        $page = (new EventLog($pdo))->page(EventLog::filters([
            'event' => "x' OR 1=1", 'ip' => '10.0_', 'user' => '5', 'date_from' => '2026-11-01', 'page' => '99', 'per_page' => '50',
        ]));

        $this->assertSame(['total' => 120, 'page' => 3, 'pages' => 3, 'perPage' => 50], array_diff_key($page, ['rows' => 1]), 'a page past the end is the last one');
        [$sql, $params] = $seen[1];
        $this->assertStringNotContainsString("OR 1=1", $sql, 'no filter text is in the SQL');
        $this->assertSame(["x' OR 1=1", '%10.0\\_%', '2026-11-01 00:00:00', '%User ID: 5 %', '%User ID: 5'], $params);
        $this->assertStringContainsString('ORDER BY datetime DESC, id DESC LIMIT 50 OFFSET 100', $sql);
    }

    public function testASiteWithoutTheEntityTablesShowsTheMessageAndNoFilters(): void
    {
        require_once dirname(__DIR__) . '/submodule/Audit/Audit.php';

        $missing = new \PDOException("Table 'event_view' doesn't exist");
        $missing->errorInfo = ['42S02', 1146, "Table 'event_view' doesn't exist"];
        $pdo = $this->createStub(\PDO::class);
        $pdo->method('query')->willThrowException($missing);

        $audit = new class($pdo) extends \Zero\Module\Admin\Audit {
            public $builtView;
            public function __construct(private \PDO $pdo) {}
            public function __destruct() {}
            protected function log(): EventLog { return new EventLog($this->pdo); }
            protected function build($view = "") { $this->builtView = $view; }
        };

        $audit->index();

        $this->assertFalse($audit->installed);
        ob_start();
        \Closure::bind(function ($view) { include $view; }, $audit, get_class($audit))($audit->builtView);
        $html = ob_get_clean();
        $this->assertStringContainsString('The entity tables are not installed on this site', $html);
        $this->assertStringNotContainsString('Filter Events', $html);
        $this->assertStringNotContainsString('style=', $html);
    }
}
