<?php

use App\Contracts\Catalog\PriceBook;
use App\Enums\BillingCycle;
use App\Events\Catalog\AddonCreated;
use App\Events\Catalog\AddonUpdated;
use App\Events\Catalog\ProductCreated;
use App\Events\Catalog\ProductPriceChanged;
use App\Events\Catalog\ProductRetired;
use App\Events\Catalog\ProductStockDepleted;
use App\Events\Catalog\ProductUpdated;
use App\Modules\Catalog\Actions\AdjustStock;
use App\Modules\Catalog\Actions\SaveAddon;
use App\Modules\Catalog\Actions\SaveProduct;
use App\Modules\Catalog\Actions\SyncPrices;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductGroup;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    Event::fake([ProductCreated::class, ProductUpdated::class, ProductRetired::class, ProductPriceChanged::class, ProductStockDepleted::class, AddonCreated::class, AddonUpdated::class]);
});

it('dispatches created, updated and retired product events', function () {
    $product = app(SaveProduct::class)->handle(Product::factory()->make(['product_group_id' => ProductGroup::factory()->create()->id])->getAttributes());
    Event::assertDispatched(ProductCreated::class, fn (ProductCreated $event) => $event->productId === $product->id);

    app(SaveProduct::class)->handle(['name' => 'Renamed'], $product);
    Event::assertNotDispatched(ProductRetired::class);

    app(SaveProduct::class)->handle(['is_retired' => true], $product);
    Event::assertDispatched(ProductUpdated::class, 2);
    Event::assertDispatchedTimes(ProductRetired::class, 1);
});

it('dispatches one price change event per changed row', function () {
    $product = Product::factory()->withPrice('USD', BillingCycle::Monthly, 1000)->withPrice('USD', BillingCycle::Annually, 9000)->create();

    app(SyncPrices::class)->handle($product, [
        ['currency' => 'USD', 'cycle' => 'monthly', 'enabled' => true, 'amount' => 1200, 'setup_fee' => 0],
        ['currency' => 'USD', 'cycle' => 'annually', 'enabled' => true, 'amount' => 9000, 'setup_fee' => 0],
    ]);

    Event::assertDispatchedTimes(ProductPriceChanged::class, 1);
    Event::assertDispatched(ProductPriceChanged::class, fn (ProductPriceChanged $event) => $event->priceableType === 'product'
        && $event->priceableId === $product->id && $event->cycle === 'monthly' && $event->oldAmount === 1000 && $event->newAmount === 1200);
});

it('dispatches stock depleted when the last unit is reserved or written off', function () {
    $reserved = Product::factory()->stock(2)->create();
    app(PriceBook::class)->reserveStock($reserved->id);
    Event::assertNotDispatched(ProductStockDepleted::class);
    app(PriceBook::class)->reserveStock($reserved->id);

    $writtenOff = Product::factory()->stock(1)->create();
    app(AdjustStock::class)->handle($writtenOff, -1);

    Event::assertDispatched(ProductStockDepleted::class, fn ($event) => $event->productId === $reserved->id);
    Event::assertDispatched(ProductStockDepleted::class, fn ($event) => $event->productId === $writtenOff->id);
});

it('dispatches addon created and updated events', function () {
    $data = ['name' => 'Backups', 'kind' => 'feature', 'payment_type' => 'recurring', 'auto_setup' => 'on_payment', 'provisioning_driver' => 'none', 'driver_config' => [], 'prices' => [], 'product_ids' => []];

    $addon = app(SaveAddon::class)->handle($data);
    app(SaveAddon::class)->handle($data, $addon);

    Event::assertDispatched(AddonCreated::class, fn ($event) => $event->addonId === $addon->id);
    Event::assertDispatched(AddonUpdated::class, fn ($event) => $event->addonId === $addon->id);
});
