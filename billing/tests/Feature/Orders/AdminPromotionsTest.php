<?php

use App\Modules\Core\Models\Currency;
use App\Modules\Orders\Enums\PromotionType;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\Promotion;
use App\Modules\Orders\Models\PromotionRedemption;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Fakes\Orders\OrdersFakes;

beforeEach(function () {
    Currency::factory()->create();
    OrdersFakes::bind()->catalog->addProduct(1);
});

/**
 * @return array<string, mixed>
 */
function promotionPayload(array $overrides = []): array
{
    return [
        'code' => 'spring25',
        'type' => 'percent',
        'value' => '25',
        'recurring' => true,
        'recur_count' => 3,
        'applies_to_products' => [1],
        'applies_to_tlds' => 'com, .NET',
        'cycles' => ['monthly'],
        'starts_on' => '2026-10-01',
        'expires_on' => '2026-11-30',
        'max_uses' => 100,
        'once_per_client' => true,
        'min_cart_amount' => '20.00',
        ...$overrides,
    ];
}

it('forbids staff without the promotions permission', function () {
    actingAsStaff(['orders.orders.view']);

    $this->get(route('admin.promotions.index'))->assertForbidden();
    $this->post(route('admin.promotions.store'), promotionPayload())->assertForbidden();
});

it('lists promotions', function () {
    actingAsStaff(['orders.promotions.manage']);
    Promotion::factory()->count(2)->create();

    $this->get(route('admin.promotions.index'))
        ->assertInertia(fn (Assert $page) => $page->component('admin/promotions/index')->has('promotions.data', 2));
});

it('creates a promotion with its restrictions', function () {
    actingAsStaff(['orders.promotions.manage']);

    $this->post(route('admin.promotions.store'), promotionPayload())->assertRedirect();

    $promotion = Promotion::query()->sole();
    expect($promotion)
        ->code->toBe('SPRING25')
        ->type->toBe(PromotionType::Percent)
        ->value->toBe('25.00')
        ->recur_count->toBe(3)
        ->applies_to->toBe(['product_ids' => [1], 'tlds' => ['.com', '.net']])
        ->cycles->toBe(['monthly'])
        ->expires_on->toDateString()->toBe('2026-11-30')
        ->min_cart_amount->toBe(2000)
        ->once_per_client->toBeTrue();
});

it('validates promotion input', function () {
    actingAsStaff(['orders.promotions.manage']);
    Promotion::factory()->create(['code' => 'TAKEN']);

    $this->post(route('admin.promotions.store'), promotionPayload([
        'code' => 'taken',
        'value' => '120',
        'expires_on' => '2026-09-01',
    ]))->assertSessionHasErrors([
        'code',
        'value' => 'A percentage cannot exceed 100.',
        'expires_on' => 'The expiry date must be on or after the start date.',
    ]);
});

it('updates a promotion', function () {
    actingAsStaff(['orders.promotions.manage']);
    $promotion = Promotion::factory()->create(['code' => 'OLD']);

    $this->put(route('admin.promotions.update', $promotion), promotionPayload(['code' => 'OLD', 'type' => 'fixed', 'value' => '5']))
        ->assertRedirect(route('admin.promotions.edit', $promotion));

    expect($promotion->fresh())->type->toBe(PromotionType::Fixed)->value->toBe('5.00');
});

it('refuses to delete a redeemed promotion', function () {
    actingAsStaff(['orders.promotions.manage']);
    $promotion = Promotion::factory()->create(['uses' => 1]);
    $order = Order::factory()->create();
    PromotionRedemption::query()->create(['promotion_id' => $promotion->id, 'client_id' => $order->client_id, 'order_id' => $order->id]);

    $this->delete(route('admin.promotions.destroy', $promotion))
        ->assertSessionHasErrors(['promotion' => 'This promotion has been used and cannot be deleted. Expire it instead.']);

    expect(Promotion::query()->count())->toBe(1);
});

it('deletes an unused promotion', function () {
    actingAsStaff(['orders.promotions.manage']);
    $promotion = Promotion::factory()->create();

    $this->delete(route('admin.promotions.destroy', $promotion))->assertRedirect(route('admin.promotions.index'));

    expect(Promotion::query()->count())->toBe(0);
});

it('expires a promotion now', function () {
    actingAsStaff(['orders.promotions.manage']);
    $promotion = Promotion::factory()->create();

    $this->post(route('admin.promotions.expire', $promotion))->assertRedirect();

    expect($promotion->fresh()->expired_at)->not->toBeNull();
});

it('duplicates a promotion with a fresh usage count', function () {
    actingAsStaff(['orders.promotions.manage']);
    $promotion = Promotion::factory()->create(['code' => 'SUMMER', 'uses' => 9]);

    $this->post(route('admin.promotions.duplicate', $promotion))->assertRedirect();

    $copy = Promotion::query()->where('code', 'SUMMER-COPY')->sole();
    expect($copy->uses)->toBe(0)->and($copy->type)->toBe($promotion->type);
});

it('shows the redemption report on the edit page', function () {
    actingAsStaff(['orders.promotions.manage']);
    $promotion = Promotion::factory()->create();
    $order = Order::factory()->create();
    PromotionRedemption::query()->create(['promotion_id' => $promotion->id, 'client_id' => $order->client_id, 'order_id' => $order->id, 'discount_amount' => 250]);

    $this->get(route('admin.promotions.edit', $promotion))
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/promotions/form')
            ->where('promotion.code', $promotion->code)
            ->where('redemptions.0.order_number', $order->number)
            ->where('redemptions.0.discount_amount', 250));
});
