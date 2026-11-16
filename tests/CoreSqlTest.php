<?php

namespace Zero\Tests\Core;

use PHPUnit\Framework\TestCase;

/**
 * core/sql/core.sql creates everything core and its bundled modules need on an
 * empty database, exactly as the live tables are defined.
 *
 * The first group of tests reads the PHP the framework ships (core, its
 * libraries, the entity framework and the ten bundled modules) and the SQL
 * file, and compares the two without a database. The last group loads the file
 * twice into a throwaway database named zerotest_core_sql and compares each
 * table with the live definition; it skips when the account cannot create a
 * database.
 */
class CoreSqlTest extends TestCase
{
    private const THROWAWAY = 'zerotest_core_sql';

    /** The ten modules that ship with core. */
    private const MODULES = [
        'Auth', 'User', 'Admin', 'AccessRequest', 'Sitemap',
        'Push', 'Entity', 'Module', 'HelloWorld', 'Test',
    ];

    /** Libraries core ships. */
    private const LIBS = ['CloudFlare', 'Http', 'Mail', 'Push'];

    /** Entity framework files core ships. */
    private const ENTITY_FILES = ['Entity.php', 'GenericEntity.php', 'Event.php'];

    /**
     * Words the scan reads as table names that are not tables: column names
     * that follow a FROM-like word inside a real SQL string, and the like.
     * Prose is never read at all (looksLikeSql()). Each entry names the reason.
     */
    private const NOT_TABLES = [
        'can_read' => 'column name',
        'group' => 'a button label ("Update Group"), not SQL',
        'p256dh_key' => 'column name',
        'setting_value' => 'column name',
        'value' => 'column name',
    ];

    /**
     * Tables the code touches by a name that is not inside a literal SQL
     * statement, or that a bundled view depends on. Each entry names the reason.
     */
    private const NAMED_ELSEWHERE = [
        'ws_tokens' => 'Admin UserAdmin::CASCADE deletes the account\'s rows from it by name',
        'user_view' => 'compatibility view over users, kept for sites that still join it; no PHP file names it',
    ];

    /** Statements that insert rows: only these tables, because the rows are what lets the table function. */
    private const SEED_TABLES = ['entity_type', 'entity', 'oauth_provider'];

    private static string $root;
    private static bool $canCreateDatabase = false;

    public static function setUpBeforeClass(): void
    {
        self::$root = dirname(__DIR__, 2) . '/';
        self::cli('DROP DATABASE IF EXISTS ' . self::THROWAWAY);
        [$code] = self::cli('CREATE DATABASE ' . self::THROWAWAY);
        self::$canCreateDatabase = $code === 0;
        self::cli('DROP DATABASE IF EXISTS ' . self::THROWAWAY);
    }

    public static function tearDownAfterClass(): void
    {
        self::cli('DROP DATABASE IF EXISTS ' . self::THROWAWAY);
    }

    /** Runs the mysql client; returns [exit code, stdout, stderr]. */
    private static function cli(string $sql, ?string $database = null, ?string $file = null, bool $skipNames = false): array
    {
        return MysqlCli::run($sql, $database, $file, $skipNames);
    }

    private function coreSqlPath(): string
    {
        return self::$root . 'core/sql/core.sql';
    }

    private function coreSql(): string
    {
        $path = $this->coreSqlPath();
        $this->assertFileExists($path, 'core/sql/core.sql is missing');
        return file_get_contents($path);
    }

    /** core.sql with its comments removed. */
    private function statementsText(): string
    {
        $sql = preg_replace('#/\*.*?\*/#s', '', $this->coreSql());
        return preg_replace('/^\s*--.*$/m', '', $sql);
    }

    /** @return string[] one entry per statement */
    private function statements(): array
    {
        $parts = array_map('trim', explode(";\n", $this->statementsText() . "\n"));
        return array_values(array_filter($parts, fn($s) => $s !== '' && $s !== ';'));
    }

