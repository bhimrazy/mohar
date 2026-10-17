<?php

use App\Enums\BillableType;
use App\Enums\BillingCycle;
use App\Events\Services\ServiceUpgraded;
use App\Modules\Core\Models\Currency;
use App\Modules\Core\Support\Settings;
use App\Modules\Services\Actions\OrderServiceChange;
use App\Modules\Services\Actions\QuoteServiceChange;
use App\Modules\Services\Enums\ChangeStatus;
use App\Modules\Services\Exceptions\ServiceActionException;
use App\Modules\Services\Models\Service;
use App\Modules\Services\Models\ServiceChange;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Tests\Fakes\Services\ServicesTestKit;

beforeEach(function () {
    Currency::factory()->create();
    $this->kit = ServicesTestKit::bind();
    $this->kit->priceBook->addProduct(1, 'Starter', ['monthly' => 1000, 'annually' => 10000]);
    $this->kit->priceBook->addProduct(2, 'Business', ['monthly' => 2000]);
    $this->kit->priceBook->addProduct(3, 'Mini', ['monthly' => 200]);
    $this->kit->priceBook->allowUpgrade(1, 2);
    $this->kit->priceBook->allowUpgrade(1, 3);
});

function orderChange(Service $service, int $product, BillingCycle $cycle, string $today): ServiceChange
{
    $date = CarbonImmutable::parse($today);
    $quote = app(QuoteServiceChange::class)->handle($service, $product, $cycle, $date);

    return app(OrderServiceChange::class)->handle($service, $quote, $date);
}

test('the upgrade invoice is prorated and the service changes only once it is paid', function () {
    Event::fake([ServiceUpgraded::class]);
    $this->travelTo('2026-11-16');
    $service = Service::factory()->dueOn('2026-12-01')->onServer()->create(['product_id' => 1, 'product_name' => 'Starter', 'recurring_amount' => 1000]);

    $change = orderChange($service, 2, BillingCycle::Monthly, '2026-11-16');

    expect($change)->credit_amount->toBe(500)->charge_amount->toBe(1000)->amount_due->toBe(500)->status->toBe(ChangeStatus::PendingPayment)
        ->and($service->refresh()->product_id)->toBe(1);
    $line = $this->kit->invoicer->invoices[$change->invoice_id]['draft']->lines[0];
    expect($line->billableType)->toBe(BillableType::ServiceUpgrade)->and($line->unitAmount)->toBe(500);

    $this->kit->invoicer->pay($change->invoice_id);
    $this->kit->invoicer->pay($change->invoice_id);

    expect($service->refresh())->product_id->toBe(2)->product_name->toBe('Business')->recurring_amount->toBe(2000)
        ->next_due_date->toDateString()->toBe('2026-12-01')
        ->and($change->refresh()->status)->toBe(ChangeStatus::Applied)
        ->and($this->kit->driver->callsFor('change_package'))->toHaveCount(1);
    Event::assertDispatchedTimes(ServiceUpgraded::class, 1);
});

test('an unpaid upgrade expires after the configured days and its invoice is cancelled', function () {
    $this->travelTo('2026-11-16 10:00');
    $service = Service::factory()->dueOn('2026-12-01')->create(['product_id' => 1]);
    $change = orderChange($service, 2, BillingCycle::Monthly, '2026-11-16');

    $this->artisan('automation:run', ['--date' => '2026-11-22', '--only' => 'services.upgrade-expiry'])->assertSuccessful();
    expect($change->refresh()->status)->toBe(ChangeStatus::PendingPayment);

    $this->artisan('automation:run', ['--date' => '2026-11-23', '--only' => 'services.upgrade-expiry'])->assertSuccessful();

    expect($change->refresh()->status)->toBe(ChangeStatus::Expired)
        ->and($this->kit->invoicer->status($change->invoice_id))->toBe('cancelled')
        ->and($service->refresh()->product_id)->toBe(1);
});

test('a downgrade is applied immediately and credited when enabled', function () {
    app(Settings::class)->set('services.upgrades.credit_on_downgrade', true);
    $this->travelTo('2026-11-16');
    $service = Service::factory()->dueOn('2026-12-01')->onServer()->create(['product_id' => 1, 'recurring_amount' => 1000]);

    $change = orderChange($service, 3, BillingCycle::Monthly, '2026-11-16');

    expect($change->amount_due)->toBe(-400)
        ->and($change->invoice_id)->toBeNull()
        ->and($change->status)->toBe(ChangeStatus::Applied)
        ->and($this->kit->credits->balance($service->client_id, 'USD')->amount)->toBe(400)
        ->and($service->refresh()->product_id)->toBe(3);
});

