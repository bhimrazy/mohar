<?php

use App\Enums\BillingCycle;
use App\Events\Services\ServiceActivated;
use App\Modules\Catalog\Models\Product;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Currency;
use App\Modules\Orders\Actions\CancelOrder;
use App\Modules\Orders\Actions\MarkOrderFraud;
use App\Modules\Orders\Actions\PlaceOrder;
use App\Modules\Orders\Models\Order;
use Illuminate\Validation\ValidationException;
use Tests\Fakes\Orders\OrdersFakes;

/*
 * AC 27 with the real Catalog: checkout reserves stock through PriceBook; Catalog returns it when the
 * order is cancelled or flagged as fraud before the service was activated (ADR 0008 addendum).
 */

beforeEach(function () {
    Currency::factory()->create();
    $this->fakes = OrdersFakes::bind(realCatalog: true);
    $this->product = Product::factory()->stock(2)->withPrice('USD', BillingCycle::Monthly, 1000)->create();
    $this->client = Client::factory()->create();
});

function placeStockedOrder(Client $client, Product $product): Order
{
    return app(PlaceOrder::class)->handle(OrdersFakes::checkout($client, [OrdersFakes::productItem($product->id)], skipFraud: true))->order;
}

it('reserves stock at checkout and returns it when the order is cancelled', function () {
    $order = placeStockedOrder($this->client, $this->product);
    expect($this->product->fresh()->stock_quantity)->toBe(1);

    app(CancelOrder::class)->handle($order);

    expect($this->product->fresh()->stock_quantity)->toBe(2);
});

it('returns stock once even when the order is flagged as fraud and then cancelled', function () {
    $order = placeStockedOrder($this->client, $this->product);

    app(MarkOrderFraud::class)->handle($order);
    app(CancelOrder::class)->handle($order->fresh());

    expect($this->product->fresh()->stock_quantity)->toBe(2);
});

it('keeps the stock of a service that was activated', function () {
    $order = placeStockedOrder($this->client, $this->product);
    ServiceActivated::dispatch((int) $order->items()->sole()->item_id, $this->client->id);

    app(CancelOrder::class)->handle($order);

    expect($this->product->fresh()->stock_quantity)->toBe(1);
});

it('refuses checkout when the last unit was taken', function () {
    $this->product->update(['stock_quantity' => 0]);

    placeStockedOrder($this->client, $this->product);
})->throws(ValidationException::class, 'is out of stock.');
