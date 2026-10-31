<?php

use App\Enums\BillingCycle;
use App\Modules\Catalog\Models\Addon;
use App\Modules\Catalog\Models\Product;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Currency;
use App\Modules\Core\Support\Settings;
use App\Modules\Orders\Actions\PlaceOrder;
use App\Modules\Orders\Actions\SetOrderPending;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Promotion;
use App\Modules\Orders\Pricing\CartPricer;
use Tests\Fakes\Orders\OrdersFakes;

/*
 * Checkout charges catalog.md §3.4's first period for a prorata product (issue #598, D1). Amounts are minor units;
 * order times are UTC instants, read as Nepal dates (UTC+05:45).
 */

beforeEach(function () {
    Currency::factory()->npr()->create();
    app(Settings::class)->set('system.timezone', 'Asia/Kathmandu');
    $this->fakes = OrdersFakes::bind(realCatalog: true);
    $this->client = Client::factory()->create(['currency' => 'NPR']);
    $this->product = fn (int $amount = 310000, BillingCycle $cycle = BillingCycle::Monthly, int $day = 1, int $chargeNextMonthDay = 20, string $currency = 'NPR') => Product::factory()
        ->withPrice($currency, $cycle, $amount)
        ->create(['prorata_enabled' => true, 'prorata_day' => $day, 'prorata_charge_next_month_day' => $chargeNextMonthDay]);
    $this->checkout = function (Product $product, string $at, string $cycle = 'monthly', array $addonIds = [], ?Client $client = null, ?string $promotion = null): array {
        $this->travelTo($at);
        $order = app(PlaceOrder::class)->handle(OrdersFakes::checkout($client ?? $this->client, [OrdersFakes::productItem($product->id, $cycle, addonIds: $addonIds)], $promotion, skipFraud: true))->order;
        $line = $this->fakes->billing->lastDraft()->lines[0];

        return [$order, $line, end($this->fakes->services->services)];
    };
});

it('charges the pro-rated first period and sets the service dates', function (string $at, int $firstAmount, string $periodEnd) {
    [$order, $line, $draft] = ($this->checkout)(($this->product)(), $at);

    expect($order->items()->sole()->first_amount)->toBe($firstAmount)
        ->and($order->subtotal)->toBe($firstAmount)
        ->and($line->unitAmount)->toBe($firstAmount)
        ->and([$line->periodStart?->toDateString(), $line->periodEnd?->toDateString()])->toBe([substr($at, 0, 10), $periodEnd])
        ->and([$draft->firstPaymentAmount, $draft->recurringAmount, $draft->nextDueDate->toDateString(), $draft->billingAnchorDay])
        ->toBe([$firstAmount, 310000, $periodEnd, 1]);
})->with([
    '01-10, before the charge-next-month day: 310000 × 22/31' => ['2026-01-10 06:00:00', 220000, '2026-02-01'],
    '01-25, after it: 310000 × 7/31 + February' => ['2026-01-25 06:00:00', 380000, '2026-03-01'],
    '01-31, the month end: 310000 × 1/31 + February' => ['2026-01-31 06:00:00', 320000, '2026-03-01'],
]);

it('reads the order date at the Asia/Kathmandu midnight', function (string $at, int $firstAmount) {
    [, $line] = ($this->checkout)(($this->product)(), $at);

    expect($line->unitAmount)->toBe($firstAmount);
})->with([
    '23:55 on 9 January in Nepal: 23 days' => ['2026-01-09 18:10:00', 230000],
    '00:05 on 10 January in Nepal, still 9 January in UTC: 22 days' => ['2026-01-09 18:20:00', 220000],
]);

it('divides a longer cycle by its own length', function () {
    // 3650000 × 22/365 = 220000 for 10 January to 1 February, plus the year from 1 February.
    [, $line, $draft] = ($this->checkout)(($this->product)(3650000, BillingCycle::Annually), '2026-01-10 06:00:00', 'annually');

    expect([$line->unitAmount, $line->periodEnd?->toDateString(), $draft->recurringAmount, $draft->nextDueDate->toDateString()])
        ->toBe([3870000, '2027-02-01', 3650000, '2027-02-01']);
});

it('counts leap February and clamps day 31 to short months', function (int $amount, int $day, int $chargeNextMonthDay, string $at, int $firstAmount, string $periodEnd) {
    [, $line, $draft] = ($this->checkout)(($this->product)($amount, day: $day, chargeNextMonthDay: $chargeNextMonthDay), $at);

    expect([$line->unitAmount, $line->periodEnd?->toDateString(), $draft->billingAnchorDay])->toBe([$firstAmount, $periodEnd, $day]);
})->with([
    'leap February: 290000 × 20/29' => [290000, 1, 20, '2028-02-10 06:00:00', 200000, '2028-03-01'],
    'day 31 in April: 300000 × 15/30, renews on the 31st' => [300000, 31, 32, '2026-04-15 06:00:00', 150000, '2026-04-30'],
]);

