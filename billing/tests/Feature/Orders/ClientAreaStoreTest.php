<?php

use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\ClientGroup;
use App\Modules\Core\Models\Currency;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Fakes\Orders\OrdersFakes;

beforeEach(function () {
    $this->fakes = OrdersFakes::bind();
    $this->fakes->catalog->addProduct(1, 'Starter Hosting', ['monthly' => [1000, 500], 'annually' => [10000, 0]]);
});

/**
 * A signed-in client in a group with a 10% discount, with one monthly Starter Hosting in the cart.
 */
function p8bResellerWithStarterInCart(): Client
{
    $group = ClientGroup::query()->create(['name' => 'Resellers', 'discount_percent' => '10.00']);
    [, $client] = actingAsClient(Client::factory()->create(['client_group_id' => $group->id]));
    test()->fakes->catalog->clientDiscounts[$client->id] = 10;
    test()->post(route('store.cart.products.store', 1), ['cycle' => 'monthly'])->assertRedirect();

    return $client;
}

test('the cart explains a client-group discount with its own line', function () {
    p8bResellerWithStarterInCart();

    $this->get(route('store.cart'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('public/cart/index')
            // Guest price 1000 + 500 setup; the group takes 10% off the 1000.
            ->where('cart.subtotal', 1400)
            ->where('group_saving', ['label' => 'Resellers discount (10%)', 'amount' => 100]));
});

test('checkout shows the same client-group discount line', function () {
    p8bResellerWithStarterInCart();

    $this->get(route('store.checkout'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('public/checkout/index')
            ->where('group_saving.amount', 100));
});

test('a guest cart has no discount line', function () {
    Currency::factory()->create();
    $this->post(route('store.cart.products.store', 1), ['cycle' => 'monthly']);

    $this->get(route('store.cart'))->assertInertia(fn (Assert $page) => $page->where('cart.count', 1)->where('group_saving', null));
});

test('a client outside any group has no discount line', function () {
    actingAsClient();
    $this->post(route('store.cart.products.store', 1), ['cycle' => 'monthly']);

    $this->get(route('store.cart'))->assertInertia(fn (Assert $page) => $page->where('cart.count', 1)->where('group_saving', null));
});

test('the store domain search hands the page the availability lookup', function () {
    Currency::factory()->create();

    $this->get(route('store.domains', ['domain' => 'ashatraders']))
        ->assertInertia(fn (Assert $page) => $page
            ->component('public/store/domains')
            ->where('lookup_url', route('domains.lookup'))
            // The fake marks every extension it sells as spotlighted.
            ->where('results.0.domain', 'ashatraders.com')
            ->where('results.1.domain', 'ashatraders.net'));
});

test('the client sidebar marks Store on store pages and Register a domain on the domain search', function () {
    actingAsClient();

    $active = fn (string $url): array => collect($this->get($url)->viewData('page')['props']['navigation'])
        ->flatMap(fn (array $group): array => $group['items'])
        ->where('active', true)
        ->pluck('title')
        ->values()
        ->all();

    expect($active(route('store.cart')))->toBe(['Store'])
        ->and($active(route('store.index')))->toBe(['Store'])
        ->and($active(route('store.domains')))->toBe(['Register a domain']);
});
