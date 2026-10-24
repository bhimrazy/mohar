<?php

use App\Enums\BillableType;
use App\Events\Services\ServiceCancellationRequested;
use App\Modules\Core\Models\Currency;
use App\Modules\Core\Support\Settings;
use App\Modules\Services\Actions\RequestCancellation;
use App\Modules\Services\Actions\VoidCancellation;
use App\Modules\Services\Enums\CancellationStatus;
use App\Modules\Services\Enums\CancellationType;
use App\Modules\Services\Enums\ServiceStatus;
use App\Modules\Services\Exceptions\ServiceActionException;
use App\Modules\Services\Models\CancellationRequest;
use App\Modules\Services\Models\Service;
use App\Modules\Services\Models\ServiceAddon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Tests\Fakes\Services\ServicesTestKit;

beforeEach(function () {
    Currency::factory()->create();
    $this->kit = ServicesTestKit::bind();
});

function requestCancellation(Service $service, CancellationType $type, string $today): CancellationRequest
{
    return app(RequestCancellation::class)->handle($service, $type, 'Moving to another host', null, false, CarbonImmutable::parse($today));
}

test('an end of period request is processed on the due date and the service ends cancelled', function () {
    Event::fake([ServiceCancellationRequested::class]);
    $service = Service::factory()->dueOn('2026-12-01')->onServer()->create();
    $addon = ServiceAddon::factory()->create(['service_id' => $service->id]);
    $request = requestCancellation($service, CancellationType::EndOfPeriod, '2026-11-10');

    $this->artisan('automation:run', ['--date' => '2026-11-30', '--only' => 'services.cancellations'])->assertSuccessful();
    expect($service->refresh()->status)->toBe(ServiceStatus::Active);

    $this->artisan('automation:run', ['--date' => '2026-12-01', '--only' => 'services.cancellations'])->assertSuccessful();

    expect($service->refresh())->status->toBe(ServiceStatus::Cancelled)->do_not_renew->toBeTrue()
        ->and($request->refresh()->status)->toBe(CancellationStatus::Processed)
        ->and($addon->refresh()->status)->toBe(ServiceStatus::Cancelled)
        ->and($this->kit->driver->callsFor('terminate'))->toHaveCount(1);
    Event::assertDispatched(ServiceCancellationRequested::class, fn ($event) => $event->type === 'end_of_period' && $event->reason === 'Moving to another host');
});

test('submitting a request stops renewals and cancels unpaid renewal invoices of this service only', function () {
    $service = Service::factory()->dueOn('2026-11-15')->create();
    $other = Service::factory()->dueOn('2026-11-15')->create(['client_id' => $service->client_id]);
    $this->artisan('automation:run', ['--date' => '2026-11-01', '--only' => 'services.renewals'])->assertSuccessful();

    requestCancellation($service, CancellationType::EndOfPeriod, '2026-11-02');

    $statuses = fn (Service $item) => array_column($this->kit->invoicer->invoicesFor(BillableType::Service, $item->id), 'status');
    expect($statuses($service))->toBe(['cancelled'])
        ->and($statuses($other))->toBe(['unpaid'])
        ->and($service->refresh()->do_not_renew)->toBeTrue();
});

test('a renewal invoice Billing refuses to cancel stays open: its links and the renewal pointers are left alone', function () {
    $service = Service::factory()->dueOn('2026-11-15')->create();
    $this->artisan('automation:run', ['--date' => '2026-11-01', '--only' => 'services.renewals'])->assertSuccessful();
    $this->kit->invoicer->refuseCancel = true;

    requestCancellation($service, CancellationType::EndOfPeriod, '2026-11-02');

    expect(array_column($this->kit->invoicer->invoicesFor(BillableType::Service, $service->id), 'status'))->toBe(['unpaid']);
});

