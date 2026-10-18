<?php

namespace App\Modules\Services\Actions;

use App\Modules\Services\Models\ModuleCommand;
use App\Modules\Services\Support\ServiceSettings;
use Carbon\CarbonImmutable;

/**
 * Automation task (HOUSEKEEPING): deletes module log entries older than the retention period.
 */
class PruneModuleLog
{
    public function __construct(private ServiceSettings $settings) {}

    public function handle(CarbonImmutable $today): int
    {
        return ModuleCommand::query()->where('created_at', '<', $today->subDays($this->settings->moduleLogRetentionDays()))->delete();
    }
}
