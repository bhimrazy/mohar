<?php

namespace App\Modules\Notifications\Console\Commands;

use App\Modules\Notifications\Actions\PruneNotificationLogs;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Manual entry point; the daily run is the "notifications.prune" housekeeping task in automation:run.
 */
#[Signature('notifications:prune')]
#[Description('Clear old email bodies and delete old webhook deliveries')]
class PruneNotifications extends Command
{
    public function handle(PruneNotificationLogs $prune): int
    {
        $this->components->info('Pruned '.$prune->handle(CarbonImmutable::today()).' records.');

        return self::SUCCESS;
    }
}
