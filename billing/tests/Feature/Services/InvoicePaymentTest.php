<?php

use App\Contracts\Services\ServiceRegistry;
use App\Data\Billing\InvoiceLineData;
use App\Data\Services\AddonDraft;
use App\Data\Services\ServiceDraft;
use App\Enums\BillableType;
use App\Enums\BillingCycle;
use App\Events\Billing\InvoicePaid;
use App\Events\Orders\OrderPlaced;
use App\Events\Services\ServiceActivated;
use App\Events\Services\ServiceRenewed;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Currency;
use App\Modules\Core\Support\Settings;
use App\Modules\Services\Enums\InvoiceLinkStatus;
use App\Modules\Services\Enums\ServiceStatus;
use App\Modules\Services\Exceptions\InvalidStatusTransition;
use App\Modules\Services\Models\Server;
use App\Modules\Services\Models\Service;
use App\Modules\Services\Models\ServiceAddon;
use App\Modules\Services\Models\ServiceInvoice;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Tests\Fakes\Services\ServicesTestKit;

beforeEach(function () {
    Currency::factory()->create();
    $this->kit = ServicesTestKit::bind();
});

function renewalLine(Service $service, string $start, string $end, int $amount = 1000): InvoiceLineData
{
    return new InvoiceLineData('Renewal', $amount, 1, true, BillableType::Service, $service->id, CarbonImmutable::parse($start), CarbonImmutable::parse($end));
}

test('paying a renewal late moves next due to the period end, not the payment date', function () {
    Event::fake([ServiceRenewed::class]);
    $service = Service::factory()->dueOn('2026-11-15')->create();
    $this->artisan('automation:run', ['--date' => '2026-11-01', '--only' => 'services.renewals'])->assertSuccessful();
    $this->travelTo('2026-11-20');

    $this->kit->invoicer->pay($this->kit->invoicer->lastInvoiceId());

    expect($service->refresh()->next_due_date->toDateString())->toBe('2026-12-15');
    Event::assertDispatched(ServiceRenewed::class, fn (ServiceRenewed $event) => $event->nextDueDate === '2026-12-15');
});

test('a replayed InvoicePaid does not advance the due date twice', function () {
    $service = Service::factory()->dueOn('2026-11-15')->create();
    $event = new InvoicePaid(9001, $service->client_id, [renewalLine($service, '2026-11-15', '2026-12-15')]);

    event($event);
    event($event);

    expect($service->refresh()->next_due_date->toDateString())->toBe('2026-12-15')
        ->and(ServiceInvoice::query()->where('service_id', $service->id)->count())->toBe(1);
});

test('paying the newer of two open periods does not skip the older unpaid one', function () {
    $service = Service::factory()->dueOn('2026-11-15')->create();
    ServiceInvoice::query()->create(['service_id' => $service->id, 'invoice_id' => 9001, 'kind' => 'renewal', 'period_start' => '2026-11-15', 'period_end' => '2026-12-15', 'amount' => 1000, 'status' => 'open']);

    event(new InvoicePaid(9002, $service->client_id, [renewalLine($service, '2026-12-15', '2027-01-15')]));
    expect($service->refresh()->next_due_date->toDateString())->toBe('2026-11-15');

    event(new InvoicePaid(9001, $service->client_id, [renewalLine($service, '2026-11-15', '2026-12-15')]));
    expect($service->refresh()->next_due_date->toDateString())->toBe('2027-01-15');
});

test('setup fee lines never extend the due date', function () {
    $service = Service::factory()->dueOn('2026-11-15')->create();

    event(new InvoicePaid(9001, $service->client_id, [new InvoiceLineData('Setup', 500, 1, true, BillableType::ServiceSetup, $service->id, CarbonImmutable::parse('2026-11-15'), CarbonImmutable::parse('2026-12-15'))]));

    expect($service->refresh()->next_due_date->toDateString())->toBe('2026-11-15');
});

test('paying the order invoice provisions a pending service with on-payment auto setup', function () {
    Event::fake([ServiceActivated::class]);
    $this->kit->priceBook->addProduct(21, 'Starter', autoSetup: 'payment');
    Server::factory()->create(['is_default' => true]);
    $service = Service::factory()->pending()->create(['product_id' => 21]);

    event(new InvoicePaid(9001, $service->client_id, [new InvoiceLineData('Starter', 1000, 1, true, BillableType::Service, $service->id, $service->registration_date, $service->next_invoice_date)]));

    expect($service->refresh())->status->toBe(ServiceStatus::Active)
        ->next_due_date->toDateString()->toBe($service->next_invoice_date->toDateString());
    Event::assertDispatched(ServiceActivated::class);
});

test('pending services with manual or on-accept setup stay pending after payment', function (string $policy) {
    $this->kit->priceBook->addProduct(21, 'Starter', autoSetup: $policy);
    $service = Service::factory()->pending()->create(['product_id' => 21]);

    event(new InvoicePaid(9001, $service->client_id, [new InvoiceLineData('Starter', 1000, 1, true, BillableType::Service, $service->id)]));

    expect($service->refresh()->status)->toBe(ServiceStatus::Pending)
        ->and($this->kit->driver->callsFor('create'))->toBeEmpty();
})->with(['manual', 'accept', 'order']);

