<?php

use App\Contracts\Catalog\PriceBook;
use App\Modules\Catalog\Enums\ProductType;
use App\Modules\Catalog\Models\Product;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use App\Support\Dashboard\Dashboard;

beforeEach(fn () => $this->travelTo('2026-10-18 12:00:00'));

function ordersByType(): array
{
    return collect(app(Dashboard::class)->resolveWidgets('admin', actingAsStaff(['orders.orders.view']), deferred: true))->firstWhere('key', 'orders.by_type');
}

function placedOrder(?Product $product, bool $domain = false, string $when = 'now', bool $fraud = false): Order
{
    $order = ($fraud ? Order::factory()->fraud() : Order::factory())->create(['created_at' => $when]);
    ($domain ? OrderItem::factory()->domain() : OrderItem::factory())->create(['order_id' => $order->id, 'product_id' => $domain ? null : $product?->id]);

    return $order;
}

test('new orders are counted per day under the product type of their first item', function () {
    $hosting = Product::factory()->create(['type' => ProductType::SharedHosting]);
    $vps = Product::factory()->create(['type' => ProductType::Server]);
    placedOrder($hosting);
    placedOrder($hosting, when: '2026-10-17 09:00:00');
    placedOrder($vps);
    placedOrder(null, domain: true);
    placedOrder($hosting, fraud: true);                       // fraud is not demand
    placedOrder($hosting, when: '2026-09-01 09:00:00');        // outside the 14 days

    $chart = ordersByType();
    $series = collect($chart['series'])->keyBy('name');

    expect($chart)->toMatchArray(['kind' => 'bar', 'stacked' => true, 'description' => 'Last 14 days · 4 orders'])
        ->and($series->keys()->all())->toBe(['Hosting', 'Servers', 'Domains'])
        ->and($series->map(fn ($item) => $item['slot'])->all())->toBe(['Hosting' => 1, 'Servers' => 2, 'Domains' => 3])
        ->and($series['Hosting']['points'])->toHaveCount(14)
        ->and(collect($series['Hosting']['points'])->last())->toBe(['x' => '2026-10-18', 'y' => 1])
        ->and($series['Hosting']['points'][12]['y'])->toBe(1)
        ->and(collect($series['Servers']['points'])->last()['y'])->toBe(1);
});

test('without the catalog, service orders fall under Other', function () {
    $product = Product::factory()->create(['type' => ProductType::Server]);
    placedOrder($product);
    app()->offsetUnset(PriceBook::class);

    expect(collect(ordersByType()['series'])->pluck('name')->all())->toBe(['Other']);
});

test('the chart is hidden without order access', function () {
    expect(collect(app(Dashboard::class)->resolveWidgets('admin', actingAsStaff(['billing.*'])))->pluck('key'))->not->toContain('orders.by_type');
});
