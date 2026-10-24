<?php

namespace App\Modules\Billing\Actions;

use App\Contracts\Core\CompanyClock;
use App\Modules\Billing\Models\GatewayLog;
use App\Modules\Core\Support\ChunkedDelete;
use App\Modules\Core\Support\Settings;
use App\Support\CalendarDate;

/**
 * Automation task (HOUSEKEEPING): deletes gateway log entries older than billing.gateways.log_retention_days (#49).
 * They hold provider request and response payloads; nothing references a log row. Reads gateway_log_created_at_index
 * and deletes at most ChunkedDelete::LIMIT rows a run.
 */
class PruneGatewayLog
{
    /** Shortest retention the task applies, whatever is stored. */
    public const MIN_DAYS = 90;

    public function __construct(private Settings $settings, private CompanyClock $clock) {}

    public function handle(CalendarDate $today): int
    {
        $days = max(self::MIN_DAYS, (int) $this->settings->get('billing.gateways.log_retention_days', 180));

        return ChunkedDelete::run(GatewayLog::query()->where('created_at', '<', $this->clock->dayBounds($today->addDays(-$days))[0]));
    }
}