test('AC24: cancelling renewals cancels this service and its addons only, never another service of the client', function () {
    $service = Service::factory()->dueOn('2026-11-15')->create();
    $addon = ServiceAddon::factory()->dueOn('2026-11-15')->create(['service_id' => $service->id]);
    $other = Service::factory()->dueOn('2026-11-15')->create(['client_id' => $service->client_id]);
    $otherAddon = ServiceAddon::factory()->dueOn('2026-11-15')->create(['service_id' => $other->id]);
    $this->artisan('automation:run', ['--date' => '2026-11-01', '--only' => 'services.renewals'])->assertSuccessful();

    requestCancellation($service, CancellationType::EndOfPeriod, '2026-11-02');

    $status = fn (BillableType $type, int $id) => array_column($this->kit->invoicer->invoicesFor($type, $id), 'status');
    expect($status(BillableType::Service, $service->id))->toBe(['cancelled'])
        ->and($status(BillableType::ServiceAddon, $addon->id))->toBe(['cancelled'])
        ->and($status(BillableType::Service, $other->id))->toBe(['unpaid'])
        ->and($status(BillableType::ServiceAddon, $otherAddon->id))->toBe(['unpaid']);
});

test('only one open request per service is allowed', function () {
    $service = Service::factory()->create();
    requestCancellation($service, CancellationType::Immediate, '2026-11-02');

    expect(fn () => requestCancellation($service->refresh(), CancellationType::Immediate, '2026-11-02'))->toThrow(ServiceActionException::class);
});

test('a voided request resumes renewals and re-invoices the cancelled period', function () {
    $service = Service::factory()->dueOn('2026-11-15')->create();
    $this->artisan('automation:run', ['--date' => '2026-11-01', '--only' => 'services.renewals'])->assertSuccessful();
    $request = requestCancellation($service, CancellationType::EndOfPeriod, '2026-11-02');

    app(VoidCancellation::class)->handle($request);
    $this->artisan('automation:run', ['--date' => '2026-11-03', '--only' => 'services.renewals'])->assertSuccessful();

    $invoices = $this->kit->invoicer->invoicesFor(BillableType::Service, $service->id);
    expect($request->refresh()->status)->toBe(CancellationStatus::Voided)
        ->and($service->refresh()->do_not_renew)->toBeFalse()
        ->and(array_column($invoices, 'status'))->toBe(['cancelled', 'unpaid'])
        ->and(end($invoices)['draft']->lines[0]->periodStart->toDateString())->toBe('2026-11-15');
});

test('with auto processing off requests wait for staff approval', function () {
    app(Settings::class)->set('services.cancellation.auto_process', false);
    $service = Service::factory()->onServer()->create();
    $request = requestCancellation($service, CancellationType::Immediate, '2026-11-02');

    $this->artisan('automation:run', ['--date' => '2026-11-10', '--only' => 'services.cancellations'])->assertSuccessful();

    expect($service->refresh()->status)->toBe(ServiceStatus::Active)
        ->and($request->refresh()->status)->toBe(CancellationStatus::Open);
});

test('terminate at period end cancels the service on its due date', function () {
    $service = Service::factory()->dueOn('2026-12-01')->onServer()->create(['terminate_at_period_end' => true]);

    $this->artisan('automation:run', ['--date' => '2026-12-01', '--only' => 'services.cancellations'])->assertSuccessful();

    expect($service->refresh()->status)->toBe(ServiceStatus::Cancelled);
});

test('a failed termination marks the request failed and keeps the service', function () {
    $service = Service::factory()->onServer()->create();
    $request = requestCancellation($service, CancellationType::Immediate, today()->toDateString());
    $this->kit->driver->failNext('terminate');

    $this->artisan('automation:run', ['--date' => today()->toDateString(), '--only' => 'services.cancellations'])->assertSuccessful();

    expect($request->refresh()->status)->toBe(CancellationStatus::Failed)
        ->and($service->refresh()->status)->toBe(ServiceStatus::Active);
});
