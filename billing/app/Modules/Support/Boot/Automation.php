<?php

namespace App\Modules\Support\Boot;

use App\Contracts\Core\CompanyClock;
use App\Modules\Core\Support\RetentionRunDate;
use App\Modules\Support\Actions\AutoCloseTickets;
use App\Modules\Support\Actions\PruneAttachments;
use App\Modules\Support\Actions\PurgeInboundRaw;
use App\Support\Automation\Automation as AutomationRegistry;
use App\Support\CalendarDate;
use Illuminate\Contracts\Container\Container;

final class Automation
{
    public function __invoke(Container $app): void
    {
        $automation = $app->make(AutomationRegistry::class);
        $automation->task('support.auto_close', AutomationRegistry::HOUSEKEEPING, fn (CalendarDate $today): int => app(AutoCloseTickets::class)->handle($today->equals(app(CompanyClock::class)->calendarDate()) ? null : app(CompanyClock::class)->dayBounds($today)[1]), label: 'Close idle tickets', description: 'Closes tickets that have waited on the customer longer than the auto-close period.');
        $automation->task('support.purge_inbound_raw', AutomationRegistry::HOUSEKEEPING, fn (CalendarDate $today): int => app(PurgeInboundRaw::class)->handle(app(CompanyClock::class)->dayBounds(RetentionRunDate::of($today))[0]), label: 'Purge raw inbound emails', description: 'Deletes stored raw inbound email payloads older than the retention period.');
        $automation->task('support.prune_attachments', AutomationRegistry::HOUSEKEEPING, fn (CalendarDate $today): int => app(PruneAttachments::class)->handle(app(CompanyClock::class)->dayBounds(RetentionRunDate::of($today))[0]), label: 'Delete old ticket attachments', description: 'Deletes attachment files from tickets closed longer than the configured months (details stay).');
    }
}
