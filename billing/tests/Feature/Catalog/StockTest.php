<?php

use App\Contracts\Catalog\PriceBook;
use App\Events\Orders\OrderCancelled;
use App\Events\Orders\OrderMarkedFraud;
use App\Events\Orders\OrderPlaced;
use App\Events\Services\ServiceActivated;
use App\Modules\Catalog\Models\Product;

it('lets exactly one of two reservations take the last unit', function () {
    $product = Product::factory()->stock(1)->create();
    $book = app(PriceBook::class);

    $results = [$book->reserveStock($product->id), $book->reserveStock($product->id)];

    expect($results)->toBe([true, false])
        ->and($product->fresh()->stock_quantity)->toBe(0)
        ->and($product->fresh()->isOutOfStock())->toBeTrue();
});

it('always reserves products without stock control', function () {
    $product = Product::factory()->create(['stock_quantity' => 0]);

    expect(app(PriceBook::class)->reserveStock($product->id))->toBeTrue()
        ->and($product->fresh()->stock_quantity)->toBe(0);
});

it('refuses to reserve unknown products', function () {
    expect(app(PriceBook::class)->reserveStock(999_999))->toBeFalse();
});

it('returns stock when an order is cancelled before its service was activated', function () {
    $product = Product::factory()->stock(5)->create();
    app(PriceBook::class)->reserveStock($product->id);
    app(PriceBook::class)->reserveStock($product->id);

    OrderPlaced::dispatch(10, 1, null, [
        ['type' => 'service', 'id' => 100, 'product_id' => $product->id],
        ['type' => 'service', 'id' => 101, 'product_id' => $product->id],
        ['type' => 'domain', 'id' => 5, 'product_id' => null],
    ], null);
    ServiceActivated::dispatch(101, 1);
    OrderCancelled::dispatch(10, 1);
    OrderCancelled::dispatch(10, 1);

    expect($product->fresh()->stock_quantity)->toBe(4);
});

it('returns stock when an order is marked fraud', function () {
    $product = Product::factory()->stock(1)->create();
    app(PriceBook::class)->reserveStock($product->id);

    OrderPlaced::dispatch(11, 1, null, [['type' => 'service', 'id' => 200, 'product_id' => $product->id]], null);
    OrderMarkedFraud::dispatch(11, 1);

    expect($product->fresh()->stock_quantity)->toBe(1);
});

it('does not track products without stock control', function () {
    $product = Product::factory()->create();

    OrderPlaced::dispatch(12, 1, null, [['type' => 'service', 'id' => 300, 'product_id' => $product->id]], null);
    OrderCancelled::dispatch(12, 1);

    $this->assertDatabaseCount('product_stock_reservations', 0);
    expect($product->fresh()->stock_quantity)->toBe(0);
});
