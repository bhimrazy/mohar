<?php

namespace App\Modules\Core\Support;

use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use LogicException;
use RuntimeException;
use Throwable;

/**
 * Index changes that are safe on large, busy tables (PostgreSQL and SQLite, ADR 0006).
 *
 * On PostgreSQL the index is built and dropped CONCURRENTLY, which takes no lock that blocks writes. That cannot
 * run inside a transaction, so the calling migration must set `public $withinTransaction = false;`; inside a
 * transaction this throws instead of silently taking a write-blocking lock (pass `allowLocking: true` only for a
 * table known to be small, or in tests). An existing index of the same name is reused only if it is on the same
 * table with the same columns, uniqueness and partial-index predicate (WHERE); anything else throws. An INVALID
 * index left by an interrupted concurrent build is dropped and rebuilt. SQLite gets the same statements without
 * CONCURRENTLY.
 *
 * Every build and drop runs under LockTimeout. A concurrent build waits for every transaction older than itself,
 * on any table, so it gets the longer `database.migrations.concurrent_lock_timeout` (60s: ordinary transactions
 * finish, a stuck one fails the build instead of hanging the deploy). A lock timeout there leaves an INVALID index:
 * the next attempt drops it first, and after the last failed attempt it is dropped too. An index another session is
 * still building is never dropped (dropIfInvalid()).
 *
 *   OnlineIndex::create('orders', ['client_id', 'id']);                                  // orders_client_id_id_index
 *   OnlineIndex::create('tld_prices', ['tld_id', 'currency', 'years'], 'tld_prices_default_price_unique', unique: true, where: 'client_group_id is null');
 *   OnlineIndex::create('clients', [], 'clients_email_lower_index', expression: 'lower(email)');
 *   OnlineIndex::trigram('invoices', 'number', 'invoices_number_trgm_index');                // PostgreSQL only
 */
final class OnlineIndex
{
    /** PostgreSQL truncates longer identifiers, after which lookups by the full name no longer find the index. */
    public const MAX_NAME_LENGTH = 63;

    public const CONCURRENT_ATTEMPTS = 3;

    /** A temporary copy of the table's columns, used to have PostgreSQL write a predicate in its own form. */
    private const PREDICATE_PROBE = 'online_index_predicate_probe';

    /**
     * @param  list<literal-string>  $columns  column names written in code; ignored when $expression is given
     * @param  literal-string|null  $where  partial-index predicate written in code
     * @param  literal-string|null  $expression  expression to index, e.g. "lower(email)"
     * @param  'gin'|null  $using  index method other than btree (PostgreSQL only)
     */
    public static function create(string $table, array $columns, ?string $name = null, bool $unique = false, ?string $where = null, ?string $expression = null, bool $allowLocking = false, ?string $using = null): void
    {
        $name ??= self::defaultName($table, $columns, $unique);
        $driver = self::driver($name);
        self::assertNameLength($name);

        if ($using !== null && $driver !== 'pgsql') {
            throw new LogicException("Index {$name} uses {$using}, which only PostgreSQL supports.");
        }

        $concurrently = self::concurrently($name, $allowLocking);

        $build = function () use ($table, $columns, $name, $unique, $where, $expression, $using, $driver, $concurrently): void {
            if ($driver === 'pgsql') {
                self::dropIfInvalid($table, $name, $concurrently);
            }

            $existing = self::existing($name);

            if ($existing !== null) {
                self::assertSameDefinition($existing, $table, $columns, $name, $unique, $where, $expression);

                return;
            }

            $grammar = DB::connection()->getQueryGrammar();
            $target = $expression ?? implode(', ', array_map(fn (string $column): string => $grammar->wrap($column), $columns));

            DB::statement(sprintf(
                'create %sindex%s %s on %s%s (%s)%s',
                $unique ? 'unique ' : '',
                $concurrently ? ' concurrently' : '',
                $grammar->wrap($name),
                $grammar->wrapTable($table),
                $using !== null ? ' using '.$using : '',
                $target,
                $where !== null ? ' where '.$where : '',
            ));
        };

        try {
            self::underLockTimeout($table, $concurrently, $build);
        } catch (Throwable $failure) {
            if ($concurrently) {
                self::dropLeftoverOf($table, $name, $failure);
            }

            throw $failure;
        }
    }

