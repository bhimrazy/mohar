<?php

use App\Contracts\Services\ClientServiceDirectory;
use App\Contracts\Services\ProvisioningDrivers;
use App\Contracts\Services\ServiceRegistry;
use App\Data\Billing\InvoiceLineData;
use App\Data\Services\ServiceDraft;
use App\Enums\BillableType;
use App\Enums\BillingCycle;
use App\Events\Billing\InvoicePaid;
use App\Events\Orders\OrderPlaced;
use App\Events\Services\CancellationVoided;
use App\Events\Services\ProvisioningFailed;
use App\Events\Services\ServerCapacityExhausted;
use App\Events\Services\ServiceActivated;
use App\Events\Services\ServiceCancelled;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Currency;
use App\Modules\Core\Support\Settings;
use App\Modules\Services\Actions\ApplyServiceChange;
use App\Modules\Services\Actions\ExecuteModuleCommand;
use App\Modules\Services\Actions\OrderServiceChange;
use App\Modules\Services\Actions\ProcessCancellations;
use App\Modules\Services\Actions\RequestCancellation;
use App\Modules\Services\Actions\SetServiceStatus;
use App\Modules\Services\Actions\VoidCancellation;
use App\Modules\Services\Enums\CancellationStatus;
use App\Modules\Services\Enums\CancellationType;
use App\Modules\Services\Enums\InvoiceLinkStatus;
use App\Modules\Services\Enums\ServiceStatus;
use App\Modules\Services\Exceptions\RetryableProvisioningException;
use App\Modules\Services\Exceptions\ServiceActionException;
use App\Modules\Services\Jobs\RunModuleCommand;
use App\Modules\Services\Models\ModuleCommand;
use App\Modules\Services\Models\ModuleQueueItem;
use App\Modules\Services\Models\Server;
use App\Modules\Services\Models\ServerGroup;
use App\Modules\Services\Models\Service;
use App\Modules\Services\Models\ServiceAddon;
use App\Modules\Services\Models\ServiceInvoice;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\DevCommands;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\Fakes\Services\ServicesTestKit;

beforeEach(function () {
    Currency::factory()->create();
    $this->kit = ServicesTestKit::bind();
});

function periodLessLine(BillableType $type, int $id, int $amount = 1000): InvoiceLineData
{
    return new InvoiceLineData('Renewal', $amount, 1, true, $type, $id);
}

// Blocking 3: replay of lines without a period

test('replaying an InvoicePaid with period-less lines advances due dates only once', function () {
    $service = Service::factory()->dueOn('2026-11-15')->create();
    $addon = ServiceAddon::factory()->dueOn('2026-11-15')->create(['service_id' => $service->id]);
    $event = new InvoicePaid(9101, $service->client_id, [periodLessLine(BillableType::Service, $service->id), periodLessLine(BillableType::ServiceAddon, $addon->id, 300)]);

    event($event);
    event($event);
    event($event);

    expect($service->refresh()->next_due_date->toDateString())->toBe('2026-12-15')
        ->and($addon->refresh()->next_due_date->toDateString())->toBe('2026-12-15')
        ->and(ServiceInvoice::query()->where('invoice_id', 9101)->count())->toBe(2);
});

test('an order invoice paid before OrderPlaced arrives leaves no open link behind', function () {
    $service = Service::factory()->pending()->create();
    event(new InvoicePaid(9102, $service->client_id, [new InvoiceLineData('Starter', 1000, 1, true, BillableType::Service, $service->id, $service->registration_date, $service->next_invoice_date)]));

    event(new OrderPlaced(77, $service->client_id, 9102, [['type' => 'service', 'id' => $service->id, 'product_id' => $service->product_id]], null));

    expect(ServiceInvoice::query()->where('service_id', $service->id)->pluck('status')->all())->toBe([InvoiceLinkStatus::Paid]);
});

// Blocking 4: addon periods survive void / upgrade apply; partially paid invoices are never cancelled

