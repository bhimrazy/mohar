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

    public static function viaStatic(CarbonImmutable $today): int
    {
        return 1;
    }

    public function calendar(CalendarDate $today): int
    {
        return 1;
    }

    public function __invoke(CarbonImmutable $today): int
    {
        return 1;
    }
}

test('no registered automation task takes the run date as Carbon beyond the baseline', function () {
    $automation = app(Automation::class);
    $found = $automation->carbonDateTasks();
    // A baselined task that is not registered (a module subset, MODULES=Billing) is neither new nor gone.
    $registered = array_keys($automation->ordered());
    $gone = array_values(array_diff(array_intersect(CARBON_DATE_TASK_BASELINE, $registered), $found));

    expect(array_values(array_diff($found, CARBON_DATE_TASK_BASELINE)))->toBe([], 'Type the run date of a new task as CalendarDate $today.')
        ->and($gone)->toBe([], 'A Carbon-typed task is gone: remove it from CARBON_DATE_TASK_BASELINE.');
});

test('carbonDateTasks reads handlers by reflection, whatever the form of the handler', function () {
    $automation = new Automation;
    $handlers = new CarbonRunDateHandlers;
    $variable = fn (CarbonImmutable $today): int => 1;

    $automation->task('t.variable', Automation::SYNC, $variable);
    $automation->task('t.method', Automation::SYNC, $handlers->viaMethod(...));
    $automation->task('t.array', Automation::SYNC, Closure::fromCallable([$handlers, 'viaMethod']));
    $automation->task('t.static', Automation::SYNC, CarbonRunDateHandlers::viaStatic(...));
    $automation->task('t.invokable', Automation::SYNC, Closure::fromCallable($handlers));
    $automation->task('t.function', Automation::SYNC, function (CarbonImmutable $today): int {
        return 1;
    });
    $automation->task('t.interface', Automation::SYNC, fn (CarbonInterface $today): int => 1);
    $automation->task('t.untyped', Automation::SYNC, fn ($today): int => 1);
    $automation->task('t.calendar', Automation::SYNC, fn (CalendarDate $today): int => 1);
    $automation->task('t.calendar_nullable', Automation::SYNC, fn (?CalendarDate $today): int => 1);
    $automation->task('t.calendar_method', Automation::SYNC, $handlers->calendar(...));
    $automation->task('t.none', Automation::SYNC, fn (): int => 1);
    $automation->task('t.none_static', Automation::SYNC, static fn (): int => 1);

    expect($automation->carbonDateTasks())->toBe(['t.array', 't.function', 't.interface', 't.invokable', 't.method', 't.static', 't.untyped', 't.variable']);
});

test('re-registering a key with a CalendarDate handler drops it from the Carbon list', function () {
    $automation = new Automation;
    $automation->task('t.a', Automation::SYNC, fn (CarbonImmutable $today): int => 1);
    $automation->task('t.a', Automation::SYNC, fn (CalendarDate $today): int => 1);

    expect($automation->carbonDateTasks())->toBe([]);
});
