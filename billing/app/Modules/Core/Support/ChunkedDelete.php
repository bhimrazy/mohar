<?php

namespace App\Modules\Core\Support;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\Log;

/**
 * Delete the rows a query matches in batches of primary keys, each its own short statement. Retention pruning
 * of log tables can match millions of rows the first time it runs; a single DELETE would hold its locks and
 * write the whole change to the WAL in one transaction. Hard deletes only: no model events, no soft deletes.
 * Each DELETE repeats the query's own conditions, so a row that stopped matching after its id was selected
 * (an extended cart, say) is kept. Single-table queries only (no joins); the key column is unqualified.
 * One call deletes at most $limit rows (a daily task works through a backlog over several runs) and logs the
 * table and count when it deletes anything.
 *
 *   ChunkedDelete::run(ModuleCommand::query()->where('created_at', '<', $cutoff));
 */
final class ChunkedDelete
{
    public const BATCH = 5000;

    /** Rows one call deletes at most. */
    public const LIMIT = 100_000;

    /**
     * @param  EloquentBuilder<*>|QueryBuilder  $query
     * @param  string  $key  primary key column of a query-builder query (Eloquent queries use the model's key)
     */
    public static function run(EloquentBuilder|QueryBuilder $query, int $batch = self::BATCH, string $key = 'id', int $limit = self::LIMIT): int
    {
        $key = $query instanceof EloquentBuilder ? $query->getModel()->getKeyName() : $key;
        $base = $query instanceof EloquentBuilder ? $query->toBase() : $query;
        $deleted = 0;

        while ($deleted < $limit) {
            $size = min($batch, $limit - $deleted);
            $ids = (clone $base)->reorder()->orderBy($key)->limit($size)->pluck($key)->all();

            if ($ids === []) {
                break;
            }

            $deleted += (clone $base)->reorder()->whereIn($key, $ids)->delete();

            if (count($ids) < $size) {
                break;
            }
        }

        if ($deleted > 0) {
            $table = $base->from;
            Log::info('Retention: rows deleted.', ['table' => is_string($table) ? $table : null, 'deleted' => $deleted, 'limit_reached' => $deleted >= $limit]);
        }

        return $deleted;
    }
}