    /**
     * A trigram index (pg_trgm, GIN) that serves contains searches on PostgreSQL: `col::text ilike '%term%'` from
     * whereLike() and `lower(col) like '%term%'` from Like::where(), as long as $expression is what the query
     * searches. Creates the extension when it is missing (trusted since PostgreSQL 13: the database owner may create
     * it). A no-op on SQLite, where contains searches scan the table.
     *
     * @param  literal-string  $expression  the searched column or expression, e.g. "number" or "lower(email)"
     */
    public static function trigram(string $table, string $expression, string $name, bool $allowLocking = false): void
    {
        if (self::driver($name) !== 'pgsql') {
            return;
        }

        DB::statement('create extension if not exists pg_trgm');
        self::create($table, [], $name, expression: $expression.' gin_trgm_ops', allowLocking: $allowLocking, using: 'gin');
    }

    /**
     * Drop an index of this table; a no-op when it does not exist, an error when the name belongs to another table.
     *
     * @param  list<literal-string>|string  $columnsOrName  the indexed columns (default name) or the index name
     */
    public static function drop(string $table, array|string $columnsOrName, bool $unique = false, bool $allowLocking = false): void
    {
        $name = is_array($columnsOrName) ? self::defaultName($table, $columnsOrName, $unique) : $columnsOrName;
        self::driver($name);
        self::assertNameLength($name);
        $existing = self::existing($name);

        if ($existing === null) {
            return;
        }

        if ($existing['table'] !== $table) {
            throw new LogicException("Index {$name} belongs to table {$existing['table']}, not {$table}.");
        }

        $concurrently = self::concurrently($name, $allowLocking);
        self::underLockTimeout($table, $concurrently, fn () => DB::statement(sprintf('drop index%s %s', $concurrently ? ' concurrently' : '', DB::connection()->getQueryGrammar()->wrap($name))));
    }

    /**
     * Laravel's own index name, so `$table->dropIndex([...])` in a later migration still finds it.
     *
     * @param  list<string>  $columns
     */
    public static function defaultName(string $table, array $columns, bool $unique = false): string
    {
        return strtolower(str_replace(['-', '.'], '_', $table.'_'.implode('_', $columns).'_'.($unique ? 'unique' : 'index')));
    }

    private static function driver(string $name): string
    {
        $driver = DB::connection()->getDriverName();

        if (! in_array($driver, ['pgsql', 'sqlite'], true)) {
            throw new LogicException("OnlineIndex supports PostgreSQL and SQLite only (ADR 0006), not {$driver} ({$name}).");
        }

        return $driver;
    }

    private static function assertNameLength(string $name): void
    {
        if (strlen($name) > self::MAX_NAME_LENGTH) {
            throw new LogicException(sprintf('Index name %s is %d bytes; PostgreSQL allows %d. Pass a shorter name.', $name, strlen($name), self::MAX_NAME_LENGTH));
        }
    }

