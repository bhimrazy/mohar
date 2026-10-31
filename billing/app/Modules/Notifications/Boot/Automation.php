<?php

namespace App\Modules\Notifications\Boot;

use App\Modules\Core\Support\RetentionRunDate;
use App\Modules\Notifications\Actions\PruneNotificationLogs;
use App\Support\Automation\Automation as AutomationRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Container\Container;

final class Automation
{
    public function __invoke(Container $app): void
    {
        $app->make(AutomationRegistry::class)->task('notifications.prune', AutomationRegistry::HOUSEKEEPING, fn (CarbonImmutable $today): int => app(PruneNotificationLogs::class)->handle(RetentionRunDate::ofCarbon($today)));
    }
}
