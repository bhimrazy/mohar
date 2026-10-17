<?php

use App\Contracts\Billing\Invoicer;
use App\Enums\BillableType;
use App\Enums\BillingCycle;
use App\Modules\Core\Models\Currency;
use App\Modules\Core\Support\Settings;
use App\Modules\Services\Enums\ServiceStatus;
use App\Modules\Services\Models\Service;
use App\Modules\Services\Models\ServiceAddon;
use Tests\Fakes\Services\ServicesTestKit;

beforeEach(function () {
    Currency::factory()->create();
    $this->kit = ServicesTestKit::bind();
});

function runRenewals(string $date, bool $force = false): void
{
    test()->artisan('automation:run', ['--date' => $date, '--only' => 'services.renewals', '--force' => $force])->assertSuccessful();
}

test('a renewal invoice is generated lead days before the period and advances the pointer', function () {
    $service = Service::factory()->dueOn('2026-11-15')->create(['recurring_amount' => 1299]);

    runRenewals('2026-11-01');

    $invoices = $this->kit->invoicer->invoicesFor(BillableType::Service, $service->id);
    expect($invoices)->toHaveCount(1);
    $line = array_values($invoices)[0]['draft']->lines[0];
    expect($line->periodStart->toDateString())->toBe('2026-11-15')
        ->and($line->periodEnd->toDateString())->toBe('2026-12-15')
        ->and($line->unitAmount)->toBe(1299)
        ->and(array_values($invoices)[0]['draft']->dueDate->toDateString())->toBe('2026-11-15')
        ->and($service->refresh()->next_invoice_date->toDateString())->toBe('2026-12-15')
        ->and($service->renewals_invoiced)->toBe(1)
        ->and($service->next_due_date->toDateString())->toBe('2026-11-15');
});

test('nothing is invoiced before the lead time', function () {
    $service = Service::factory()->dueOn('2026-11-15')->create();

    runRenewals('2026-10-31');

    expect($this->kit->invoicer->invoicesFor(BillableType::Service, $service->id))->toBeEmpty();
});

test('a per-cycle lead time overrides the default', function () {
    app(Settings::class)->set('services.renewals.lead_days.annually', 30);
    $service = Service::factory()->dueOn('2026-11-30')->create(['cycle' => BillingCycle::Annually]);

    runRenewals('2026-10-31');

    expect($this->kit->invoicer->invoicesFor(BillableType::Service, $service->id))->toHaveCount(1);
});

test('running the job twice the same day creates one invoice', function () {
    $service = Service::factory()->dueOn('2026-11-15')->create();

    runRenewals('2026-11-01');
    runRenewals('2026-11-01', force: true);

    expect($this->kit->invoicer->invoicesFor(BillableType::Service, $service->id))->toHaveCount(1);
});

test('non-continuous mode waits while a renewal is unpaid', function () {
    $service = Service::factory()->dueOn('2026-11-15')->create();
    runRenewals('2026-11-01');

    runRenewals('2026-12-01');

    expect($this->kit->invoicer->invoicesFor(BillableType::Service, $service->id))->toHaveCount(1)
        ->and($service->refresh()->next_invoice_date->toDateString())->toBe('2026-12-15');
});

test('continuous mode invoices exactly one period per run', function () {
    app(Settings::class)->set('services.renewals.continuous', true);
    $service = Service::factory()->dueOn('2026-10-15')->create();

    runRenewals('2026-12-10');

    expect($this->kit->invoicer->invoicesFor(BillableType::Service, $service->id))->toHaveCount(1)
        ->and($service->refresh()->next_invoice_date->toDateString())->toBe('2026-11-15');
});

test('a cancelled renewal period is not regenerated automatically', function () {
    $service = Service::factory()->dueOn('2026-11-15')->create();
    runRenewals('2026-11-01');
    $this->kit->invoicer->cancel($this->kit->invoicer->lastInvoiceId());

    runRenewals('2026-11-02');

    $periods = collect($this->kit->invoicer->invoicesFor(BillableType::Service, $service->id))
        ->map(fn (array $invoice) => $invoice['draft']->lines[0]->periodStart->toDateString())->values()->all();
    expect($periods)->toBe(['2026-11-15']);
});

test('an existing invoice for the period only advances the pointer', function () {
    $service = Service::factory()->dueOn('2026-11-15')->create();
    runRenewals('2026-11-01');
    $service->refresh()->update(['next_invoice_date' => '2026-11-15']);
    $this->kit->invoicer->pay($this->kit->invoicer->lastInvoiceId());
    $service->refresh()->update(['next_invoice_date' => '2026-11-15']);

    runRenewals('2026-11-03');

    expect($this->kit->invoicer->invoicesFor(BillableType::Service, $service->id))->toHaveCount(1)
        ->and($service->refresh()->next_invoice_date->toDateString())->toBe('2026-12-15');
});