test('paying the overdue invoice unsuspends an overdue suspension but not a staff one', function () {
    $overdue = Service::factory()->dueOn('2026-11-15')->suspended('overdue')->onServer()->create();
    $staff = Service::factory()->dueOn('2026-11-15')->suspended('staff:abuse')->onServer()->create();
    $this->travelTo('2026-11-25');

    foreach ([$overdue, $staff] as $service) {
        event(new InvoicePaid(9000 + $service->id, $service->client_id, [renewalLine($service, '2026-11-15', '2026-12-15')]));
    }

    expect($overdue->refresh()->status)->toBe(ServiceStatus::Active)
        ->and($overdue->suspension_reason)->toBeNull()
        ->and($staff->refresh()->status)->toBe(ServiceStatus::Suspended);
});

test('unsuspend on payment can be turned off', function () {
    app(Settings::class)->set('services.unsuspend_on_payment', false);
    $service = Service::factory()->dueOn('2026-11-15')->suspended('overdue')->onServer()->create();

    event(new InvoicePaid(9001, $service->client_id, [renewalLine($service, '2026-11-15', '2026-12-15')]));

    expect($service->refresh()->status)->toBe(ServiceStatus::Suspended);
});

test('a paid addon line activates a pending addon and extends its due date', function () {
    $addon = ServiceAddon::factory()->dueOn('2026-11-15')->create(['status' => ServiceStatus::Pending]);

    event(new InvoicePaid(9001, $addon->service->client_id, [new InvoiceLineData('Backups', 300, 1, true, BillableType::ServiceAddon, $addon->id, CarbonImmutable::parse('2026-11-15'), CarbonImmutable::parse('2026-12-15'))]));

    expect($addon->refresh())->status->toBe(ServiceStatus::Active)
        ->next_due_date->toDateString()->toBe('2026-12-15');
});

test('the registry creates pending services with first-period dates and the order invoice is linked', function () {
    $this->travelTo('2026-10-17');
    $client = Client::factory()->create();
    $this->kit->priceBook->addProduct(31, 'Business Hosting');
    $registry = app(ServiceRegistry::class);

    $id = $registry->createPending(new ServiceDraft($client->id, 31, BillingCycle::Monthly, 'usd', 1500, 2000, CarbonImmutable::parse('2026-11-17'), orderId: 55, domain: 'Acme.Example', configOptions: [3 => 9], customFields: ['ssh' => 'key']));
    $addonId = $registry->createPendingAddon(new AddonDraft($id, 7, BillingCycle::Monthly, 300, 0, CarbonImmutable::parse('2026-11-17')));
    event(new OrderPlaced(55, $client->id, 8123, [['type' => 'service', 'id' => $id, 'product_id' => 31], ['type' => 'service_addon', 'id' => $addonId, 'product_id' => null]], null));

    $service = Service::query()->findOrFail($id);
    expect($service)
        ->status->toBe(ServiceStatus::Pending)
        ->product_name->toBe('Business Hosting')
        ->currency->toBe('USD')
        ->domain->toBe('acme.example')
        ->next_due_date->toDateString()->toBe('2026-10-17')
        ->next_invoice_date->toDateString()->toBe('2026-11-17')
        ->billing_anchor_day->toBe(17)
        ->custom_fields->toBe(['ssh' => 'key'])
        ->and(ServiceInvoice::query()->where('invoice_id', 8123)->where('status', InvoiceLinkStatus::Open->value)->count())->toBe(2)
        ->and(ServiceAddon::query()->findOrFail($addonId)->status)->toBe(ServiceStatus::Pending);
});

test('the registry provisions and cancels pending services', function () {
    $this->kit->priceBook->addProduct(31, 'Business Hosting');
    Server::factory()->create(['is_default' => true]);
    $registry = app(ServiceRegistry::class);
    $toProvision = Service::factory()->pending()->create(['product_id' => 31]);
    $toCancel = Service::factory()->pending()->create(['product_id' => 31]);
    $addon = ServiceAddon::factory()->create(['service_id' => $toCancel->id, 'status' => ServiceStatus::Pending]);

    $result = $registry->provision($toProvision->id);
    $registry->cancelPending($toCancel->id, 'fraud');

    expect($result->success)->toBeTrue()
        ->and($toProvision->refresh()->status)->toBe(ServiceStatus::Active)
        ->and($toCancel->refresh()->status)->toBe(ServiceStatus::Fraud)
        ->and($addon->refresh()->status)->toBe(ServiceStatus::Fraud)
        ->and($this->kit->driver->wasCalledFor($toCancel, 'terminate'))->toBeFalse();
});

test('the registry reports failed provisioning and refuses to cancel active services', function () {
    $this->kit->priceBook->addProduct(31, 'Business Hosting');
    Server::factory()->create(['is_default' => true]);
    $service = Service::factory()->pending()->create(['product_id' => 31]);
    $this->kit->driver->failNext('create', message: 'Quota exceeded');

    $result = app(ServiceRegistry::class)->provision($service->id);

    expect($result->success)->toBeFalse()->and($result->message)->toBe('Quota exceeded');
    expect(fn () => app(ServiceRegistry::class)->cancelPending(Service::factory()->create()->id))
        ->toThrow(InvalidStatusTransition::class);
});

test('a zero-total renewal paid on issue keeps one link and advances the due date', function () {
    $service = Service::factory()->dueOn('2026-11-15')->create(['recurring_amount' => 0]);

    $this->artisan('automation:run', ['--date' => '2026-11-01', '--only' => 'services.renewals'])->assertSuccessful();

    expect($service->refresh()->next_due_date->toDateString())->toBe('2026-12-15')
        ->and(ServiceInvoice::query()->where('service_id', $service->id)->count())->toBe(1)
        ->and(ServiceInvoice::query()->where('service_id', $service->id)->value('status'))->toBe(InvoiceLinkStatus::Paid);
});