    /** @return array{tables: string[], views: string[]} names created by core.sql */
    private function created(): array
    {
        $tables = $views = [];
        foreach ($this->statements() as $s) {
            if (preg_match('/^CREATE TABLE IF NOT EXISTS `?(\w+)`?/i', $s, $m)) {
                $tables[] = $m[1];
            } elseif (preg_match('/^(?:SET @zero_event_view = \(\s*SELECT CONCAT\(\x27)?CREATE OR REPLACE\b.*?\bVIEW `?(\w+)`?/is', $s, $m)) {
                $views[] = $m[1];
            }
        }
        return ['tables' => $tables, 'views' => $views];
    }

    /** First existing folder of a list. */
    private function firstDir(string ...$candidates): ?string
    {
        foreach ($candidates as $c) {
            if (is_dir(self::$root . $c)) {
                return self::$root . $c;
            }
        }
        return null;
    }

    /** @return string[] the PHP files the scan reads */
    private function scannedFiles(): array
    {
        $files = glob(self::$root . 'core/*.php');
        $dirs = [self::$root . 'core/attribute'];

        $libBase = $this->firstDir('core/lib', 'lib');
        foreach (self::LIBS as $lib) {
            $dirs[] = $libBase . '/' . $lib;
        }
        $entityBase = $this->firstDir('core/entity', 'entity');
        foreach (self::ENTITY_FILES as $f) {
            $files[] = $entityBase . '/' . $f;
        }
        foreach (self::MODULES as $m) {
            $dirs[] = self::$root . 'core/modules/' . $m;
        }

        foreach ($dirs as $dir) {
            if (!is_dir($dir)) {
                continue;
            }
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                $p = $f->getPathname();
                if ($f->getExtension() === 'php' && !preg_match('#/(tests|vendor|node_modules)/#', $p)) {
                    $files[] = $p;
                }
            }
        }
        return array_values(array_unique(array_filter($files, 'is_file')));
    }

    /**
     * Whether a string literal is SQL rather than prose: it starts with a
     * statement keyword, or holds a SELECT with its FROM. Prose in a message
     * ("already exists in the list") is not read for table names.
     */
    private function looksLikeSql(string $literal): bool
    {
        $text = trim($literal, "\"' \t\r\n");
        return preg_match('/^(SELECT|INSERT|REPLACE|UPDATE|DELETE|CREATE|ALTER|DROP|TRUNCATE|SHOW|DESCRIBE|WITH)\b/i', $text) === 1
            || preg_match('/\bSELECT\b.+\bFROM\b/is', $text) === 1;
    }

    /** @return array<string, string[]> table name => files naming it in a SQL string */
    private function tablesInCode(): array
    {
        $found = [];
        foreach ($this->scannedFiles() as $file) {
            foreach (token_get_all(file_get_contents($file)) as $tok) {
                if (!is_array($tok) || !in_array($tok[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
                    continue;
                }
                if (!$this->looksLikeSql($tok[1])) {
                    continue;
                }
                if (preg_match_all('/\b(?:FROM|JOIN|INTO|UPDATE|TABLE(?:\s+IF\s+(?:NOT\s+)?EXISTS)?)\s+`?([A-Za-z_][A-Za-z0-9_]*)`?/i', $tok[1], $m)) {
                    foreach ($m[1] as $name) {
                        $found[strtolower($name)][$file] = true;
                    }
                }
            }
        }
        $out = [];
        foreach ($found as $name => $files) {
            if (!isset(self::NOT_TABLES[$name])) {
                $out[$name] = array_map(fn($f) => str_replace(self::$root, '', $f), array_keys($files));
            }
        }
        ksort($out);
        return $out;
    }

    public function testEveryTableTheCodeNamesIsCreated(): void
    {
        $created = $this->created();
        $have = array_merge($created['tables'], $created['views']);
        $missing = [];
        foreach ($this->tablesInCode() as $name => $files) {
            if (!in_array($name, $have, true)) {
                $missing[] = $name . ' (' . $files[0] . ')';
            }
        }
        $this->assertSame([], $missing, 'core.sql does not create: ' . implode(', ', $missing));
    }

    public function testCoreSqlNamesNoTableOutsideTheScannedSet(): void
    {
        $created = $this->created();
        $allowed = array_merge(array_keys($this->tablesInCode()), array_keys(self::NAMED_ELSEWHERE));
        $extra = array_diff(array_merge($created['tables'], $created['views']), $allowed);
        $this->assertSame([], array_values($extra), 'core.sql creates tables no scanned code names: ' . implode(', ', $extra));
    }

    public function testNamedElsewhereTablesAreStillNamed(): void
    {
        $admin = self::$root . 'core/modules/Admin/model/UserAdmin.php';
        $this->assertStringContainsString("'ws_tokens'", file_get_contents($admin));
    }

    public function testNothingTheFileMustNotContain(): void
    {
        $text = $this->statementsText();
        $this->assertDoesNotMatchRegularExpression('/AUTO_INCREMENT\s*=/i', $text);
        $this->assertDoesNotMatchRegularExpression('/DEFINER\s*=/i', $text);
        $this->assertDoesNotMatchRegularExpression('/\bDROP\b/i', $text);
        $this->assertDoesNotMatchRegularExpression('/\b(TRUNCATE|(?<!ON )DELETE)\b/i', $text);
        $this->assertDoesNotMatchRegularExpression('/AUTO_INCREMENT\s*=/i', $this->coreSql());
    }

    public function testInsertsTargetOnlySeedTables(): void
    {
        foreach ($this->statements() as $s) {
            if (preg_match('/^INSERT\b(?:\s+IGNORE)?\s+INTO\s+`?(\w+)`?/i', $s, $m)) {
                $this->assertContains($m[1], self::SEED_TABLES, 'INSERT into a table that is not a listed seed: ' . $m[1]);
            } elseif (preg_match('/^INSERT\b/i', $s)) {
                $this->fail('Unrecognised INSERT: ' . substr($s, 0, 60));
            }
        }
    }

    public function testStatementsAreIdempotent(): void
    {
        foreach ($this->statements() as $s) {
            $this->assertMatchesRegularExpression(
                '/^(CREATE TABLE IF NOT EXISTS|CREATE OR REPLACE\b.*\bVIEW|INSERT IGNORE INTO entity\b|INSERT INTO (oauth_provider|entity_type)\b.*\bWHERE\s+NOT EXISTS|SET @zero_event_partition|SET @zero_event_view|PREPARE zero_stmt|EXECUTE zero_stmt|DEALLOCATE PREPARE zero_stmt)/is',
                $s,
                'A statement that can fail or add rows on a second load: ' . substr($s, 0, 60)
            );
        }
    }

    public function testViewsComeAfterTheTablesTheyRead(): void
    {
        $seen = [];
        foreach ($this->statements() as $s) {
            if (preg_match('/^CREATE TABLE IF NOT EXISTS `?(\w+)`?/i', $s, $m)) {
                $seen[$m[1]] = true;
            } elseif (preg_match('/^(?:SET @zero_event_view = \(\s*SELECT CONCAT\(\x27)?CREATE OR REPLACE\b.*?\bVIEW `?(\w+)`?.*?\bfrom\s+`?(\w+)`?/is', $s, $m)) {
                $this->assertArrayHasKey($m[2], $seen, "view {$m[1]} comes before its table {$m[2]}");
            }
        }
    }

    public function testEntityIsDeclaredWithOnlyTheEventPartition(): void
    {
        foreach ($this->statements() as $s) {
            if (preg_match('/^CREATE TABLE IF NOT EXISTS `entity`/i', $s)) {
                preg_match_all('/PARTITION `(\w+)`/', $s, $m);
                $this->assertSame(['e_type2'], $m[1]);
            }
        }
    }

    /** Whether the database the file was taken from, `unisolu`, is the one this test can compare with. */
    private function liveReference(): bool
    {
        [$code, $out] = self::cli("SHOW TABLES FROM unisolu LIKE 'entity_type'", null, null, true);
        return $code === 0 && trim($out) === 'entity_type';
    }

    public function testForeignKeysAreExactlyTheLiveOnes(): void
    {
        if (!$this->liveReference()) {
            $this->markTestSkipped('The reference database `unisolu` is not on this server; the live foreign-key comparison did not run.');
        }
        $created = $this->created();
        $list = "'" . implode("','", $created['tables']) . "'";
        [$code, $out] = self::cli(
            "SELECT CONCAT(table_name,'.',constraint_name) FROM information_schema.referential_constraints "
            . "WHERE constraint_schema = 'unisolu' AND table_name IN ($list) ORDER BY 1",
            null,
            null,
            true
        );
        $this->assertSame(0, $code, 'live database not reachable');
        $live = array_values(array_filter(array_map('trim', explode("\n", $out))));

        $this->assertSame($live, $this->foreignKeysInFile());
    }

    /** @return string[] "table.constraint" for each foreign key in the file, sorted */
    private function foreignKeysInFile(): array
    {
        $inFile = [];
        foreach ($this->statements() as $s) {
            if (preg_match('/^CREATE TABLE IF NOT EXISTS `?(\w+)`?/i', $s, $t)) {
                preg_match_all('/CONSTRAINT\s+`?(\w+)`?\s+FOREIGN KEY/i', $s, $c);
                foreach ($c[1] as $name) {
                    $inFile[] = $t[1] . '.' . $name;
                }
                $this->assertSame(
                    count($c[1]),
                    preg_match_all('/FOREIGN KEY/i', $s),
                    'a FOREIGN KEY without a CONSTRAINT name in ' . $t[1]
                );
            }
        }
        sort($inFile);
        return $inFile;
    }

    public function testTheOnlyForeignKeyIsGroupMembership(): void
    {
        $this->assertSame(['user_group_member.user_group_member_ibfk_1'], $this->foreignKeysInFile());
    }

    private function normalise(string $createSql): string
    {
        $createSql = preg_replace('/ AUTO_INCREMENT=\d+/', '', $createSql);
        $createSql = preg_replace('/DEFINER=`[^`]*`@`[^`]*` /', '', $createSql);
        return trim($createSql);
    }

    private function showCreate(string $db, string $name): string
    {
        [$code, $out] = self::cli("SHOW CREATE TABLE `$name`", $db, null, true);
        $this->assertSame(0, $code, "SHOW CREATE $name in $db failed");
        $text = substr($out, strpos($out, "\t") + 1);
        if (preg_match('/^CREATE .*?\bVIEW\b/s', $text)) {
            // A view's row ends with the client charset and collation, which follow the database, not the view.
            $text = preg_replace('/\t[^\t\n]*\t[^\t\n]*\s*$/', '', $text);
        }
        return $this->normalise($text);
    }

    /** Creates the throwaway database, or skips the test when the account cannot. */
    private function freshDatabase(): string
    {
        if (!self::$canCreateDatabase) {
            $this->markTestSkipped('The database account cannot create ' . self::THROWAWAY . '; this check did not run.');
        }
        $this->coreSql();
        self::cli('DROP DATABASE IF EXISTS ' . self::THROWAWAY);
        [$code, , $err] = self::cli('CREATE DATABASE ' . self::THROWAWAY . ' DEFAULT CHARACTER SET utf8mb4');
        $this->assertSame(0, $code, $err);
        return self::THROWAWAY;
    }

    private function load(string $db): void
    {
        [$code, , $err] = self::cli('', $db, $this->coreSqlPath());
        $this->assertSame(0, $code, 'load failed: ' . $err);
    }

    private function one(string $db, string $query): string
    {
        return trim(self::cli($query, $db, null, true)[1]);
    }

    /** The partitions of `entity` in $db, in order. */
    private function partitions(string $db): string
    {
        return $this->one($db, "SELECT GROUP_CONCAT(partition_name ORDER BY partition_ordinal_position) FROM information_schema.partitions "
            . "WHERE table_schema = DATABASE() AND table_name = 'entity'");
    }

    /** What Entity::__construct does for a type it has not seen: insert the type, then add its partition. */
    private function addEntityType(string $db, string $label): int
    {
        [$code, , $err] = self::cli("INSERT INTO entity_type (label) VALUES ('$label')", $db);
        $this->assertSame(0, $code, $err);
        $id = (int) $this->one($db, "SELECT id FROM entity_type WHERE label = '$label'");
        [$code, , $err] = self::cli("ALTER TABLE entity ADD PARTITION (PARTITION e_type$id VALUES IN ($id))", $db);
        $this->assertSame(0, $code, "ADD PARTITION e_type$id failed: $err");
        return $id;
    }

    private function counter(string $db, string $table): string
    {
        return $this->one($db, "SELECT auto_increment FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = '$table'");
    }

    public function testLoadsTwiceIntoAnEmptyDatabase(): void
    {
        $db = $this->freshDatabase();
        try {
            $this->load($db);
            $afterOne = [$this->counter($db, 'entity_type'), $this->counter($db, 'oauth_provider')];
            $this->load($db);

            $this->assertSame("2\tevent", $this->one($db, 'SELECT id, label FROM entity_type'));
            $this->assertSame("datetime\nevent\ndetail\nip", $this->one($db, 'SELECT value FROM entity WHERE type = 2 AND id = 0 ORDER BY attr'));
            $this->assertSame("google\ngithub\ndiscord\ngitlab", $this->one($db, 'SELECT slug FROM oauth_provider ORDER BY sort_order'));
            $this->assertSame('1,2,3,4', $this->one($db, 'SELECT GROUP_CONCAT(id ORDER BY id) FROM oauth_provider'));
            $this->assertSame('0', $this->one($db, 'SELECT COUNT(*) FROM event_view'));
            $this->assertSame('e_type2', $this->partitions($db));
            // A repeat load takes no ids: the counters are where the first load left them.
            $this->assertSame($afterOne, [$this->counter($db, 'entity_type'), $this->counter($db, 'oauth_provider')]);
            // The first id a new type gets is the one a plain table hands out after a row with id 2
            // (the server may step ids by more than one).
            self::cli('CREATE TABLE ctl (id int NOT NULL AUTO_INCREMENT, l varchar(9), PRIMARY KEY (id)) ENGINE=MyISAM', $db);
            self::cli("INSERT INTO ctl (id, l) VALUES (2, 'a')", $db);
            self::cli("INSERT INTO ctl (l) VALUES ('b')", $db);
            $expected = $this->one($db, "SELECT id FROM ctl WHERE l = 'b'");
            self::cli("INSERT INTO entity_type (label) VALUES ('widget')", $db);
            $this->assertSame($expected, $this->one($db, "SELECT id FROM entity_type WHERE label = 'widget'"));
        } finally {
            self::cli("DROP DATABASE IF EXISTS $db");
        }
    }

    /** @return array<string, array{int}> */
    public static function loadCounts(): array
    {
        return ['after one load' => [1], 'after two loads' => [2]];
    }

    /**
     * @dataProvider loadCounts
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('loadCounts')]
    public function testASecondEntityTypeCanBeCreated(int $loads): void
    {
        $db = $this->freshDatabase();
        try {
            for ($i = 0; $i < $loads; $i++) {
                $this->load($db);
            }
            self::cli('CREATE TABLE ctl (id int NOT NULL AUTO_INCREMENT, l varchar(9), PRIMARY KEY (id)) ENGINE=MyISAM', $db);
            self::cli("INSERT INTO ctl (id, l) VALUES (2, 'a')", $db);
            self::cli("INSERT INTO ctl (l) VALUES ('b')", $db);
            $expected = (int) $this->one($db, "SELECT id FROM ctl WHERE l = 'b'");
            $id = $this->addEntityType($db, 'widget');
            $this->assertSame($expected, $id, 'the new type did not get the first id after 2');
            $this->assertSame("e_type2,e_type$id", $this->partitions($db));
        } finally {
            self::cli("DROP DATABASE IF EXISTS $db");
        }
    }

    public function testEventSeedGoesToTheEventLabelWhenIdTwoIsTaken(): void
    {
        $db = $this->freshDatabase();
        try {
            self::cli("CREATE TABLE entity_type (id int(11) NOT NULL AUTO_INCREMENT, label varchar(255) NOT NULL, PRIMARY KEY (id), UNIQUE KEY label (label), KEY label_2 (label)) ENGINE=MyISAM DEFAULT CHARSET=utf8", $db);
            self::cli("INSERT INTO entity_type (id, label) VALUES (2, 'other')", $db);
            $this->load($db);
            $this->load($db);

            $this->assertSame("2\tother", $this->one($db, 'SELECT id, label FROM entity_type WHERE id = 2'));
            $eventId = (int) $this->one($db, "SELECT id FROM entity_type WHERE label = 'event'");
            $this->assertGreaterThan(2, $eventId);
            $this->assertSame('1', $this->one($db, "SELECT COUNT(*) FROM entity_type WHERE label = 'event'"));
            $this->assertSame("datetime\nevent\ndetail\nip", $this->one($db, "SELECT value FROM entity WHERE type = $eventId AND id = 0 ORDER BY attr"));
            $this->assertSame('0', $this->one($db, 'SELECT COUNT(*) FROM entity WHERE type = 2'), 'schema rows attached to the unrelated type');
            $this->assertSame("e_type2,e_type$eventId", $this->partitions($db));
            // event_view reads the event type's own id, never the unrelated type 2
            self::cli("INSERT INTO entity (type, id, attr, value) VALUES (2, 5, 1, 'rows of another type'), ($eventId, 6, 1, 'a real event')", $db);
            $this->assertSame('a real event', $this->one($db, 'SELECT event FROM event_view'));
        } finally {
            self::cli("DROP DATABASE IF EXISTS $db");
        }
    }

    public function testLoadsOntoADatabaseThatAlreadyHasSomeTables(): void
    {
        $db = $this->freshDatabase();
        try {
            self::cli('CREATE TABLE users (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, name VARCHAR(10), email VARCHAR(254) NOT NULL)', $db);
            self::cli("INSERT INTO users (name, email) VALUES ('keep', 'keep@example.test')", $db);
            $this->load($db);
            $this->assertStringContainsString('keep', $this->one($db, 'SELECT name FROM users'), 'an existing table lost its rows');
        } finally {
            self::cli("DROP DATABASE IF EXISTS $db");
        }
    }

    public function testEveryObjectMatchesTheLiveDefinition(): void
    {
        if (!$this->liveReference()) {
            $this->markTestSkipped('The reference database `unisolu` is not on this server; the comparison with the live definitions did not run.');
        }
        $db = $this->freshDatabase();
        try {
            $this->load($db);
            $created = $this->created();
            foreach (array_merge($created['tables'], $created['views']) as $name) {
                $fresh = $this->showCreate($db, $name);
                $live = $this->showCreate('unisolu', $name);
                if ($name === 'entity') {
                    // Only the live side loses its partition list: unisolu has one partition for each of its own entity types.
                    // The fresh side keeps its partitions in the comparison and is asserted on its own below.
                    $live = preg_replace('/\s+PARTITION BY.*$/s', '', $live);
                    $fresh = preg_replace('/\s+PARTITION BY.*$/s', '', $fresh);
                }
                $this->assertSame($live, $fresh, "$name differs from the live definition");
            }
            $this->assertSame('e_type2', $this->partitions($db));
        } finally {
            self::cli("DROP DATABASE IF EXISTS $db");
        }
    }
}
