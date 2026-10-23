<?php

use App\Support\Automation\Automation;
use App\Support\CalendarDate;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Registered tasks that still take the run date as Carbon, by key. The list may only shrink: a module types its tasks
 * `CalendarDate $today` when it moves onto the company clock and removes its keys here.
 */
const CARBON_DATE_TASK_BASELINE = [
    'billing.auto_cancel',
    'billing.auto_capture',
    'billing.housekeeping',
    'billing.late_fees',
    'billing.payment_reconciliation',
    'billing.reminders',
    'kyc.prune_documents',
    'notifications.prune',
    'orders.cancel_stale_pending',
    'services.cancellations',
    'services.complete-limited',
    'services.import-prune',
    'services.module-log-prune',
    'services.renewals',
    'services.suspensions',
    'services.terminations',
    'services.upgrade-expiry',
    'services.usage-sync',
];

class CarbonRunDateHandlers
{
    public function viaMethod(CarbonImmutable $today): int
    {
        return 1;
    }
}

test('no registered automation task takes the run date as Carbon beyond the baseline', function () {
    $found = app(Automation::class)->carbonDateTasks();

    expect(array_values(array_diff($found, CARBON_DATE_TASK_BASELINE)))->toBe([], 'Type the run date of a new task as CalendarDate $today.')
        ->and(array_values(array_diff(CARBON_DATE_TASK_BASELINE, $found)))->toBe([], 'A Carbon-typed task is gone: remove it from CARBON_DATE_TASK_BASELINE.');
});

test('carbonDateTasks reads handlers by reflection, whatever the form of the handler', function () {
    $automation = new Automation;
    $variable = fn (CarbonImmutable $today): int => 1;

    $automation->task('t.variable', Automation::SYNC, $variable);
    $automation->task('t.method', Automation::SYNC, (new CarbonRunDateHandlers)->viaMethod(...));
    $automation->task('t.interface', Automation::SYNC, fn (CarbonInterface $today): int => 1);
    $automation->task('t.untyped', Automation::SYNC, fn ($today): int => 1);
    $automation->task('t.calendar', Automation::SYNC, fn (CalendarDate $today): int => 1);
    $automation->task('t.none', Automation::SYNC, fn (): int => 1);

});