it('adds the next month always with 0 and never with 32', function (int $chargeNextMonthDay, string $at, int $firstAmount, string $periodEnd) {
    [, $line] = ($this->checkout)(($this->product)(chargeNextMonthDay: $chargeNextMonthDay), $at);

    expect([$line->unitAmount, $line->periodEnd?->toDateString()])->toBe([$firstAmount, $periodEnd]);
})->with([
    '0, ordered 01-10: 220000 + 310000' => [0, '2026-01-10 06:00:00', 530000, '2026-03-01'],
    '32, ordered 01-25: 310000 × 7/31 only' => [32, '2026-01-25 06:00:00', 70000, '2026-02-01'],
]);

it('rounds in a zero-decimal currency', function () {
    Currency::factory()->create(['code' => 'JPY', 'name' => 'Japanese Yen', 'prefix' => '¥', 'exchange_rate' => 150, 'is_default' => false]);
    $client = Client::factory()->create(['currency' => 'JPY']);

    // ¥1000 × 22/31 = 709.68 → 710.
    [, $line] = ($this->checkout)(($this->product)(1000, currency: 'JPY'), '2026-01-10 06:00:00', client: $client);

    expect($line->unitAmount)->toBe(710);
});

it('bills one cycle from the order date without prorata, ending on the clamped month end', function () {
    $product = Product::factory()->withPrice('NPR', BillingCycle::Monthly, 310000)->create(['prorata_enabled' => false, 'prorata_day' => 1]);

    [, $line, $draft] = ($this->checkout)($product, '2026-01-31 06:00:00');

    expect([$line->unitAmount, $line->periodStart?->toDateString(), $line->periodEnd?->toDateString()])->toBe([310000, '2026-01-31', '2026-02-28'])
        ->and([$draft->nextDueDate->toDateString(), $draft->billingAnchorDay])->toBe(['2026-02-28', 31]);
});

it('aligns an addon with its parent only when prorata_with_parent is on', function (bool $withParent, int $firstAmount, string $periodEnd, int $anchorDay) {
    $product = ($this->product)();
    $addon = Addon::factory()->withPrice('NPR', BillingCycle::Monthly, 62000)->create(['prorata_with_parent' => $withParent]);
    $product->addons()->attach($addon->id);

    ($this->checkout)($product, '2026-01-10 06:00:00', addonIds: [$addon->id]);

    $line = collect($this->fakes->billing->lastDraft()->lines)->firstWhere('description', "Addon: {$addon->name}");
    $draft = end($this->fakes->services->addons);
    expect([$line->unitAmount, $line->periodEnd?->toDateString()])->toBe([$firstAmount, $periodEnd])
        ->and([$draft->recurringAmount, $draft->nextDueDate->toDateString(), $draft->billingAnchorDay])->toBe([62000, $periodEnd, $anchorDay]);
})->with([
    'on: 62000 × 22/31 to the parent anchor' => [true, 44000, '2026-02-01', 1],
    'off: its own month from the order date' => [false, 62000, '2026-02-10', 10],
]);

it('discounts renewals of a pro-rated service on the renewal price', function () {
    $product = ($this->product)();
    Promotion::factory()->percent('10.00')->create(['code' => 'TENOFF', 'recurring' => true]);

    // First amount 380000 (7/31 + February), 10% off it; each renewal is 310000, 10% off that.
    [$order, , $draft] = ($this->checkout)($product, '2026-01-25 06:00:00', promotion: 'TENOFF');

    expect([$order->discount_total, $draft->firstPaymentAmount, $draft->renewalDiscount, $order->items()->sole()->renewal_discount])
        ->toBe([38000, 342000, 31000, 31000]);
});

it('shows the first period and its amount in the cart', function () {
    $product = ($this->product)();
    $this->travelTo('2026-01-10 06:00:00');

    $line = app(CartPricer::class)->price([OrdersFakes::productItem($product->id)], 'NPR', client: $this->client)->toArray()['items'][0]['lines'][0];

    expect([$line['recurring'], $line['renewal'], $line['due_today'], $line['first_period']])
        ->toBe([220000, 310000, 220000, ['start' => '2026-01-10', 'end' => '2026-02-01', 'prorated' => true]]);
});

it('re-issues a cancelled order invoice for the first period stored at checkout', function () {
    [$order] = ($this->checkout)(($this->product)(), '2026-01-10 06:00:00');
    $order->forceFill(['status' => OrderStatus::Fraud])->save();
    $this->fakes->billing->statuses[$order->invoice_id] = 'cancelled';
    $this->travelTo('2026-01-14 06:00:00');

    app(SetOrderPending::class)->handle($order);

    $line = $this->fakes->billing->lastDraft()->lines[0];
    expect([$line->unitAmount, $line->periodStart?->toDateString(), $line->periodEnd?->toDateString()])->toBe([220000, '2026-01-10', '2026-02-01']);
});
