<?php

use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Currency;
use App\Modules\Orders\Enums\OrderItemStatus;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Orders\Models\Promotion;
use App\Modules\Orders\Models\PromotionRedemption;
use App\Modules\Orders\Pricing\CartPricer;
use App\Modules\Orders\Pricing\PricedCart;
use Carbon\CarbonImmutable;
use Tests\Fakes\Orders\OrdersFakes;

beforeEach(function () {
    Currency::factory()->create();
    $this->fakes = OrdersFakes::bind();
    $this->fakes->catalog->addProduct(1, 'Starter Hosting', ['monthly' => [1000, 0]])->addProduct(2, 'Business Hosting', ['monthly' => [2000, 0]]);
});

/**
 * @param  list<int>  $productIds
 */
function pricePromotion(string $code, array $productIds = [1], ?Client $client = null): PricedCart
{
    return app(CartPricer::class)->price(array_map(fn (int $id) => OrdersFakes::productItem($id), $productIds), 'USD', $code, $client);
}

it('accepts a promotion until the end of its expiry day in the company timezone', function () {
    config(['system.timezone' => 'Europe/Berlin']);
    $promotion = Promotion::factory()->percent('10')->create(['expires_on' => '2026-11-30']);

    $this->travelTo(CarbonImmutable::parse('2026-11-30 23:30', 'Europe/Berlin'));
    expect(pricePromotion($promotion->code)->promotionError)->toBeNull();

    $this->travelTo(CarbonImmutable::parse('2026-12-01 00:01', 'Europe/Berlin'));
    expect(pricePromotion($promotion->code)->promotionError)->toBe('This promotion code has expired.');
});

it('is not valid before its start date', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-17 12:00'));
    $promotion = Promotion::factory()->create(['starts_on' => '2026-10-18']);

    expect(pricePromotion($promotion->code)->promotionError)->toBe('This promotion code is not active yet.');
});

it('rejects a manually expired promotion', function () {
    $promotion = Promotion::factory()->create(['expired_at' => now()]);

    expect(pricePromotion($promotion->code)->promotionError)->toBe('This promotion code has expired.');
});

it('rejects a promotion that reached its usage limit', function () {
    $promotion = Promotion::factory()->create(['max_uses' => 1, 'uses' => 1]);

    expect(pricePromotion($promotion->code)->promotionError)->toContain('Usage limit reached');
});

it('rejects a new-clients-only promotion for a client with a prior order', function () {
    $client = Client::factory()->create();
    Order::factory()->for($client)->status(OrderStatus::Active)->create();
    $promotion = Promotion::factory()->create(['new_clients_only' => true]);

    expect(pricePromotion($promotion->code, client: $client)->promotionError)->toBe('This promotion code is only for new customers.')
        ->and(pricePromotion($promotion->code, client: Client::factory()->create())->promotionError)->toBeNull();
});

it('ignores cancelled orders when checking for new clients', function () {
    $client = Client::factory()->create();
    Order::factory()->for($client)->status(OrderStatus::Cancelled)->create();
    $promotion = Promotion::factory()->create(['new_clients_only' => true]);

    expect(pricePromotion($promotion->code, client: $client)->promotionError)->toBeNull();
});

it('rejects a once-per-client promotion the client already redeemed', function () {
    $client = Client::factory()->create();
    $promotion = Promotion::factory()->create(['once_per_client' => true]);
    $order = Order::factory()->for($client)->create();
    PromotionRedemption::query()->create(['promotion_id' => $promotion->id, 'client_id' => $client->id, 'order_id' => $order->id]);

    expect(pricePromotion($promotion->code, client: $client)->promotionError)->toBe('You have already used this promotion code.');
});

it('accepts a once-per-client promotion again after its redemption was released', function () {
    $client = Client::factory()->create();
    $promotion = Promotion::factory()->create(['once_per_client' => true]);
    $order = Order::factory()->for($client)->create();
    PromotionRedemption::query()->create(['promotion_id' => $promotion->id, 'client_id' => $client->id, 'order_id' => $order->id, 'released_at' => now()]);

    expect(pricePromotion($promotion->code, client: $client)->promotionError)->toBeNull();
});

