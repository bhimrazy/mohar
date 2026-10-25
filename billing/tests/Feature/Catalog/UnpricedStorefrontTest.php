<?php

use App\Enums\BillingCycle;
use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductGroup;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Currency;
use App\Modules\Orders\Services\CartManager;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Currency::factory()->npr()->create(['is_default' => true]);
    Currency::factory()->create(['is_default' => false]);
    $group = ProductGroup::factory()->create();
    $this->usdOnly = Product::factory()->for($group, 'group')->withPrice('USD', BillingCycle::Monthly, 1000)->create(['name' => 'Dollar plan']);
    $this->nprToo = Product::factory()->for($group, 'group')->withPrice('NPR', BillingCycle::Monthly, 100_000)->withPrice('USD', BillingCycle::Monthly, 800)->create(['name' => 'Rupee plan']);
});

it('lists only the products priced in the visitor currency', function () {
    $this->get(route('store.index'))->assertInertia(fn (Assert $page) => $page
        ->where('currency', 'NPR')
        ->where('groups.0.products', fn ($products) => collect($products)->pluck('name')->all() === ['Rupee plan'])
        ->where('sold_in', []));
});

it('explains an empty store and names the currencies that have products', function () {
    Product::query()->whereKey($this->nprToo->id)->delete();

    $this->get(route('store.index'))->assertInertia(fn (Assert $page) => $page
        ->where('groups', [])
        ->where('sold_in', ['USD'])
        ->where('hide_tax_note', true)
        ->where('account_currency', null));
});

it('opens an unpriced product with the reason and where it is sold, and refuses it in the cart', function () {
    $this->get(route('store.products.show', [$this->usdOnly->group->slug, $this->usdOnly->slug]))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('public/store/configure')
        ->where('cycles', [])
        ->where('hide_tax_note', true)
        ->where('sold_in', ['USD']));

    $this->post(route('store.cart.products.store', $this->usdOnly), ['cycle' => 'monthly'])
        ->assertSessionHasErrors(['product_id' => 'Dollar plan is not sold in NPR.']);

    $this->get(route('store.products.show', [$this->nprToo->group->slug, $this->nprToo->slug]))->assertInertia(fn (Assert $page) => $page
        ->has('cycles', 1)->where('sold_in', []));
});

it('adds the product once the visitor switches to a currency it is priced in', function () {
    $this->withSession([CartManager::SESSION_CURRENCY => 'USD'])
        ->post(route('store.cart.products.store', $this->usdOnly), ['cycle' => 'monthly'])
        ->assertSessionHasNoErrors();
});

it('tells a signed-in client their account currency and where to ask, without a switch', function () {
    Product::query()->whereKey($this->nprToo->id)->delete();
    $client = Client::factory()->create(['currency' => 'NPR']);
    $user = User::factory()->create(['email' => $client->email]);
    $client->users()->attach($user, ['is_owner' => true]);
    $this->actingAs($user);

    $this->get(route('store.index'))->assertInertia(fn (Assert $page) => $page
        ->where('account_currency', 'NPR')
        ->where('can_switch_currency', false)
        ->where('support_url', route('support.tickets.create')));
});
