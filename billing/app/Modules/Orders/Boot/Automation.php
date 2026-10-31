<?php

namespace App\Modules\Orders\Boot;

use App\Modules\Core\Support\Settings;
use App\Modules\Orders\Actions\CancelStalePendingOrders;
use App\Modules\Orders\Actions\PruneExpiredCarts;
use App\Support\Automation\Automation as AutomationRegistry;
use App\Support\CalendarDate;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Container\Container;

final class Automation
{
    public function __invoke(Container $app): void
    {
        $automation = $app->make(AutomationRegistry::class);
        // Resolve the action (and its Billing dependency) only when auto-cancel is switched on.
        $automation->task('orders.cancel_stale_pending', AutomationRegistry::CANCELLATIONS, fn (CarbonImmutable $today): int => (int) $app->make(Settings::class)->get('orders.auto_cancel_unpaid_days', 0) > 0
            ? $app->make(CancelStalePendingOrders::class)->handle($today)
            : 0, label: 'Cancel stale unpaid orders', description: 'Cancels pending orders whose invoice is still unpaid after the configured number of days.');
        $automation->task('orders.prune_carts', AutomationRegistry::HOUSEKEEPING, fn (CalendarDate $today): int => $app->make(PruneExpiredCarts::class)->handle(), label: 'Delete expired carts', description: 'Removes shopping carts past their expiry.');
    }
}
