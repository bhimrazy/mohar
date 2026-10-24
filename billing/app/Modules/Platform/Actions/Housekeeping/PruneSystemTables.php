<?php

namespace App\Modules\Platform\Actions\Housekeeping;

use App\Contracts\Core\CompanyClock;
use App\Modules\Core\Models\AutomationRun;
use App\Modules\Core\Support\AutomationLogRetention;
use App\Modules\Core\Support\ChunkedDelete;
use App\Modules\Core\Support\Settings;
use App\Support\CalendarDate;
use Illuminate\Support\Facades\DB;

/**
 * Automation tasks (HOUSEKEEPING) for the application's own tables (#49): the automation log, failed queue jobs, job
 * batches and the database cache. Each deletes at most ChunkedDelete::LIMIT rows per table and run.
 */
class PruneSystemTables
{
    public const FAILED_JOBS_MIN_DAYS = 7;

    public function __construct(private Settings $settings, private CompanyClock $clock, private AutomationLogRetention $retention) {}

    /**
     * Automation runs of dates `automation:run` no longer accepts (AutomationLogRetention); each task's latest run is
     * kept, because the automation page shows it. The table is small: the plan is the primary key with a hashed
     * max(id) per task.
     */
    public function automationLog(CalendarDate $today): int
    {
        $oldest = $this->retention->oldestRunDate($today);
        $deleted = ChunkedDelete::run(AutomationRun::query()
            ->where('run_date', '<', $oldest->toString())
            ->whereNotIn('id', AutomationRun::query()->selectRaw('max(id)')->groupBy('task')));

        if ($deleted > 0) {
            $this->retention->markPrunedBefore($oldest);
        }

        return $deleted;
    }

    /**
     * Failed jobs, and finished or cancelled job batches, older than the retention period. Unfinished batches are
     * never deleted. Failed jobs read failed_jobs_failed_at_index; job_batches has no index on these columns, and
     * the application creates no batches today.
     */
    public function queueTables(CalendarDate $today): int
    {
        $days = max(self::FAILED_JOBS_MIN_DAYS, (int) $this->settings->get('platform.failed_jobs_retention_days', 30));
        $cutoff = $this->clock->dayBounds($today->addDays(-$days))[0];
        $deleted = 0;

        if (in_array(config('queue.failed.driver'), ['database', 'database-uuids'], true)) {
            $deleted += ChunkedDelete::run(DB::connection(config('queue.failed.database'))->table((string) config('queue.failed.table', 'failed_jobs'))
                ->where('failed_at', '<', $cutoff));
        }

        $deleted += ChunkedDelete::run(DB::connection(config('queue.batching.database'))->table((string) config('queue.batching.table', 'job_batches'))
            ->where(fn ($query) => $query
                ->where(fn ($query) => $query->whereNotNull('finished_at')->where('finished_at', '<', $cutoff->getTimestamp()))
                ->orWhere(fn ($query) => $query->whereNotNull('cancelled_at')->where('cancelled_at', '<', $cutoff->getTimestamp()))));

        return $deleted;
    }

    /**
     * Rows of the database cache store whose expiry has passed. The store already treats them as missing; it only
     * removes one when it is read again. Reads cache_expiration_index and cache_locks_expiration_index.
     */
    public function expiredCache(): int
    {
        $store = config('cache.stores.database');

        if (! is_array($store) || ($store['driver'] ?? null) !== 'database') {
            return 0;
        }

        $now = now()->getTimestamp();

        return ChunkedDelete::run(DB::connection($store['connection'] ?? null)->table($store['table'] ?? 'cache')->where('expiration', '<', $now), key: 'key')
            + ChunkedDelete::run(DB::connection($store['lock_connection'] ?? $store['connection'] ?? null)->table($store['lock_table'] ?? 'cache_locks')->where('expiration', '<', $now), key: 'key');
    }
}