test('a voided cancellation re-invoices both the service and its addon', function () {
    $service = Service::factory()->dueOn('2026-11-15')->create();
    $addon = ServiceAddon::factory()->dueOn('2026-11-15')->create(['service_id' => $service->id]);
    $this->artisan('automation:run', ['--date' => '2026-11-01', '--only' => 'services.renewals'])->assertSuccessful();
    $request = app(RequestCancellation::class)->handle($service, CancellationType::EndOfPeriod, 'Leaving', null, false, CarbonImmutable::parse('2026-11-02'));

    expect($addon->refresh()->next_invoice_date->toDateString())->toBe('2026-11-15');

    app(VoidCancellation::class)->handle($request);
    $this->artisan('automation:run', ['--date' => '2026-11-03', '--only' => 'services.renewals'])->assertSuccessful();

    $addonPeriods = collect($this->kit->invoicer->invoicesFor(BillableType::ServiceAddon, $addon->id))
        ->map(fn (array $invoice) => [$invoice['status'], $invoice['draft']->lines[0]->periodStart->toDateString()])->values()->all();
    expect($addonPeriods)->toBe([['cancelled', '2026-11-15'], ['unpaid', '2026-11-15']])
        ->and($addon->refresh()->next_invoice_date->toDateString())->toBe('2026-12-15');
});

test('applying an upgrade leaves the addon renewal alone', function () {
    $this->travelTo('2026-11-20');
    $this->kit->priceBook->addProduct(1, 'Starter');
    $this->kit->priceBook->addProduct(2, 'Business', ['monthly' => 2000]);
    $this->kit->priceBook->allowUpgrade(1, 2);
    $service = Service::factory()->dueOn('2026-12-01')->create(['product_id' => 1]);
    $addon = ServiceAddon::factory()->dueOn('2026-12-01')->create(['service_id' => $service->id]);
    $this->artisan('automation:run', ['--date' => '2026-11-20', '--only' => 'services.renewals'])->assertSuccessful();

    $change = app(OrderServiceChange::class)->handle($service, 2, BillingCycle::Monthly, CarbonImmutable::parse('2026-11-20'));
    $this->kit->invoicer->pay($change->invoice_id);

    expect(array_column($this->kit->invoicer->invoicesFor(BillableType::ServiceAddon, $addon->id), 'status'))->toBe(['unpaid'])
        ->and($addon->refresh()->next_invoice_date->toDateString())->toBe('2027-01-01');
});

test('a renewal invoice with a recorded payment is never cancelled', function () {
    $service = Service::factory()->dueOn('2026-11-15')->create();
    $this->artisan('automation:run', ['--date' => '2026-11-01', '--only' => 'services.renewals'])->assertSuccessful();
    $this->kit->invoiceQueries->paidInvoiceIds[] = $this->kit->invoicer->lastInvoiceId();

    app(RequestCancellation::class)->handle($service, CancellationType::EndOfPeriod, 'Leaving', null, false, CarbonImmutable::parse('2026-11-02'));

    expect($this->kit->invoicer->status($this->kit->invoicer->lastInvoiceId()))->toBe('unpaid');
});

// Blocking 5: state machine under concurrency

test('a status changed by staff while a suspend is in flight is not overwritten', function () {
    Event::fake([ProvisioningFailed::class]);
    $service = Service::factory()->onServer()->create();
    $this->kit->driver->whileRunning = fn () => Service::query()->whereKey($service->id)->update(['status' => ServiceStatus::Cancelled->value]);

    RunModuleCommand::dispatch($service->id, 'suspend', ['reason' => 'overdue']);

    expect($service->refresh()->status)->toBe(ServiceStatus::Cancelled)
        ->and(ModuleQueueItem::query()->where('subject_id', $service->id)->value('kind'))->toBe(ModuleQueueItem::KIND_RECONCILE);
    Event::assertDispatched(ProvisioningFailed::class);
});

test('staff cannot set a status while a module command holds the service', function () {
    $service = Service::factory()->onServer()->create();
    Cache::lock(ExecuteModuleCommand::lockKey($service->id), 60)->get();

    expect(fn () => app(SetServiceStatus::class)->handle($service, ServiceStatus::Suspended))->toThrow(ServiceActionException::class);
    expect($service->refresh()->status)->toBe(ServiceStatus::Active);
});

