<?php

use App\Contracts\Catalog\PriceBook;
use App\Events\Orders\OrderCancelled;
use App\Events\Orders\OrderMarkedFraud;
use App\Events\Orders\OrderPlaced;
use App\Events\Services\ServiceActivated;
use App\Modules\Catalog\Models\Product;

it('refuses a second reservation once the last unit is taken (conditional decrement)', function () {
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

it('releases stock only for products with stock control', function () {
    $stocked = Product::factory()->stock(0)->create();
    $unlimited = Product::factory()->create();

    app(PriceBook::class)->releaseStock($stocked->id, 2);
    app(PriceBook::class)->releaseStock($unlimited->id);
    app(PriceBook::class)->releaseStock($stocked->id, 0);

    expect($stocked->fresh()->stock_quantity)->toBe(2)
        ->and($unlimited->fresh()->stock_quantity)->toBe(0);
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

it('returns stock when fraud is flagged before the order is placed', function () {
    $product = Product::factory()->stock(3)->create();
    app(PriceBook::class)->reserveStock($product->id);
    app(PriceBook::class)->reserveStock($product->id);

    OrderMarkedFraud::dispatch(20, 1);
    OrderPlaced::dispatch(20, 1, null, [
        ['type' => 'service', 'id' => 400, 'product_id' => $product->id],
        ['type' => 'service', 'id' => 401, 'product_id' => $product->id],
    ], null);
    OrderPlaced::dispatch(20, 1, null, [['type' => 'service', 'id' => 400, 'product_id' => $product->id]], null);
    OrderMarkedFraud::dispatch(20, 1);
    OrderCancelled::dispatch(20, 1);

    expect($product->fresh()->stock_quantity)->toBe(3);
});

it('keeps stock for services that activated even if the order is cancelled later', function () {
    $product = Product::factory()->stock(1)->create();
    app(PriceBook::class)->reserveStock($product->id);

    OrderPlaced::dispatch(21, 1, null, [['type' => 'service', 'id' => 500, 'product_id' => $product->id]], null);
    ServiceActivated::dispatch(500, 1);
    OrderCancelled::dispatch(21, 1);

    expect($product->fresh()->stock_quantity)->toBe(0);
});

it('does not track products without stock control', function () {
    $product = Product::factory()->create();

    OrderPlaced::dispatch(12, 1, null, [['type' => 'service', 'id' => 300, 'product_id' => $product->id]], null);
    OrderCancelled::dispatch(12, 1);

    $this->assertDatabaseCount('product_stock_reservations', 0);
    expect($product->fresh()->stock_quantity)->toBe(0);
});
