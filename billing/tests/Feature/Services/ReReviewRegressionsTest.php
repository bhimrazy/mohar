<?php

use App\Data\Billing\InvoiceLineData;
use App\Enums\BillableType;
use App\Enums\BillingCycle;
use App\Events\Billing\InvoicePaid;
use App\Models\User;
use App\Modules\Core\Models\Currency;
use App\Modules\Core\Support\Settings;
use App\Modules\Services\Actions\ExecuteModuleCommand;
use App\Modules\Services\Actions\OrderServiceChange;
use App\Modules\Services\Actions\RequestCancellation;
use App\Modules\Services\Actions\VoidCancellation;
use App\Modules\Services\Enums\CancellationType;
use App\Modules\Services\Enums\ModuleCommandName;
use App\Modules\Services\Enums\ServiceStatus;
use App\Modules\Services\Jobs\RunModuleCommand;
use App\Modules\Services\Models\ModuleCommand;
use App\Modules\Services\Models\ModuleQueueItem;
use App\Modules\Services\Models\Server;
use App\Modules\Services\Models\Service;
use App\Modules\Services\Models\ServiceInvoice;
use App\Support\Permissions\PermissionRegistry;
use Carbon\CarbonImmutable;
use Tests\Fakes\Services\ServicesTestKit;

beforeEach(function () {
    Currency::factory()->create();
    $this->kit = ServicesTestKit::bind();
});

/**
 * Business 12.99 / Starter 4.99, current period [10-29, 11-29) and the next one [11-29, 12-29)
 * both paid (renewal paid during lead days), today 11-20.
 */
function prepaidService(ServicesTestKit $kit): Service
{
    app(Settings::class)->set('services.upgrades.credit_on_downgrade', true);
    test()->travelTo('2026-11-20');
    $kit->priceBook->addProduct(1, 'Starter', ['monthly' => 499]);
    $kit->priceBook->addProduct(2, 'Business', ['monthly' => 1299]);
    $kit->priceBook->allowUpgrade(1, 2);
    $kit->priceBook->allowUpgrade(2, 1);
    $service = Service::factory()->dueOn('2026-12-29', 29)->create(['product_id' => 2, 'recurring_amount' => 1299]);

    foreach ([['2026-10-29', '2026-11-29', 9401], ['2026-11-29', '2026-12-29', 9402]] as [$start, $end, $invoice]) {
        ServiceInvoice::query()->create(['service_id' => $service->id, 'invoice_id' => $invoice, 'kind' => 'renewal', 'period_start' => $start, 'period_end' => $end, 'amount' => 1299, 'status' => 'paid']);
    }

    return $service;
}

/**
 * Run a plan change; pay its invoice. Returns [credit granted, amount paid].
 *
 * @return array{0: int, 1: int}
 */
function changePlan(ServicesTestKit $kit, Service $service, int $product): array
{
    $before = $kit->credits->balance($service->client_id, 'USD')->amount;
    $change = app(OrderServiceChange::class)->handle($service->refresh(), $product, BillingCycle::Monthly, CarbonImmutable::today());

    if ($change->invoice_id !== null) {
        $kit->invoicer->pay($change->invoice_id);
    }

    return [$kit->credits->balance($service->client_id, 'USD')->amount - $before, max(0, $change->amount_due)];
}

// 1. Credit loop with a prepaid next period

test('a downgrade credits the unused days of the current and the prepaid period', function () {
    $service = prepaidService($this->kit);

    [$credit] = changePlan($this->kit, $service, 1);

    // 9 of 31 days of [10-29, 11-29) + all 30 days of [11-29, 12-29), each at 12.99 → 4.99
    $expected = (int) round(800 * 9 / 31) + 800;
    expect($credit)->toBe($expected);
});

test('upgrading back charges the difference for the prepaid period too', function () {
    $service = prepaidService($this->kit);
    [$credit] = changePlan($this->kit, $service, 1);

    $change = app(OrderServiceChange::class)->handle($service->refresh(), 2, BillingCycle::Monthly, CarbonImmutable::today());

    expect($change->amount_due)->toBe($credit)
        ->and($change->invoice_id)->not->toBeNull();
});