test('downgrade differences are forfeited by default', function () {
    $this->travelTo('2026-11-11');
    $service = Service::factory()->dueOn('2026-12-01')->create(['product_id' => 1]);

    orderChange($service, 3, BillingCycle::Monthly, '2026-11-11');

    expect($this->kit->credits->entries)->toBeEmpty();
});

test('applying an upgrade cancels the unpaid renewal at the old price and the next run re-invoices', function () {
    $this->travelTo('2026-11-20');
    $service = Service::factory()->dueOn('2026-12-01')->create(['product_id' => 1, 'recurring_amount' => 1000]);
    $this->artisan('automation:run', ['--date' => '2026-11-20', '--only' => 'services.renewals'])->assertSuccessful();
    $renewalId = $this->kit->invoicer->lastInvoiceId();

    $change = orderChange($service->refresh(), 2, BillingCycle::Monthly, '2026-11-20');
    $this->kit->invoicer->pay($change->invoice_id);
    $this->artisan('automation:run', ['--date' => '2026-11-21', '--only' => 'services.renewals'])->assertSuccessful();

    $renewals = array_values(array_filter($this->kit->invoicer->invoicesFor(BillableType::Service, $service->id), fn ($invoice) => $invoice['draft']->lines[0]->billableType === BillableType::Service));
    expect($this->kit->invoicer->status($renewalId))->toBe('cancelled')
        ->and($renewals)->toHaveCount(2)
        ->and($renewals[1]['draft']->lines[0]->unitAmount)->toBe(2000)
        ->and($renewals[1]['draft']->lines[0]->periodStart->toDateString())->toBe('2026-12-01');
});

test('a free service upgraded to a monthly plan is charged in full and due a month later', function () {
    $this->travelTo('2027-01-01');
    $service = Service::factory()->free()->create(['product_id' => 1]);
    $this->kit->priceBook->allowUpgrade(1, 2);

    $change = orderChange($service, 2, BillingCycle::Monthly, '2027-01-01');

    expect($change->amount_due)->toBe(2000);
    $this->kit->invoicer->pay($change->invoice_id);
    expect($service->refresh())->next_due_date->toDateString()->toBe('2027-02-01')
        ->cycle->toBe(BillingCycle::Monthly)
        ->billing_anchor_day->toBe(1);
});

test('a billing cycle change starts a new period today', function () {
    $this->travelTo('2026-11-16');
    $service = Service::factory()->dueOn('2026-12-01')->create(['product_id' => 1, 'recurring_amount' => 1000]);

    $change = orderChange($service, 1, BillingCycle::Annually, '2026-11-16');

    expect($change)->credit_amount->toBe(500)->charge_amount->toBe(10000)->amount_due->toBe(9500);
    $this->kit->invoicer->pay($change->invoice_id);
    expect($service->refresh())->cycle->toBe(BillingCycle::Annually)
        ->next_due_date->toDateString()->toBe('2027-11-16')
        ->next_invoice_date->toDateString()->toBe('2027-11-16');
});

test('upgrades are refused when not allowed', function (callable $setup, string $message) {
    $this->travelTo('2026-11-16');
    $service = Service::factory()->dueOn('2026-12-01')->create(['product_id' => 1]);
    [$product, $cycle] = $setup($service);

    expect(fn () => app(QuoteServiceChange::class)->handle($service->refresh(), $product, $cycle, CarbonImmutable::parse('2026-11-16')))
        ->toThrow(ServiceActionException::class, $message);
})->with([
    'no upgrade path' => [fn () => [99, BillingCycle::Monthly], 'cannot be changed'],
    'suspended' => [function (Service $service) {
        $service->update(['status' => 'suspended']);

        return [2, BillingCycle::Monthly];
    }, 'Only active services'],
    'overdue' => [function (Service $service) {
        $service->update(['next_due_date' => '2026-11-10']);

        return [2, BillingCycle::Monthly];
    }, 'Pay the outstanding invoice'],
    'pending change' => [function (Service $service) {
        orderChange($service, 2, BillingCycle::Monthly, '2026-11-16');

        return [3, BillingCycle::Monthly];
    }, 'already waiting'],
    'cycle not offered' => [fn () => [2, BillingCycle::Annually], 'not offered'],
]);
