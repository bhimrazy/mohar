<?php

namespace App\Modules\Core\Support;

use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Index changes that are safe on large, busy tables (PostgreSQL and SQLite, ADR 0006).
 *
 * On PostgreSQL the index is built and dropped CONCURRENTLY, which takes no lock that blocks writes. That cannot
 * run inside a transaction, so the calling migration must set `public $withinTransaction = false;`; inside a
 * transaction this throws instead of silently taking a write-blocking lock (pass `allowLocking: true` only for a
 * table known to be small, or in tests). An existing index of the same name is reused only if it is on the same
 * table with the same columns and uniqueness; anything else throws. An INVALID index left by an interrupted
 * concurrent build is dropped and rebuilt. SQLite gets the same statements without CONCURRENTLY.
 *
 *   OnlineIndex::create('orders', ['client_id', 'id']);                                  // orders_client_id_id_index
 *   OnlineIndex::create('tld_prices', ['tld_id', 'currency', 'years'], 'tld_prices_default_price_unique', unique: true, where: 'client_group_id is null');
 *   OnlineIndex::create('clients', [], 'clients_email_lower_index', expression: 'lower(email)');
 */
final class OnlineIndex
{
    /** PostgreSQL truncates longer identifiers, after which lookups by the full name no longer find the index. */
    public const MAX_NAME_LENGTH = 63;

    /**
     * @param  list<literal-string>  $columns  column names written in code; ignored when $expression is given
     * @param  literal-string|null  $where  partial-index predicate written in code
     * @param  literal-string|null  $expression  expression to index, e.g. "lower(email)"
     */
    public static function create(string $table, array $columns, ?string $name = null, bool $unique = false, ?string $where = null, ?string $expression = null, bool $allowLocking = false): void
    {
        $name ??= self::defaultName($table, $columns, $unique);
        $driver = self::driver($name);
        self::assertNameLength($name);
        $concurrently = self::concurrently($name, $allowLocking);

        if ($driver === 'pgsql') {
            self::dropIfInvalid($name, $concurrently);
        }

        $existing = self::existing($name);

        if ($existing !== null) {
            $expected = ['table' => $table, 'columns' => $expression !== null ? null : $columns, 'unique' => $unique];

            if ($existing['table'] !== $table || $existing['unique'] !== $unique || ($expected['columns'] !== null && $existing['columns'] !== $columns)) {
                throw new LogicException(sprintf(
                    'Index %s already exists with a different definition (%s%s (%s)); expected %s%s (%s).',
                    $name, $existing['unique'] ? 'unique on ' : 'on ', $existing['table'], implode(', ', $existing['columns']),
                    $unique ? 'unique on ' : 'on ', $table, $expression ?? implode(', ', $columns),
                ));
            }

            return;
        }

        $grammar = DB::connection()->getQueryGrammar();
        $target = $expression ?? implode(', ', array_map(fn (string $column): string => $grammar->wrap($column), $columns));

        DB::statement(sprintf(
            'create %sindex%s %s on %s (%s)%s',
            $unique ? 'unique ' : '',
            $concurrently ? ' concurrently' : '',
            $grammar->wrap($name),
            $grammar->wrapTable($table),
            $target,
            $where !== null ? ' where '.$where : '',
        ));
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
        DB::statement(sprintf('drop index%s %s', $concurrently ? ' concurrently' : '', DB::connection()->getQueryGrammar()->wrap($name)));
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
     * @return array{table: string, columns: list<string>, unique: bool}|null
     */
    private static function existing(string $name): ?array
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            $row = DB::selectOne(
                'select t.relname as table_name, i.indisunique as is_unique,
                    array_to_string(array(select coalesce(a.attname, \'\') from unnest(i.indkey) with ordinality k(attnum, n)
                        left join pg_attribute a on a.attrelid = i.indrelid and a.attnum = k.attnum order by k.n), \',\') as columns
                 from pg_index i join pg_class c on c.oid = i.indexrelid join pg_class t on t.oid = i.indrelid
                 where c.relname = ? and pg_table_is_visible(c.oid)',
                [$name],
            );

            return $row === null ? null : ['table' => (string) $row->table_name, 'columns' => self::columnList((string) $row->columns), 'unique' => (bool) $row->is_unique];
        }

        $row = DB::selectOne("select tbl_name from sqlite_master where type = 'index' and name = ?", [$name]);

        if ($row === null) {
            return null;
        }

        $table = (string) $row->tbl_name;
        $columns = array_map(fn (object $column): string => (string) ($column->name ?? ''), DB::select('select name from pragma_index_info(?) order by seqno', [$name]));
        $flags = DB::selectOne('select "unique" as is_unique from pragma_index_list(?) where name = ?', [$table, $name]);
        $unique = $flags !== null && (bool) $flags->is_unique;

        return ['table' => $table, 'columns' => array_values($columns), 'unique' => $unique];
    }

    /**
     * @return list<string>
     */
    private static function columnList(string $columns): array
    {
        return $columns === '' ? [] : explode(',', $columns);
    }

    private static function dropIfInvalid(string $name, bool $concurrently): void
    {
        $invalid = DB::selectOne(
            'select 1 as invalid from pg_index i join pg_class c on c.oid = i.indexrelid where c.relname = ? and not i.indisvalid and pg_table_is_visible(c.oid)',
            [$name],
        );

        if ($invalid !== null) {
            DB::statement(sprintf('drop index%s if exists %s', $concurrently ? ' concurrently' : '', DB::connection()->getQueryGrammar()->wrap($name)));
        }
    }
}