test('terminated, cancelled, fraud, completed, free and do-not-renew services never invoice', function () {
    $excluded = collect([
        Service::factory()->dueOn('2026-11-15')->status(ServiceStatus::Terminated)->create(),
        Service::factory()->dueOn('2026-11-15')->status(ServiceStatus::Cancelled)->create(),
        Service::factory()->dueOn('2026-11-15')->status(ServiceStatus::Fraud)->create(),
        Service::factory()->dueOn('2026-11-15')->status(ServiceStatus::Completed)->create(),
        Service::factory()->free()->create(),
        Service::factory()->dueOn('2026-11-15')->create(['do_not_renew' => true]),
        Service::factory()->dueOn('2026-11-15')->create(['terminate_at_period_end' => true]),
    ]);
    $suspended = Service::factory()->dueOn('2026-11-15')->suspended()->create();

    runRenewals('2026-11-01');

    $excluded->each(fn (Service $service) => expect($this->kit->invoicer->invoicesFor(BillableType::Service, $service->id))->toBeEmpty());
    expect($this->kit->invoicer->invoicesFor(BillableType::Service, $suspended->id))->toHaveCount(1);
});

test('anchored renewals do not drift across short months', function () {
    $service = Service::factory()->dueOn('2027-01-31', 31)->create();

    foreach (['2027-01-20', '2027-02-20', '2027-03-20'] as $date) {
        runRenewals($date);
        $this->kit->invoicer->pay($this->kit->invoicer->lastInvoiceId());
    }

    expect($service->refresh()->next_due_date->toDateString())->toBe('2027-04-30')
        ->and($service->billing_anchor_day)->toBe(31);
});

test('each renewal invoice bills exactly one item, even when addons share the due date', function () {
    $service = Service::factory()->dueOn('2026-11-15')->create();
    $addon = ServiceAddon::factory()->dueOn('2026-11-15')->create(['service_id' => $service->id, 'recurring_amount' => 250]);

    runRenewals('2026-11-01');

    $serviceInvoices = array_values($this->kit->invoicer->invoicesFor(BillableType::Service, $service->id));
    $addonInvoices = array_values($this->kit->invoicer->invoicesFor(BillableType::ServiceAddon, $addon->id));
    expect($serviceInvoices)->toHaveCount(1)->and($serviceInvoices[0]['draft']->lines)->toHaveCount(1)
        ->and($addonInvoices)->toHaveCount(1)->and($addonInvoices[0]['draft']->lines)->toHaveCount(1)
        ->and($addonInvoices[0]['draft']->lines[0]->unitAmount)->toBe(250)
        ->and($addon->refresh()->next_invoice_date->toDateString())->toBe('2026-12-15');
});

test('addons on their own dates are invoiced separately', function () {
    $service = Service::factory()->dueOn('2027-01-15')->create();
    $addon = ServiceAddon::factory()->dueOn('2026-11-20')->create(['service_id' => $service->id]);

    runRenewals('2026-11-10');

    expect($this->kit->invoicer->invoicesFor(BillableType::ServiceAddon, $addon->id))->toHaveCount(1)
        ->and($this->kit->invoicer->invoicesFor(BillableType::Service, $service->id))->toBeEmpty();
});

test('renewals stop at the recurring cycle limit and the service completes after the last period', function () {
    $service = Service::factory()->dueOn('2026-11-15')->create(['recurring_cycles_limit' => 3, 'renewals_invoiced' => 2]);

    runRenewals('2026-11-01');
    $this->kit->invoicer->pay($this->kit->invoicer->lastInvoiceId());
    runRenewals('2026-12-01');

    expect($this->kit->invoicer->invoicesFor(BillableType::Service, $service->id))->toHaveCount(1)
        ->and($service->refresh()->renewals_invoiced)->toBe(3);

    $this->artisan('automation:run', ['--date' => '2026-12-14', '--only' => 'services.complete-limited'])->assertSuccessful();
    expect($service->refresh()->status)->toBe(ServiceStatus::Active);

    $this->artisan('automation:run', ['--date' => '2026-12-15', '--only' => 'services.complete-limited'])->assertSuccessful();
    expect($service->refresh()->status)->toBe(ServiceStatus::Completed);
});

test('the renewal task is a no-op without a billing implementation', function () {
    app()->offsetUnset(Invoicer::class);
    Service::factory()->dueOn('2026-11-15')->create();

    runRenewals('2026-11-01');

    expect(Service::query()->value('renewals_invoiced'))->toBe(0);
});

test('a pipeline completed today is not re-run without force', function () {
    $service = Service::factory()->dueOn('2026-11-15')->create();

    $this->artisan('automation:run', ['--date' => '2026-11-01'])->assertSuccessful();
    $service->refresh()->update(['next_invoice_date' => '2026-11-15']);
    $this->artisan('automation:run', ['--date' => '2026-11-01'])->assertSuccessful();

    expect($this->kit->invoicer->invoicesFor(BillableType::Service, $service->id))->toHaveCount(1)
        ->and($service->refresh()->next_invoice_date->toDateString())->toBe('2026-11-15');
});
