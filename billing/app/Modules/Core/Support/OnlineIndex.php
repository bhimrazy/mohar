<?php

namespace App\Modules\Core\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;

/**
 * Index changes that are safe on large, busy tables. On PostgreSQL the index is built CONCURRENTLY (no lock that
 * blocks writes), so the calling migration must set `public $withinTransaction = false;`. Creation is idempotent
 * (IF NOT EXISTS), and an INVALID index left behind by an interrupted concurrent build is dropped and rebuilt.
 * SQLite gets the same statement without CONCURRENTLY; other drivers fall back to the schema builder.
 *
 *   OnlineIndex::create('orders', ['client_id', 'id']);                                  // orders_client_id_id_index
 *   OnlineIndex::create('tld_prices', ['tld_id', 'currency', 'years'], 'tld_prices_default_unique', unique: true, where: 'client_group_id is null');
 *   OnlineIndex::create('clients', [], 'clients_email_lower_index', expression: 'lower(email)');
 */
final class OnlineIndex
{
    /**
     * @param  list<literal-string>  $columns  column names written in code; ignored when $expression is given
     * @param  literal-string|null  $where  partial-index predicate written in code (PostgreSQL and SQLite only)
     * @param  literal-string|null  $expression  expression to index, e.g. "lower(email)" (PostgreSQL and SQLite only)
     */
    public static function create(string $table, array $columns, ?string $name = null, bool $unique = false, ?string $where = null, ?string $expression = null): void
    {
        $name ??= self::defaultName($table, $columns, $unique);
        $driver = DB::connection()->getDriverName();

        if (! in_array($driver, ['pgsql', 'sqlite'], true)) {
            if ($where !== null || $expression !== null) {
                throw new LogicException("Partial and expression indexes need PostgreSQL or SQLite ({$name}).");
            }

            if (! Schema::hasIndex($table, $name)) {
                Schema::table($table, fn ($blueprint) => $unique ? $blueprint->unique($columns, $name) : $blueprint->index($columns, $name));
            }

            return;
        }

        $grammar = DB::connection()->getQueryGrammar();
        $target = $expression ?? implode(', ', array_map(fn (string $column): string => $grammar->wrap($column), $columns));
        $concurrently = self::concurrent() ? ' concurrently' : '';

        if ($driver === 'pgsql') {
            self::dropIfInvalid($name);
        }

        DB::statement(sprintf(
            'create %sindex%s if not exists %s on %s (%s)%s',
            $unique ? 'unique ' : '',
            $concurrently,
            $grammar->wrap($name),
            $grammar->wrapTable($table),
            $target,
            $where !== null ? ' where '.$where : '',
        ));
    }

    /**
     * @param  list<literal-string>|string  $columnsOrName  the indexed columns (default name) or the index name
     */
    public static function drop(string $table, array|string $columnsOrName, bool $unique = false): void
    {
        $name = is_array($columnsOrName) ? self::defaultName($table, $columnsOrName, $unique) : $columnsOrName;
        $driver = DB::connection()->getDriverName();

        if (! in_array($driver, ['pgsql', 'sqlite'], true)) {
            if (Schema::hasIndex($table, $name)) {
                Schema::table($table, fn ($blueprint) => $unique ? $blueprint->dropUnique($name) : $blueprint->dropIndex($name));
            }

            return;
        }

        $concurrently = self::concurrent() ? ' concurrently' : '';

        DB::statement(sprintf('drop index%s if exists %s', $concurrently, DB::connection()->getQueryGrammar()->wrap($name)));
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

    /**
     * CONCURRENTLY is PostgreSQL-only and cannot run inside a transaction block.
     */
    private static function concurrent(): bool
    {
        return DB::connection()->getDriverName() === 'pgsql' && DB::transactionLevel() === 0;
    }

    private static function dropIfInvalid(string $name): void
    {
        $invalid = DB::selectOne(
            'select 1 as invalid from pg_index i join pg_class c on c.oid = i.indexrelid where c.relname = ? and not i.indisvalid and pg_table_is_visible(c.oid)',
            [$name],
        );

        if ($invalid !== null) {
            DB::statement(sprintf('drop index%s if exists %s', self::concurrent() ? ' concurrently' : '', DB::connection()->getQueryGrammar()->wrap($name)));
        }
    }
}