test('a terminate queued for an approved cancellation does not run once the request is voided', function () {
    Queue::fake();
    $service = Service::factory()->onServer()->create();
    $request = app(RequestCancellation::class)->handle($service, CancellationType::Immediate, 'Leaving', null, false, CarbonImmutable::today());
    app(ProcessCancellations::class)->process($request->refresh());
    $job = Queue::pushed(RunModuleCommand::class)->first();

    app(VoidCancellation::class)->handle($request->refresh());
    $job->handle(app(ExecuteModuleCommand::class));

    expect($service->refresh()->status)->toBe(ServiceStatus::Active)
        ->and($request->refresh()->status)->toBe(CancellationStatus::Voided)
        ->and($this->kit->driver->callsFor('terminate'))->toBeEmpty();
});

// Blocking 6: create never re-runs after an unknown outcome

test('create is not called again when a previous attempt never reported back', function () {
    Event::fake([ProvisioningFailed::class]);
    $this->kit->priceBook->addProduct(11, 'Starter');
    $service = Service::factory()->pending()->create(['product_id' => 11, 'server_id' => Server::factory()->create()->id]);
    ModuleCommand::query()->create(['service_id' => $service->id, 'driver' => 'log', 'command' => 'create', 'initiator' => 'system', 'success' => false, 'status' => ModuleCommand::RUNNING]);

    RunModuleCommand::dispatch($service->id, 'create');

    expect($this->kit->driver->callsFor('create'))->toBeEmpty()
        ->and($service->refresh()->status)->toBe(ServiceStatus::Pending)
        ->and(ModuleQueueItem::query()->where('subject_id', $service->id)->value('kind'))->toBe(ModuleQueueItem::KIND_VERIFY)
        ->and(ModuleCommand::query()->where('service_id', $service->id)->value('status'))->toBe(ModuleCommand::UNKNOWN);
    Event::assertDispatched(ProvisioningFailed::class);
});

test('only a staff retry of the verify item creates the account after an unknown outcome', function () {
    actingAsStaff(['services.module_log.view', 'services.module_log.manage']);
    $this->kit->priceBook->addProduct(11, 'Starter');
    $service = Service::factory()->pending()->create(['product_id' => 11, 'server_id' => Server::factory()->create()->id]);
    ModuleCommand::query()->create(['service_id' => $service->id, 'driver' => 'log', 'command' => 'create', 'initiator' => 'system', 'success' => false, 'status' => ModuleCommand::RUNNING]);
    RunModuleCommand::dispatch($service->id, 'create');

    RunModuleCommand::dispatch($service->id, 'create', lockRetries: 1);
    expect($this->kit->driver->callsFor('create'))->toBeEmpty();

    $this->post(route('admin.services.module-log.retry', ModuleQueueItem::query()->sole()))->assertSessionHasNoErrors();

    expect($service->refresh()->status)->toBe(ServiceStatus::Active)
        ->and($this->kit->driver->callsFor('create'))->toHaveCount(1)
        ->and(ModuleQueueItem::query()->sole()->status->value)->toBe('resolved')
        ->and(ModuleCommand::query()->where('service_id', $service->id)->where('status', ModuleCommand::UNKNOWN)->exists())->toBeFalse();
});

test('a job that dies mid-create opens a verify item instead of retrying', function () {
    $service = Service::factory()->pending()->onServer()->create();
    ModuleCommand::query()->create(['service_id' => $service->id, 'driver' => 'log', 'command' => 'create', 'initiator' => 'system', 'success' => false, 'status' => ModuleCommand::RUNNING]);

    (new RunModuleCommand($service->id, 'create'))->failed(new RuntimeException('timed out'));

    expect(ModuleQueueItem::query()->where('subject_id', $service->id)->value('kind'))->toBe(ModuleQueueItem::KIND_VERIFY)
        ->and($this->kit->driver->calls)->toBeEmpty();
});

test('module command jobs try once and time out before the lock and the queue redelivery', function () {
    $job = new RunModuleCommand(1, 'create');

    expect($job->tries)->toBe(1)
        ->and($job->failOnTimeout)->toBeTrue()
        ->and($job->timeout)->toBeLessThan((int) config('services.module.lock_ttl_seconds'))
        ->and((int) config('services.module.lock_ttl_seconds'))->toBeLessThan((int) config('queue.connections.database.retry_after'));
});

// Blocking 8: downgrade credit

