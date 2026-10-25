<?php

use App\Enums\BillingCycle;
use App\Modules\Catalog\Http\Requests\ProductGroupRequest;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductGroup;
use App\Modules\Core\Models\Currency;
use App\Modules\Orders\Http\Controllers\Public\StoreController;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Currency::factory()->create();
    $this->webGroup = ProductGroup::factory()->create(['name' => 'Web hosting', 'slug' => 'web-hosting']);
    $this->vpsGroup = ProductGroup::factory()->create(['name' => 'VPS', 'slug' => 'vps']);
    $this->starter = Product::factory()->for($this->webGroup, 'group')->withPrice('USD', BillingCycle::Monthly, 1000)->create(['name' => 'Starter', 'slug' => 'starter']);
    $this->cloud = Product::factory()->for($this->vpsGroup, 'group')->withPrice('USD', BillingCycle::Monthly, 5000)->create(['name' => 'Cloud', 'slug' => 'cloud']);
});

it('renders the configure page on the group and product slugs', function () {
    $this->get("/store/{$this->webGroup->slug}/{$this->starter->slug}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('public/store/configure')
            ->where('product.id', $this->starter->id)
            ->where('product.group', 'Web hosting'));
});

it('sends the numeric product URL to the slug URL permanently, keeping the query string', function () {
    $this->get("/store/products/{$this->starter->id}?domain=asha.com.np&item=abc")
        ->assertStatus(301)
        ->assertRedirect('/store/web-hosting/starter?domain=asha.com.np&item=abc');

    $this->get("/store/products/{$this->starter->id}")->assertStatus(301)->assertRedirect('/store/web-hosting/starter');
});

it('sends a product asked for under another group to its own group', function () {
    $this->get("/store/{$this->vpsGroup->slug}/{$this->starter->slug}?domain=asha.com.np")
        ->assertStatus(301)
        ->assertRedirect('/store/web-hosting/starter?domain=asha.com.np');
});

it('lists only the requested group on its page', function () {
    $this->get('/store/vps')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('public/store/index')
            ->where('group.name', 'VPS')
            ->has('groups', 1)
            ->where('groups.0.slug', 'vps')
            ->where('groups.0.products.0.url', url('/store/vps/cloud')));
});

it('carries the visitor-owned domain into the product links of the store pages', function () {
    $this->get('/store/vps?domain=asha.com.np')
        ->assertInertia(fn (Assert $page) => $page->where('groups.0.products.0.url', url('/store/vps/cloud?domain=asha.com.np')));
});

it('answers 404 for unknown and hidden groups, and for unknown, retired or wrongly spelled products', function () {
    $hidden = ProductGroup::factory()->create(['slug' => 'secret', 'is_hidden' => true]);
    Product::factory()->for($hidden, 'group')->withPrice('USD', BillingCycle::Monthly, 100)->create(['slug' => 'quiet']);
    $retired = Product::factory()->for($this->webGroup, 'group')->withPrice('USD', BillingCycle::Monthly, 100)->create(['slug' => 'old-plan', 'is_retired' => true]);

    $this->get('/store/nope')->assertNotFound();
    $this->get('/store/secret')->assertNotFound();
    $this->get('/store/web-hosting/nope')->assertNotFound();
    $this->get('/store/web-hosting/old-plan')->assertNotFound();
    $this->get("/store/products/{$retired->id}")->assertNotFound();
    $this->get('/store/products/999999')->assertNotFound();
    $this->get('/store/Web_Hosting!')->assertNotFound();
});

it('keeps the fixed store paths ahead of the group slug', function () {
    $this->get('/store/cart')->assertOk()->assertInertia(fn (Assert $page) => $page->component('public/cart/index'));
    $this->get('/store/checkout')->assertRedirect();
    $this->get('/store/domains')->assertOk()->assertInertia(fn (Assert $page) => $page->component('public/store/domains'));
    $this->get('/store/products')->assertNotFound();
    $this->put('/store/currency', ['currency' => 'USD'])->assertRedirect();
});

it('uses the slug URL for the store sitemap and the cart edit link', function () {
    $this->get('/sitemap.xml')->assertOk()
        ->assertSee(url('/store/vps'), false)
        ->assertSee(url('/store/vps/cloud'), false);

    $this->post(route('store.cart.products.store', $this->cloud), ['cycle' => 'monthly'])->assertRedirect();
    $this->get(route('store.cart'))->assertInertia(fn (Assert $page) => $page
        ->where('cart.items.0.product_url', fn ($url) => str_starts_with($url, url('/store/vps/cloud?item='))));
});

it('refuses the same reserved words as a group slug that the store routes keep', function () {
    expect(ProductGroupRequest::RESERVED_SLUGS)->toEqualCanonicalizing(StoreController::RESERVED_SLUGS);
});
