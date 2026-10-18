<?php

use App\Enums\BillableType;
use App\Modules\Core\Models\Currency;
use App\Modules\Core\Support\Settings;
use App\Modules\Services\Enums\ServiceStatus;
use App\Modules\Services\Models\ModuleCommand;
use App\Modules\Services\Models\Service;
use App\Modules\Services\Models\ServiceAddon;
use App\Modules\Services\Models\UsageSnapshot;
use Tests\Fakes\Services\ServicesTestKit;

beforeEach(function () {
    Currency::factory()->create();
    $this->kit = ServicesTestKit::bind();
});

/**
 * An active service whose renewal for [due, due+1m) is invoiced and unpaid.
 */
function overdueService(string $due = '2026-11-15', array $attributes = []): Service
{
    $service = Service::factory()->dueOn($due)->onServer()->create($attributes);
    test()->artisan('automation:run', ['--date' => $due, '--only' => 'services.renewals'])->assertSuccessful();

    return $service->refresh();
}

function runTask(string $task, string $date): void
{
    test()->artisan('automation:run', ['--date' => $date, '--only' => $task, '--force' => true])->assertSuccessful();
}

test('a service overdue for the suspend threshold is suspended once with reason overdue', function () {
    $service = overdueService();

    runTask('services.suspensions', '2026-11-21');
    expect($service->refresh()->status)->toBe(ServiceStatus::Active);

    runTask('services.suspensions', '2026-11-22');
    runTask('services.suspensions', '2026-11-22');

    expect($service->refresh())->status->toBe(ServiceStatus::Suspended)->suspension_reason->toBe('overdue')
        ->and($this->kit->driver->callsFor('suspend'))->toHaveCount(1);
});

test('a cancelled overdue invoice never triggers suspension', function () {
    $service = overdueService();
    $this->kit->invoicer->cancel($this->kit->invoicer->lastInvoiceId());

    runTask('services.suspensions', '2026-12-01');

    expect($service->refresh()->status)->toBe(ServiceStatus::Active);
});

test('a service without an invoice for its due period is not suspended', function () {
    $service = Service::factory()->dueOn('2026-11-15')->onServer()->create();

    runTask('services.suspensions', '2026-12-01');

    expect($service->refresh()->status)->toBe(ServiceStatus::Active);
});

test('an exemption date blocks suspension and termination', function () {
    app(Settings::class)->set('services.terminate.enabled', true);
    $service = overdueService(attributes: ['suspend_exempt_until' => '2027-01-31']);

    runTask('services.suspensions', '2026-12-30');
    runTask('services.terminations', '2026-12-30');

    expect($service->refresh()->status)->toBe(ServiceStatus::Active);
});

test('a per-service suspend override wins over the setting', function () {
    $service = overdueService(attributes: ['suspend_days_override' => 2]);

    runTask('services.suspensions', '2026-11-17');

    expect($service->refresh()->status)->toBe(ServiceStatus::Suspended);
});

test('suspension can be disabled', function () {
    app(Settings::class)->set('services.suspend.enabled', false);
    $service = overdueService();

    runTask('services.suspensions', '2026-12-01');

    expect($service->refresh()->status)->toBe(ServiceStatus::Active);
});

test('termination is off by default', function () {
    $service = overdueService();

    runTask('services.terminations', '2027-03-01');

    expect($service->refresh()->status)->toBe(ServiceStatus::Active)
        ->and($this->kit->driver->callsFor('terminate'))->toBeEmpty();
});

test('when enabled, services overdue past the terminate threshold are terminated', function () {
    app(Settings::class)->set('services.terminate.enabled', true);
    $service = overdueService();
    runTask('services.suspensions', '2026-11-22');

    runTask('services.terminations', '2026-12-14');
    expect($service->refresh()->status)->toBe(ServiceStatus::Suspended);

    runTask('services.terminations', '2026-12-15');
    expect($service->refresh()->status)->toBe(ServiceStatus::Terminated);
});

test('an overdue addon with suspend-parent suspends itself and its parent', function () {
    $service = Service::factory()->dueOn('2027-01-15')->onServer()->create();
    $addon = ServiceAddon::factory()->dueOn('2026-11-15')->create(['service_id' => $service->id, 'suspend_parent_on_overdue' => true]);
    runTask('services.renewals', '2026-11-15');
    expect($this->kit->invoicer->invoicesFor(BillableType::ServiceAddon, $addon->id))->toHaveCount(1);

    runTask('services.suspensions', '2026-11-22');

    expect($addon->refresh())->status->toBe(ServiceStatus::Suspended)->suspension_reason->toBe('overdue')
        ->and($service->refresh())->status->toBe(ServiceStatus::Suspended)->suspension_reason->toBe('addon_overdue');

    $this->kit->invoicer->pay($this->kit->invoicer->lastInvoiceId());

    expect($addon->refresh()->status)->toBe(ServiceStatus::Active)
        ->and($service->refresh()->status)->toBe(ServiceStatus::Active);
});

test('usage sync stores one snapshot per service per day', function () {
    $service = Service::factory()->onServer()->create();
    $this->kit->driver->rememberAccount((int) $service->server_id, (string) $service->username);

    runTask('services.usage-sync', '2026-11-01');
    runTask('services.usage-sync', '2026-11-01');

    expect(UsageSnapshot::query()->where('service_id', $service->id)->count())->toBe(1);
});

test('re-running usage sync for an earlier date does not add a second snapshot for that day', function () {
    $service = Service::factory()->onServer()->create();
    $this->kit->driver->rememberAccount((int) $service->server_id, (string) $service->username);

    runTask('services.usage-sync', '2026-11-01');
    runTask('services.usage-sync', '2026-11-02');
    runTask('services.usage-sync', '2026-11-01');

    expect(UsageSnapshot::query()->where('service_id', $service->id)->count())->toBe(2);
});

test('the module log is pruned after the retention period', function () {
    $service = Service::factory()->create();
    ModuleCommand::query()->create(['service_id' => $service->id, 'driver' => 'log', 'command' => 'create', 'initiator' => 'system', 'success' => true, 'created_at' => '2026-09-01 10:00:00']);
    ModuleCommand::query()->create(['service_id' => $service->id, 'driver' => 'log', 'command' => 'suspend', 'initiator' => 'system', 'success' => true, 'created_at' => '2026-10-20 10:00:00']);

    runTask('services.module-log-prune', '2026-11-01');

    expect(ModuleCommand::query()->pluck('command')->all())->toBe(['suspend']);
});
