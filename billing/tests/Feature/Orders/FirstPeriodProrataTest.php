<?php

use App\Enums\BillingCycle;
use App\Modules\Catalog\Models\Product;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Currency;
use App\Modules\Core\Support\Settings;
use App\Modules\Orders\Actions\PlaceOrder;
use Tests\Fakes\Orders\OrdersFakes;

/*
 * Today's behaviour, pinned for the CTO's decision in docs/reviews/2026-11-01-prorata.md: checkout does not read the
 * product's prorata settings. A prorata product's first invoice is one full cycle from the order date, as for any
 * other product. When Orders adopts catalog.md §3.4, these expectations change on purpose.
 */

beforeEach(function () {
    Currency::factory()->npr()->create();
    app(Settings::class)->set('system.timezone', 'Asia/Kathmandu');
    $this->fakes = OrdersFakes::bind(realCatalog: true);
    $this->client = Client::factory()->create(['currency' => 'NPR']);
});

it('invoices a prorata product for one full cycle from the Nepal order date', function () {
    $product = Product::factory()->withPrice('NPR', BillingCycle::Monthly, 310000)
        ->create(['prorata_enabled' => true, 'prorata_day' => 1, 'prorata_charge_next_month_day' => 20]);
    // 00:05 on 10 January in Nepal, still 9 January in UTC. catalog.md §3.4 would charge 310000 × 22/31 = 220000.
    $this->travelTo('2026-01-09 18:20:00');

    $order = app(PlaceOrder::class)->handle(OrdersFakes::checkout($this->client, [OrdersFakes::productItem($product->id)], skipFraud: true))->order;

    $line = $this->fakes->billing->lastDraft()->lines[0];
    $draft = $this->fakes->services->services[array_key_first($this->fakes->services->services)];
    expect($order->items()->sole()->first_amount)->toBe(310000)
        ->and($line->unitAmount)->toBe(310000)
        ->and([$line->periodStart?->toDateString(), $line->periodEnd?->toDateString()])->toBe(['2026-01-10', '2026-02-10'])
        ->and($draft->firstPaymentAmount)->toBe(310000)
        ->and($draft->nextDueDate->toDateString())->toBe('2026-01-10');
});

it('invoices a prorata product ordered on the last day of a month for a full cycle clamped to the next month end', function () {
    $product = Product::factory()->withPrice('NPR', BillingCycle::Monthly, 310000)
        ->create(['prorata_enabled' => true, 'prorata_day' => 1, 'prorata_charge_next_month_day' => 20]);
    // catalog.md §3.4 would charge 310000 × 1/31 + 310000 = 320000, next due 1 March.
    $this->travelTo('2026-01-31 06:00:00');

    app(PlaceOrder::class)->handle(OrdersFakes::checkout($this->client, [OrdersFakes::productItem($product->id)], skipFraud: true));

    $line = $this->fakes->billing->lastDraft()->lines[0];
    expect($line->unitAmount)->toBe(310000)
        ->and([$line->periodStart?->toDateString(), $line->periodEnd?->toDateString()])->toBe(['2026-01-31', '2026-02-28']);
});
