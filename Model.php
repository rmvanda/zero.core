<?php
namespace Zero\Core;

/**
 * Thin typed row gateway over a real table.
 *
 * Deliberately NOT an ORM and deliberately NOT Zero\Entity — this is for models
 * with fixed columns and hand-written SQL, shared across modules. See
 * docs/superpowers/specs/2026-08-07-zero-model-user-migration-design.md
 *
 * One intentional incompatibility with Zero\Entity\Entity: findBy() returns a
 * single ?static, never an array. Entity::where() returns null|Entity|array
 * depending on match count, which entity/CLAUDE.md documents as a footgun.
 * Use allBy() when many rows are wanted.
 *
 * PORTABILITY: the generated SQL is plain ANSI — no backtick quoting (that is
 * a syntax error outside MySQL) and no driver-specific insert-id handling.
 * Zero\Core\Database builds DSNs for mysql/pgsql/sqlite, so a model should not
 * be the thing that pins us to one engine. Identifiers are safe unquoted
 * because assertColumn() whitelists every column name that reaches SQL; the
 * tradeoff is that a column named after a reserved word cannot be used.
 */
abstract class Model
{
    /** Backing table name. */
    protected static string $table;

    /** Primary key column. */
    protected static string $pk = 'id';

    /**
     * Writable column whitelist. Keys outside this list are never written and
     * never interpolated into SQL. The DDL owns id/created_at/updated_at, so
     * they are excluded here on purpose.
     */
    protected static array $columns = [];

    /** The loaded or staged row, column => value. */
    protected array $row = [];

    /** True when this instance corresponds to a row already in the table. */
    protected bool $exists = false;

    /** Columns staged for the next save(). */
    protected array $dirty = [];

    public function __construct(array $data = [], bool $exists = false)
    {
        $this->exists = $exists;

        if ($exists) {
            // Loaded from the database — every key is a real column, keep them all.
            $this->row = $data;
            return;
        }

        // Staged for insert. Keep ONLY what we will actually write: anything else
        // (created_at, updated_at, unknown keys) would leave the object reporting
        // a value the database never stored. save() fills in the pk after insert.
        $this->row   = array_intersect_key($data, array_flip(static::$columns));
        $this->dirty = array_values(array_intersect(static::$columns, array_keys($this->row)));
    }

    public function __get(string $key)
    {
        return $this->row[$key] ?? null;
    }

    public function __set(string $key, $value): void
    {
        // Silently ignore non-whitelisted columns rather than throwing: callers
        // routinely hand us a wider array than the table accepts.
        if (!in_array($key, static::$columns, true)) {
            return;
        }
        $this->row[$key] = $value;
        if (!in_array($key, $this->dirty, true)) {
            $this->dirty[] = $key;
        }
    }

    public function __isset(string $key): bool
    {
        return isset($this->row[$key]);
    }

    /** Load one row by primary key. */
    public static function find(int|string $id): ?static
    {
        return static::findBy(static::$pk, $id);
    }

    /** Load at most one row by any known column. */
    public static function findBy(string $column, $value): ?static
    {
        self::assertColumn($column);

        $stmt = Database::getConnection()->prepare(
            "SELECT * FROM " . static::$table . " WHERE {$column} = ? LIMIT 1"
        );
        $stmt->execute([$value]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : new static($row, true);
    }

    /** Load every row matching a column. */
    public static function allBy(string $column, $value): array
    {
        self::assertColumn($column);

        $stmt = Database::getConnection()->prepare(
            "SELECT * FROM " . static::$table . " WHERE {$column} = ?"
        );
        $stmt->execute([$value]);

        return array_map(
            fn(array $row) => new static($row, true),
            $stmt->fetchAll(\PDO::FETCH_ASSOC)
        );
    }

    /** Insert a new row and return the populated instance. */
    public static function create(array $data): static
    {
        $model = new static($data, false);
        $model->save();
        return $model;
    }

    /** Insert if new, update the staged columns if loaded. No-op when clean. */
    public function save(): void
    {
        $cols = array_values(array_intersect(static::$columns, $this->dirty));
        if (empty($cols)) {
            return;
        }

        $db     = Database::getConnection();
        $params = array_map(fn(string $c) => $this->row[$c] ?? null, $cols);

        if ($this->exists) {
            $set      = implode(', ', array_map(fn(string $c) => "{$c} = ?", $cols));
            $params[] = $this->row[static::$pk];
            $db->prepare(
                "UPDATE " . static::$table . " SET {$set} WHERE " . static::$pk . " = ?"
            )->execute($params);
        } else {
            $names        = implode(', ', $cols);
            $placeholders = implode(', ', array_fill(0, count($cols), '?'));
            $db->prepare(
                "INSERT INTO " . static::$table . " ({$names}) VALUES ({$placeholders})"
            )->execute($params);

            $this->row[static::$pk] = static::lastInsertId($db);
            $this->exists = true;
        }

        $this->dirty = [];
    }

    /**
     * Retrieve the id just inserted.
     *
     * Postgres needs the sequence name — a bare lastInsertId() there returns
     * whatever sequence the session last touched, which is not necessarily
     * ours. MySQL and SQLite both answer correctly with no argument.
     */
    protected static function lastInsertId(\PDO $db): int
    {
        if ($db->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'pgsql') {
            return (int) $db->lastInsertId(static::$table . '_' . static::$pk . '_seq');
        }
        return (int) $db->lastInsertId();
    }

    public function delete(): void
    {
        if (!$this->exists) {
            return;
        }
        Database::getConnection()->prepare(
            "DELETE FROM " . static::$table . " WHERE " . static::$pk . " = ?"
        )->execute([$this->row[static::$pk]]);

        $this->exists = false;
    }

    public function toArray(): array
    {
        return $this->row;
    }

    /**
     * True when a PDOException is a UNIQUE-constraint violation specifically, so
     * callers can turn a duplicate into a clean message instead of a 500.
     *
     * SQLSTATE 23000 is too coarse on its own: MySQL/MariaDB use it for every
     * integrity violation, including NOT NULL (1048) and foreign key (1452), so
     * the driver code has to disambiguate. PostgreSQL reports unique violations
     * under their own SQLSTATE (23505) and needs no driver code.
     */
    public static function isDuplicateKey(\PDOException $e): bool
    {
        if ($e->getCode() === '23505') {
            return true;                                    // pgsql unique_violation
        }
        return $e->getCode() === '23000'
            && (int) ($e->errorInfo[1] ?? 0) === 1062;      // mysql ER_DUP_ENTRY
    }

    /**
     * Guard every column name that reaches SQL. These are interpolated, not
     * bound — and since the generated SQL carries no identifier quoting, this
     * whitelist is the last line of defence. Never relax it.
     *
     * Called as self:: rather than static:: on purpose: it is private, and
     * under late static binding a subclass declaring its own assertColumn()
     * would otherwise be dispatched in its place and silently disarm the guard.
     *
     * The write paths (__set, the constructor, save()) enforce the same
     * whitelist independently via array_intersect/in_array against
     * static::$columns, so this is not the only enforcement point — but it is
     * the only one for caller-supplied column names on the read paths.
     */
    private static function assertColumn(string $column): void
    {
        if ($column !== static::$pk && !in_array($column, static::$columns, true)) {
            throw new \InvalidArgumentException(
                "Unknown column '{$column}' on " . static::class
            );
        }
    }
}