test('downgrade/upgrade sequences with prepaid periods never mint credit or give free upgrades', function (array $sequence) {
    $service = prepaidService($this->kit);
    $credited = 0;
    $paid = 0;
    $changes = 0;
    $starterDays = 0;

    foreach ([...$sequence, 'up'] as $step) {
        if ($step === 'wait') {
            $starterDays += $service->refresh()->product_id === 1 ? 3 : 0;
            $this->travel(3)->days();

            continue;
        }

        $target = $step === 'down' ? 1 : 2;

        if ($service->refresh()->product_id === $target) {
            continue;
        }

        [$credit, $payment] = changePlan($this->kit, $service, $target);
        $credited += $credit;
        $paid += $payment;
        $changes++;

        if ($step === 'up') {
            expect($payment)->toBeGreaterThan(0, 'an upgrade with paid time left was free');
        }
    }

    // Back on the original plan: the client may only keep the difference for days actually spent
    // on the cheaper plan (8.00 per 30 days), plus half-up rounding of each prorated segment.
    $entitled = (int) ceil(800 * $starterDays / 30) + 2 * $changes;
    expect($credited - $paid)->toBeLessThanOrEqual($entitled);
})->with([
    'loop x3' => [['down', 'up', 'down', 'up', 'down', 'up']],
    'with waits' => [['down', 'wait', 'up', 'wait', 'down', 'up']],
    'down only' => [['down']],
    'down, up, wait, down' => [['down', 'up', 'wait', 'wait', 'down']],
    'long wait on starter' => [['down', 'wait', 'wait', 'wait', 'up', 'down', 'wait', 'up']],
]);

test('a downgrade/upgrade round trip on the same day nets exactly zero', function () {
    $service = prepaidService($this->kit);

    foreach (range(1, 3) as $loop) {
        [$credit] = changePlan($this->kit, $service, 1);
        [, $paid] = changePlan($this->kit, $service, 2);
        expect($credit - $paid)->toBe(0);
    }
});

// 2. create never runs twice after an unknown outcome

test('a payment replay after a crashed create does not provision again', function () {
    $this->kit->priceBook->addProduct(11, 'Starter', autoSetup: 'payment');
    $service = Service::factory()->pending()->create(['product_id' => 11, 'server_id' => Server::factory()->create()->id]);
    ModuleCommand::query()->create(['service_id' => $service->id, 'driver' => 'log', 'command' => 'create', 'initiator' => 'system', 'success' => false, 'status' => ModuleCommand::RUNNING]);
    (new RunModuleCommand($service->id, 'create'))->failed(new RuntimeException('worker killed'));
    $paid = new InvoicePaid(9501, $service->client_id, [new InvoiceLineData('Starter', 1000, 1, true, BillableType::Service, $service->id, $service->registration_date, $service->next_invoice_date)]);

    event($paid);
    event($paid);
    RunModuleCommand::dispatch($service->id, 'create', lockRetries: 3);

    expect($this->kit->driver->callsFor('create'))->toBeEmpty()
        ->and($service->refresh()->status)->toBe(ServiceStatus::Pending)
        ->and(ModuleQueueItem::query()->where('subject_id', $service->id)->where('kind', ModuleQueueItem::KIND_VERIFY)->value('status')->value)->toBe('open');
});

test('resolving a verify item acknowledges the unknown attempt', function () {
    actingAsStaff(['services.module_log.view', 'services.module_log.manage']);
    $service = Service::factory()->pending()->onServer()->create();
    ModuleCommand::query()->create(['service_id' => $service->id, 'driver' => 'log', 'command' => 'create', 'initiator' => 'system', 'success' => false, 'status' => ModuleCommand::RUNNING]);
    (new RunModuleCommand($service->id, 'create'))->failed(null);

    $this->post(route('admin.services.module-log.resolve', ModuleQueueItem::query()->sole()))->assertRedirect();

    expect(ModuleCommand::query()->where('service_id', $service->id)->value('status'))->toBe(ModuleCommand::VERIFIED);
});

test('a job that gave up waiting for the lock does not flag another worker\'s running call', function () {
    $service = Service::factory()->onServer()->create();
    ModuleCommand::query()->create(['service_id' => $service->id, 'driver' => 'log', 'command' => 'suspend', 'initiator' => 'system', 'success' => false, 'status' => ModuleCommand::RUNNING]);

    app(ExecuteModuleCommand::class)->abandon($service->id, ModuleCommandName::Suspend, [], 'busy', jobDied: false);

    expect(ModuleCommand::query()->where('service_id', $service->id)->value('status'))->toBe(ModuleCommand::RUNNING)
        ->and(ModuleQueueItem::query()->sole()->kind)->toBe(ModuleQueueItem::KIND_FAILURE);
});