it('requires an active service for existing-clients-only promotions', function () {
    $promotion = Promotion::factory()->create(['existing_clients_only' => true]);
    $client = Client::factory()->create();

    expect(pricePromotion($promotion->code, client: $client)->promotionError)->toBe('This promotion code is only for existing customers with an active service.');

    OrderItem::factory()->for(Order::factory()->for($client))->status(OrderItemStatus::Active)->create();

    expect(pricePromotion($promotion->code, client: $client)->promotionError)->toBeNull();
});

it('counts active services from the Services directory for existing-clients-only promotions', function () {
    $promotion = Promotion::factory()->create(['existing_clients_only' => true]);
    $client = Client::factory()->create();
    $this->fakes->clientServices->services[$client->id] = [['id' => 9, 'label' => 'Imported plan', 'status' => 'active']];

    expect(pricePromotion($promotion->code, client: $client)->promotionError)->toBeNull();
});

it('discounts only the more expensive item when applied once', function () {
    $promotion = Promotion::factory()->percent('10')->create(['apply_once' => true]);

    $cart = pricePromotion($promotion->code, [1, 2]);

    expect(array_map(fn ($line) => $line->discount, $cart->lines()))->toBe([0, 200]);
});

it('requires the required product in the cart and then discounts the target product', function () {
    $promotion = Promotion::factory()->percent('50')->create([
        'requires' => ['product_ids' => [1]],
        'applies_to' => ['product_ids' => [2]],
    ]);

    expect(pricePromotion($promotion->code, [2])->promotionError)->toBe('This promotion code requires another product in your cart.');

    $cart = pricePromotion($promotion->code, [1, 2]);
    expect($cart->promotionError)->toBeNull()
        ->and(array_map(fn ($line) => $line->discount, $cart->lines()))->toBe([0, 1000]);
});

it('accepts an owned required product when allowed', function () {
    $client = Client::factory()->create();
    OrderItem::factory()->for(Order::factory()->for($client))->status(OrderItemStatus::Active)->create(['product_id' => 1]);
    $promotion = Promotion::factory()->create(['requires' => ['product_ids' => [1]], 'applies_to' => ['product_ids' => [2]], 'requires_existing_ok' => true]);

    expect(pricePromotion($promotion->code, [2], $client)->promotionError)->toBeNull();
});

it('enforces the minimum cart amount converted to the cart currency', function () {
    Currency::factory()->inr()->create(['exchange_rate' => 0.5]);
    $promotion = Promotion::factory()->create(['min_cart_amount' => 1500]);

    expect(pricePromotion($promotion->code)->promotionError)->toBe('Your cart total is below the minimum for this promotion code.')
        ->and(app(CartPricer::class)->price([OrdersFakes::productItem(1)], 'INR', $promotion->code)->promotionError)->toBeNull();
});

it('converts fixed amounts from the default currency at the current rate', function () {
    Currency::factory()->inr()->create(['exchange_rate' => 84]);
    $promotion = Promotion::factory()->fixed('0.10')->create();

    $cart = app(CartPricer::class)->price([OrdersFakes::productItem(1)], 'INR', $promotion->code);

    expect($cart->lines()[0]->discount)->toBe(840);
});

it('matches promotion codes case-insensitively', function () {
    Promotion::factory()->create(['code' => 'welcome10']);

    expect(Promotion::query()->sole()->code)->toBe('WELCOME10')
        ->and(pricePromotion('Welcome10')->promotionApplied())->toBeTrue();
});

it('reports an unknown code', function () {
    expect(pricePromotion('NOPE')->promotionError)->toBe('This promotion code does not exist.');
});

it('reports a code that matches nothing in the cart', function () {
    $promotion = Promotion::factory()->forProducts([99])->create();

    expect(pricePromotion($promotion->code)->promotionError)->toBe('This promotion code does not apply to the items in your cart.');
});