test('downgrade then upgrade loops never mint credit', function () {
    app(Settings::class)->set('services.upgrades.credit_on_downgrade', true);
    $this->travelTo('2026-10-17');
    $this->kit->priceBook->addProduct(1, 'Starter', ['monthly' => 499]);
    $this->kit->priceBook->addProduct(2, 'Business', ['monthly' => 1299]);
    $this->kit->priceBook->allowUpgrade(1, 2);
    $this->kit->priceBook->allowUpgrade(2, 1);
    $service = Service::factory()->dueOn('2026-10-29', 29)->create(['product_id' => 2, 'recurring_amount' => 1299]);
    ServiceInvoice::query()->create(['service_id' => $service->id, 'invoice_id' => 9200, 'kind' => 'renewal', 'period_start' => '2026-09-29', 'period_end' => '2026-10-29', 'amount' => 1299, 'status' => 'paid']);
    $paid = 0;

    foreach (range(1, 4) as $loop) {
        app(OrderServiceChange::class)->handle($service->refresh(), 1, BillingCycle::Monthly, CarbonImmutable::today());
        $upgrade = app(OrderServiceChange::class)->handle($service->refresh(), 2, BillingCycle::Monthly, CarbonImmutable::today());
        $paid += max(0, $upgrade->amount_due);
        if ($upgrade->invoice_id !== null) {
            $this->kit->invoicer->pay($upgrade->invoice_id);
        }
    }

    $credit = $this->kit->credits->balance($service->client_id, 'USD')->amount;
    expect($credit - $paid)->toBeLessThanOrEqual(0)
        ->and($service->refresh()->product_id)->toBe(2);
});

test('the downgrade credit is based on what was paid net of promotions', function () {
    app(Settings::class)->set('services.upgrades.credit_on_downgrade', true);
    $this->travelTo('2026-11-02');
    $this->kit->priceBook->addProduct(1, 'Business', ['monthly' => 1000]);
    $this->kit->priceBook->addProduct(2, 'Starter', ['monthly' => 200]);
    $this->kit->priceBook->allowUpgrade(1, 2);
    $service = Service::factory()->dueOn('2026-11-01')->create(['product_id' => 1, 'recurring_amount' => 1000]);
    event(new InvoicePaid(9300, $service->client_id, [
        new InvoiceLineData('Business', 1000, 1, true, BillableType::Service, $service->id, CarbonImmutable::parse('2026-11-01'), CarbonImmutable::parse('2026-12-01')),
        new InvoiceLineData('First month free', -1000, 1, true, BillableType::Promotion, 5, CarbonImmutable::parse('2026-11-01'), CarbonImmutable::parse('2026-12-01')),
    ]));

    $change = app(OrderServiceChange::class)->handle($service->refresh(), 2, BillingCycle::Monthly, CarbonImmutable::today());

    expect($change->credit_amount)->toBe(0)
        ->and($this->kit->credits->balance($service->client_id, 'USD')->amount)->toBe(0);
});

test('the upgrade quote passes the client for group pricing', function () {
    $this->kit->priceBook->addProduct(1, 'Starter');
    $this->kit->priceBook->addProduct(2, 'Business', ['monthly' => 2000]);
    $this->kit->priceBook->allowUpgrade(1, 2);
    $service = Service::factory()->dueOn(today()->addDays(10))->create(['product_id' => 1]);

    app(OrderServiceChange::class)->handle($service, 2, BillingCycle::Monthly, CarbonImmutable::today());

    expect(end($this->kit->priceBook->quotedSelections)->clientId)->toBe($service->client_id);
});

test('a paid change is not applied to a service that ended meanwhile', function () {
    $this->kit->priceBook->addProduct(1, 'Starter');
    $this->kit->priceBook->addProduct(2, 'Business', ['monthly' => 2000]);
    $this->kit->priceBook->allowUpgrade(1, 2);
    $service = Service::factory()->dueOn(today()->addDays(10))->create(['product_id' => 1]);
    $change = app(OrderServiceChange::class)->handle($service, 2, BillingCycle::Monthly, CarbonImmutable::today());
    $service->update(['status' => ServiceStatus::Terminated]);

    app(ApplyServiceChange::class)->handle($change->id);

    expect($service->refresh()->product_id)->toBe(1)->and($change->refresh()->status->value)->toBe('apply_failed');
});

// Blocking 9: re-create and terminate