// 3. Promotion cycles

test('a cancelled discounted renewal gives its promotion cycle back', function () {
    $service = Service::factory()->dueOn('2026-11-15')->create(['recurring_amount' => 1000, 'promotion_cycles_remaining' => 1, 'renewal_discount' => 300]);
    $this->artisan('automation:run', ['--date' => '2026-11-01', '--only' => 'services.renewals'])->assertSuccessful();
    $request = app(RequestCancellation::class)->handle($service, CancellationType::EndOfPeriod, 'Leaving', null, false, CarbonImmutable::parse('2026-11-02'));

    expect($service->refresh()->promotion_cycles_remaining)->toBe(1);

    app(VoidCancellation::class)->handle($request);
    $this->artisan('automation:run', ['--date' => '2026-11-03', '--only' => 'services.renewals'])->assertSuccessful();

    $totals = collect($this->kit->invoicer->invoicesFor(BillableType::Service, $service->id))
        ->map(fn (array $invoice) => [$invoice['status'], array_sum(array_map(fn (InvoiceLineData $line) => $line->total(), $invoice['draft']->lines))])->values()->all();
    expect($totals)->toBe([['cancelled', 700], ['unpaid', 700]])
        ->and($service->refresh()->promotion_cycles_remaining)->toBe(0);
});

test('a renewal cancelled in Billing also restores the promotion cycle', function () {
    $service = Service::factory()->dueOn('2026-11-15')->create(['recurring_amount' => 1000, 'promotion_cycles_remaining' => 2, 'renewal_discount' => 300]);
    $this->artisan('automation:run', ['--date' => '2026-11-01', '--only' => 'services.renewals'])->assertSuccessful();

    $this->kit->invoicer->cancel($this->kit->invoicer->lastInvoiceId());
    $this->kit->invoicer->cancel($this->kit->invoicer->lastInvoiceId());

    expect($service->refresh()->promotion_cycles_remaining)->toBe(2);
});

// N2: promotion netting

test('a promotion after a domain line is not netted against the hosting service', function () {
    $service = Service::factory()->pending()->create();

    event(new InvoicePaid(9601, $service->client_id, [
        new InvoiceLineData('Hosting', 1000, 1, true, BillableType::Service, $service->id, $service->registration_date, $service->next_invoice_date),
        new InvoiceLineData('Domain', 1500, 1, true, BillableType::Domain, 55),
        new InvoiceLineData('Free domain', -1500, 1, true, BillableType::Promotion, 3),
    ]));

    expect(ServiceInvoice::query()->where('service_id', $service->id)->value('amount'))->toBe(1000);
});

// 4. Sensitive permissions

test('credential reveal and server management are sensitive: wildcards never grant them', function () {
    $registry = app(PermissionRegistry::class);
    $wildcard = User::factory()->staff(['services.*'])->create();

    expect($registry->sensitive())->toContain('services.services.credentials', 'services.servers.manage')
        ->and($wildcard->hasPermission('services.services.credentials'))->toBeFalse()
        ->and($wildcard->hasPermission('services.servers.manage'))->toBeFalse()
        ->and($wildcard->hasPermission('services.services.view'))->toBeTrue();
});

test('revealing credentials needs a recent password confirmation', function () {
    actingAsStaff(['services.services.view', 'services.services.credentials']);
    $service = Service::factory()->onServer()->create();

    $this->postJson(route('admin.services.services.credentials', $service))->assertStatus(423);
    $this->assertDatabaseMissing('activity_log', ['description' => 'Service credentials revealed']);
});

test('changing server credentials needs a recent password confirmation', function () {
    actingAsStaff(['services.servers.view', 'services.servers.manage']);
    $server = Server::factory()->create();

    $this->put(route('admin.services.servers.update', $server), ['name' => 'X'])->assertSessionHasErrors(['password_confirmation']);
    $this->delete(route('admin.services.servers.destroy', $server))->assertSessionHasErrors(['password_confirmation']);
});
