<?php

namespace App\Modules\Services\Actions;

use App\Contracts\Core\CompanyClock;
use App\Modules\Core\Support\ChunkedDelete;
use App\Modules\Services\Models\UsageSnapshot;
use App\Modules\Services\Support\ServiceSettings;
use App\Support\CalendarDate;
use Illuminate\Database\Query\Builder;

/**
 * Automation task (HOUSEKEEPING): deletes usage snapshots older than services.usage.retention_days (#49). A service's
 * latest snapshot is kept however old it is, because the service pages show it. Reads
 * service_usage_snapshots_captured_at_index, and service_usage_snapshots_service_id_captured_at_index for the newer
 * snapshot; deletes at most ChunkedDelete::LIMIT rows a run.
 */
class PruneUsageSnapshots
{
    public function __construct(private ServiceSettings $settings, private CompanyClock $clock) {}

    public function handle(CalendarDate $today): int
    {
        $cutoff = $this->clock->dayBounds($today->addDays(-$this->settings->usageRetentionDays()))[0];

        return ChunkedDelete::run(UsageSnapshot::query()
            ->where('captured_at', '<', $cutoff)
            ->whereExists(fn (Builder $newer) => $newer->selectRaw('1')
                ->from('service_usage_snapshots as newer')
                ->whereColumn('newer.service_id', 'service_usage_snapshots.service_id')
                ->whereColumn('newer.captured_at', '>', 'service_usage_snapshots.captured_at')));
    }
}