    /**
     * CONCURRENTLY is PostgreSQL-only and cannot run inside a transaction block.
     */
    private static function concurrently(string $name, bool $allowLocking): bool
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return false;
        }

        if (DB::transactionLevel() === 0) {
            return true;
        }

        if ($allowLocking) {
            return false;
        }

        throw new LogicException("Index {$name} would be built inside a transaction, which blocks writes to the table: set `public \$withinTransaction = false;` on the migration.");
    }

    /**
     * @return array{table: string, columns: list<string>, unique: bool, predicate: string|null}|null
     */
    private static function existing(string $name): ?array
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            $row = DB::selectOne(
                'select t.relname as table_name, i.indisunique as is_unique,
                    array_to_string(array(select coalesce(a.attname, \'\') from unnest(i.indkey) with ordinality k(attnum, n)
                        left join pg_attribute a on a.attrelid = i.indrelid and a.attnum = k.attnum order by k.n), \',\') as columns,
                    pg_get_expr(i.indpred, i.indrelid) as predicate
                 from pg_index i join pg_class c on c.oid = i.indexrelid join pg_class t on t.oid = i.indrelid
                 where c.relname = ? and pg_table_is_visible(c.oid)',
                [$name],
            );

            return $row === null ? null : [
                'table' => (string) $row->table_name,
                'columns' => self::columnList((string) $row->columns),
                'unique' => (bool) $row->is_unique,
                'predicate' => $row->predicate !== null ? (string) $row->predicate : null,
            ];
        }

        $row = DB::selectOne("select tbl_name, sql from sqlite_master where type = 'index' and name = ?", [$name]);

        if ($row === null) {
            return null;
        }

        $table = (string) $row->tbl_name;
        $columns = array_map(fn (object $column): string => (string) ($column->name ?? ''), DB::select('select name from pragma_index_info(?) order by seqno', [$name]));
        $flags = DB::selectOne('select "unique" as is_unique from pragma_index_list(?) where name = ?', [$table, $name]);
        $unique = $flags !== null && (bool) $flags->is_unique;

        // sqlite_master keeps the CREATE INDEX statement as written; indexes behind a UNIQUE constraint have none.
        $predicate = $row->sql !== null ? self::sqliteWhere((string) $row->sql) : null;

        return ['table' => $table, 'columns' => array_values($columns), 'unique' => $unique, 'predicate' => $predicate];
    }

    /**
     * @param  Closure(): mixed  $ddl
     */
    private static function underLockTimeout(string $table, bool $concurrently, Closure $ddl): void
    {
        if ($concurrently) {
            LockTimeout::run($table, $ddl, (string) config('database.migrations.concurrent_lock_timeout', '60s'), self::CONCURRENT_ATTEMPTS, transaction: false);

            return;
        }

        LockTimeout::run($table, $ddl);
    }

    /**
     * @param  array{table: string, columns: list<string>, unique: bool, predicate: string|null}  $existing
     * @param  list<string>  $columns
     */
    private static function assertSameDefinition(array $existing, string $table, array $columns, string $name, bool $unique, ?string $where, ?string $expression): void
    {
        if ($existing['table'] !== $table || $existing['unique'] !== $unique || ($expression === null && $existing['columns'] !== $columns)) {
            throw new LogicException(sprintf(
                'Index %s already exists with a different definition (%s%s (%s)); expected %s%s (%s).',
                $name, $existing['unique'] ? 'unique on ' : 'on ', $existing['table'], implode(', ', $existing['columns']),
                $unique ? 'unique on ' : 'on ', $table, $expression ?? implode(', ', $columns),
            ));
        }

        if (! self::samePredicate($table, $existing['predicate'], $where)) {
            throw new LogicException(sprintf(
                'Index %s already exists with a different predicate (%s); expected %s. An index is never reused with another WHERE: drop it in a migration of its own, or pick another name.',
                $name,
                $existing['predicate'] !== null ? 'where '.$existing['predicate'] : 'not partial',
                $where !== null ? 'where '.$where : 'not partial',
            ));
        }
    }

    private static function samePredicate(string $table, ?string $existing, ?string $expected): bool
    {
        if ($existing === null || $expected === null) {
            return $existing === $expected;
        }

        if (DB::connection()->getDriverName() === 'pgsql') {
            return self::pgsqlPredicate($table, $expected) === $existing;
        }

        return self::normalise($existing) === self::normalise($expected);
    }

    /**
     * The predicate as PostgreSQL stores it (casts added, IN turned into = ANY), so it compares with pg_get_expr()
     * exactly: build the same partial index on a temporary, empty copy of the table's columns and read it back.
     */
    private static function pgsqlPredicate(string $table, string $where): string
    {
        $probe = self::PREDICATE_PROBE;
        DB::statement("drop table if exists pg_temp.{$probe}");
        DB::statement(sprintf('create temporary table %s (like %s)', $probe, DB::connection()->getQueryGrammar()->wrapTable($table)));
        try {
            DB::statement(sprintf('create index %s_index on pg_temp.%s ((1)) where %s', $probe, $probe, self::withoutQualifier($where, $table)));
        } catch (QueryException $exception) {
            if (($exception->errorInfo[0] ?? $exception->getCode()) === '55P03') {
                throw $exception;
            }

            throw new LogicException("The predicate of index on {$table} could not be compared with the existing one ({$exception->errorInfo[2]}). Write it with unqualified columns or columns qualified by the table name only: where {$where}", 0, $exception);
        }

        $row = DB::selectOne("select pg_get_expr(i.indpred, i.indrelid) as predicate from pg_index i where i.indexrelid = 'pg_temp.{$probe}_index'::regclass");
        DB::statement("drop table pg_temp.{$probe}");

        return (string) $row?->predicate;
    }

    /**
     * The predicate with "table." qualifiers removed outside string literals: the probe copy has another name, and
     * PostgreSQL stores the predicate of a one-table index unqualified anyway.
     */
    private static function withoutQualifier(string $where, string $table): string
    {
        $parts = preg_split("/('(?:[^']|'')*')/", $where, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$where];
        $qualifier = '/(?<![\w."])"?'.preg_quote($table, '/').'"?\s*\.\s*(?=["\w])/i';

        foreach ($parts as $index => $part) {
            $parts[$index] = $index % 2 === 0 ? (string) preg_replace($qualifier, '', $part) : $part;
        }

        return implode('', $parts);
    }

    /**
     * The WHERE of a CREATE INDEX statement: the first "where" outside quotes and parentheses.
     */
    private static function sqliteWhere(string $sql): ?string
    {
        $depth = 0;
        $quote = null;
        $length = strlen($sql);

        for ($i = 0; $i < $length; $i++) {
            $character = $sql[$i];

            if ($quote !== null) {
                $quote = $character === $quote ? null : $quote;

                continue;
            }

            if (in_array($character, ["'", '"', '`', '['], true)) {
                $quote = $character === '[' ? ']' : $character;
            } elseif ($character === '(') {
                $depth++;
            } elseif ($character === ')') {
                $depth--;
            } elseif ($depth === 0 && $i > 0 && (ctype_space($sql[$i - 1]) || $sql[$i - 1] === ')') && preg_match('/\Gwhere\b/i', $sql, $match, 0, $i) === 1) {
                return trim(substr($sql, $i + 5));
            }
        }

        return null;
    }

    /**
     * Case, spacing, identifier quotes and enclosing parentheses do not change a predicate; string literals keep
     * their case.
     */
    private static function normalise(string $predicate): string
    {
        $parts = preg_split("/('(?:[^']|'')*')/", $predicate, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$predicate];

        foreach ($parts as $index => $part) {
            if ($index % 2 === 0) {
                $part = strtolower(str_replace(['"', '`'], '', $part));
                $part = (string) preg_replace('/\s+/', ' ', $part);
                $parts[$index] = (string) preg_replace('/\s*([(),=<>!+*\/-])\s*/', '$1', $part);
            }
        }

        $normalised = trim(implode('', $parts));

        while (str_starts_with($normalised, '(') && str_ends_with($normalised, ')') && self::enclosed($normalised)) {
            $normalised = trim(substr($normalised, 1, -1));
        }

        return str_replace('!=', '<>', $normalised);
    }

    /**
     * Whether the opening parenthesis at the start closes only at the very end, as in "(a or b)" but not "(a) or (b)".
     */
    private static function enclosed(string $predicate): bool
    {
        $depth = 0;
        $quoted = false;
        $last = strlen($predicate) - 1;

        for ($i = 0; $i <= $last; $i++) {
            $character = $predicate[$i];
            $quoted = $character === "'" ? ! $quoted : $quoted;

            if ($quoted) {
                continue;
            }

            $depth += match ($character) {
                '(' => 1,
                ')' => -1,
                default => 0,
            };

            if ($depth === 0 && $i < $last) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<string>
     */
    private static function columnList(string $columns): array
    {
        return $columns === '' ? [] : explode(',', $columns);
    }

    /**
     * Drop the INVALID index of $table that an interrupted concurrent build left, never one another session is still
     * building: such a build is INVALID too until its last step, and a DROP queued behind it would remove the index
     * once it has become valid. The order makes the check race-free. pg_index is read, then the build progress, then
     * pg_index again: a build running at the first read either still shows in the progress view, or finished before
     * it and the second read sees the index valid (a build commits indisvalid before it leaves the view). No new
     * build can start on the name while the index exists. A build in progress is waited for on its SHARE UPDATE
     * EXCLUSIVE lock, which blocks no reads or writes; lock_timeout bounds the wait, after which LockTimeout retries,
     * then fails naming the building session. An INVALID index of another table is left for existing() to report.
     *
     * @param  bool  $waitForBuilder  false: leave an index another session is building alone, without waiting
     */
    private static function dropIfInvalid(string $table, string $name, bool $concurrently, bool $waitForBuilder = true): void
    {
        while (($index = self::indexState($name)) !== null && ! $index['valid'] && $index['table'] === $table) {
            $builder = DB::selectOne(
                'select p.pid from pg_stat_progress_create_index p
                 where p.datid = (select oid from pg_database where datname = current_database())
                   and (p.index_relid = ? or p.relid = ?) and p.pid <> pg_backend_pid() limit 1',
                [$index['oid'], $index['table_oid']],
            );

            if ($builder !== null) {
                if (! $waitForBuilder) {
                    return;
                }

                DB::transaction(fn () => DB::statement(sprintf('lock table %s in share update exclusive mode', DB::connection()->getQueryGrammar()->wrapTable($table))));

                continue;
            }

            $again = self::indexState($name);

            if ($again !== null && $again['oid'] === $index['oid'] && ! $again['valid']) {
                DB::statement(sprintf('drop index%s if exists %s', $concurrently ? ' concurrently' : '', DB::connection()->getQueryGrammar()->wrap($name)));

                return;
            }
        }
    }

    /**
     * The index as the catalog shows it now; each call reads it again.
     *
     * @phpstan-impure
     *
     * @return array{oid: int, valid: bool, table: string, table_oid: int}|null
     */
    private static function indexState(string $name): ?array
    {
        $row = DB::selectOne(
            'select c.oid, i.indisvalid as valid, t.relname as table_name, t.oid as table_oid
             from pg_index i join pg_class c on c.oid = i.indexrelid join pg_class t on t.oid = i.indrelid
             where c.relname = ? and pg_table_is_visible(c.oid)',
            [$name],
        );

        return $row === null ? null : ['oid' => (int) $row->oid, 'valid' => (bool) $row->valid, 'table' => (string) $row->table_name, 'table_oid' => (int) $row->table_oid];
    }

    /**
     * After a failed concurrent build, drop the INVALID index it left: a ready one is maintained on every write, and a
     * unique one already rejects rows, while the deploy reports that nothing changed. One attempt under the concurrent
     * timeout; when that fails too, the error names the index to drop by hand.
     */
    private static function dropLeftoverOf(string $table, string $name, Throwable $failure): void
    {
        try {
            LockTimeout::run($table, fn () => self::dropIfInvalid($table, $name, true, waitForBuilder: false), (string) config('database.migrations.concurrent_lock_timeout', '60s'), 1, transaction: false);
        } catch (Throwable $cleanup) {
            throw new RuntimeException(sprintf(
                '%s The INVALID index %s that the failed build left could not be dropped (%s); drop it with DROP INDEX CONCURRENTLY %s before the old release meets it.',
                $failure->getMessage(), $name, $cleanup->getMessage(), $name,
            ), 0, $failure);
        }
    }
}
