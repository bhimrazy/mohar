<?php

namespace App\Modules\Core\Support;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * A derived table of constant rows (`select … union all select …`, every value bound) to join against, so a report
 * can group by points it computes in PHP while its SQL stays a literal string. Portable across PostgreSQL, SQLite
 * and MySQL; give PostgreSQL the column types it cannot infer from a bare parameter, e.g. `? + 0` for an integer and
 * `date(?)` for a date:
 *
 *   ConstantRows::of('? + 0 as point, date(?) as day', [[0, '2026-08-02'], [1, '2026-08-09']]);
 */
final class ConstantRows
{
    /**
     * @param  literal-string  $select  one row's columns, with a `?` per value
     * @param  list<list<int|string>>  $rows  the bound values of each row
     */
    public static function of(string $select, array $rows): Builder
    {
        if ($rows === []) {
            throw new InvalidArgumentException('ConstantRows needs at least one row.');
        }

        $query = DB::query()->selectRaw($select, array_shift($rows));

        foreach ($rows as $bindings) {
            $query->unionAll(DB::query()->selectRaw($select, $bindings));
        }

        return $query;
    }
}