test('re-creating a terminated service resumes billing from today', function () {
    $this->travelTo('2026-11-10');
    $service = Service::factory()->dueOn('2026-08-01')->onServer()->create(['status' => ServiceStatus::Terminated, 'do_not_renew' => true, 'termination_date' => '2026-08-05']);

    RunModuleCommand::dispatch($service->id, 'create');

    expect($service->refresh())
        ->status->toBe(ServiceStatus::Active)
        ->do_not_renew->toBeFalse()
        ->termination_date->toBeNull()
        ->next_due_date->toDateString()->toBe('2026-11-10')
        ->next_invoice_date->toDateString()->toBe('2026-11-10');

    $this->artisan('automation:run', ['--date' => '2026-11-10', '--only' => 'services.renewals'])->assertSuccessful();
    expect($this->kit->invoicer->invoicesFor(BillableType::Service, $service->id))->toHaveCount(1);
});

test('a staff terminate closes open cancellation requests', function () {
    Event::fake([ServiceCancelled::class]);
    $service = Service::factory()->onServer()->create();
    $request = app(RequestCancellation::class)->handle($service, CancellationType::EndOfPeriod, 'Leaving', null, false, CarbonImmutable::today());

    RunModuleCommand::dispatch($service->id, 'terminate');

    expect($request->refresh()->status)->toBe(CancellationStatus::Processed);
    Event::assertNotDispatched(ServiceCancelled::class);
});

// Blocking 10: queue wiring

test('the dev runner consumes the provisioning queue', function () {
    $queue = collect(DevCommands::commands())->firstWhere('name', 'queue');

    expect($queue['command'])->toContain('--queue=provisioning,default');
});

// ADR 0008

test('pending services take promotion, cycle limit, config options and custom fields from the draft and product', function () {
    $this->kit->priceBook->addProduct(31, 'Business', recurringCycleLimit: 12);
    $client = Client::factory()->create();

    $id = app(ServiceRegistry::class)->createPending(new ServiceDraft($client->id, 31, BillingCycle::Monthly, 'USD', 1500, 1500, CarbonImmutable::today()->addMonth(), configOptions: [4 => 40], customFields: [7 => 'ssh-key'], promotionId: 3, promotionCyclesRemaining: 2, renewalDiscount: 300));

    $service = Service::query()->findOrFail($id);
    expect($service)
        ->recurring_cycles_limit->toBe(12)
        ->promotion_cycles_remaining->toBe(2)
        ->renewal_discount->toBe(300)
        ->custom_fields->toBe([7 => 'ssh-key'])
        ->and($service->configOptionSelections()->get(['option_id', 'value_id'])->toArray())->toBe([['option_id' => 4, 'value_id' => 40]]);
});

test('renewals carry the promotion line while cycles remain', function () {
    $service = Service::factory()->dueOn('2026-11-15')->create(['recurring_amount' => 1000, 'promotion_id' => 3, 'promotion_cycles_remaining' => 1, 'renewal_discount' => 250]);

    $this->artisan('automation:run', ['--date' => '2026-11-01', '--only' => 'services.renewals'])->assertSuccessful();
    $this->kit->invoicer->pay($this->kit->invoicer->lastInvoiceId());
    $this->artisan('automation:run', ['--date' => '2026-12-01', '--only' => 'services.renewals'])->assertSuccessful();

    $totals = collect($this->kit->invoicer->invoicesFor(BillableType::Service, $service->id))
        ->map(fn (array $invoice) => array_sum(array_map(fn (InvoiceLineData $line) => $line->total(), $invoice['draft']->lines)))->values()->all();
    expect($totals)->toBe([750, 1000])
        ->and($service->refresh()->promotion_cycles_remaining)->toBe(0)
        ->and(ServiceInvoice::query()->where('service_id', $service->id)->orderBy('id')->value('amount'))->toBe(750);
});

test('a forever promotion keeps discounting renewals', function () {
    $service = Service::factory()->dueOn('2026-11-15')->create(['recurring_amount' => 1000, 'promotion_cycles_remaining' => null, 'renewal_discount' => 100]);

    $this->artisan('automation:run', ['--date' => '2026-11-01', '--only' => 'services.renewals'])->assertSuccessful();

    $lines = array_values($this->kit->invoicer->invoicesFor(BillableType::Service, $service->id))[0]['draft']->lines;
    expect($lines[1]->billableType)->toBe(BillableType::Promotion)->and($lines[1]->unitAmount)->toBe(-100)
        ->and($service->refresh()->promotion_cycles_remaining)->toBeNull();
});

test('ServiceActivated carries the next due date', function () {
    Event::fake([ServiceActivated::class]);
    $service = Service::factory()->pending()->onServer()->create();

    RunModuleCommand::dispatch($service->id, 'create');

    Event::assertDispatched(ServiceActivated::class, fn (ServiceActivated $event) => $event->nextDueDate === $service->next_due_date->toDateString());
});

test('drivers receive custom fields by module key and password fields are redacted', function () {
    $this->kit->priceBook->addProduct(11, 'Starter');
    $this->kit->priceBook->customFields[11] = [
        ['id' => 5, 'name' => 'Root access', 'type' => 'text', 'required' => false, 'options' => [], 'module_key' => 'root'],
        ['id' => 6, 'name' => 'Panel PIN', 'type' => 'password', 'required' => false, 'options' => []],
    ];
    $service = Service::factory()->pending()->onServer()->create(['product_id' => 11, 'custom_fields' => [5 => 'yes', 6 => 'pin-998877']]);
    $seen = null;
    $this->kit->driver->whileRunning = function (string $command, $context) use (&$seen) {
        $seen = $context?->customFields;
        throw new RetryableProvisioningException('failed with pin-998877');
    };
    config(['services.module.max_attempts' => 1]);

    RunModuleCommand::dispatch($service->id, 'create');

    expect($seen)->toBe(['root' => 'yes', 'panel_pin' => 'pin-998877'])
        ->and(ModuleCommand::query()->where('service_id', $service->id)->value('error'))->toBe('failed with ***');
});

test('product suspend overrides apply when the service has none', function () {
    $this->kit->priceBook->addProduct(11, 'Starter', suspendAfterDays: 2);
    $service = Service::factory()->dueOn('2026-11-15')->onServer()->create(['product_id' => 11]);
    $this->artisan('automation:run', ['--date' => '2026-11-15', '--only' => 'services.renewals'])->assertSuccessful();

    $this->artisan('automation:run', ['--date' => '2026-11-17', '--only' => 'services.suspensions'])->assertSuccessful();

    expect($service->refresh()->status)->toBe(ServiceStatus::Suspended);
});

test('ProvisioningDrivers lists drivers with typed config fields', function () {
    $drivers = app(ProvisioningDrivers::class);
    $log = collect($drivers->all())->firstWhere('key', 'log');

    expect($drivers->exists('log'))->toBeTrue()
        ->and($drivers->exists('cpanel-x'))->toBeFalse()
        ->and($log['config_fields'][0])->toMatchArray(['key' => 'package', 'required' => false, 'secret' => false]);
});

test('ClientServiceDirectory lists only the client\'s non-deleted services', function () {
    $client = Client::factory()->create();
    $mine = Service::factory()->create(['client_id' => $client->id, 'domain' => 'mine.io', 'product_name' => 'Starter']);
    Service::factory()->create(['client_id' => $client->id])->delete();
    $other = Service::factory()->create();
    $directory = app(ClientServiceDirectory::class);

    expect($directory->forClient($client->id))->toBe([['id' => $mine->id, 'label' => 'Starter - mine.io', 'status' => 'active']])
        ->and($directory->belongsToClient($mine->id, $client->id))->toBeTrue()
        ->and($directory->belongsToClient($other->id, $client->id))->toBeFalse();
});

test('capacity exhaustion and voided cancellations dispatch shared events', function () {
    Event::fake([ServerCapacityExhausted::class, CancellationVoided::class]);
    $group = ServerGroup::factory()->create();
    $this->kit->priceBook->addProduct(11, 'Starter', serverGroupId: $group->id);
    $pending = Service::factory()->pending()->create(['product_id' => 11]);
    RunModuleCommand::dispatch($pending->id, 'create');

    $service = Service::factory()->create();
    $request = app(RequestCancellation::class)->handle($service, CancellationType::EndOfPeriod, 'Leaving', null, false, CarbonImmutable::today());
    app(VoidCancellation::class)->handle($request);

    Event::assertDispatched(ServerCapacityExhausted::class, fn ($event) => $event->serviceId === $pending->id && $event->serverGroupId === $group->id);
    Event::assertDispatched(CancellationVoided::class, fn ($event) => $event->cancellationRequestId === $request->id);
});
