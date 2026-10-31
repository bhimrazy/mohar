<?php

use App\Contracts\Catalog\PriceBook;
use App\Enums\BillingCycle;
use App\Modules\Catalog\Enums\AutoSetup;
use App\Modules\Catalog\Models\ConfigOptionGroup;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductCustomField;
use App\Modules\Catalog\Models\ProductGroup;
use App\Modules\Core\Models\Currency;
use App\Modules\Core\Support\Settings;
use Illuminate\Database\UniqueConstraintViolationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Fakes\Catalog\FakeCatalogUsage;

beforeEach(function () {
    Currency::factory()->create();
});

/**
 * @return array<string, mixed>
 */
function productPayload(ProductGroup $group, array $overrides = []): array
{
    return [
        'product_group_id' => $group->id,
        'type' => 'shared_hosting',
        'name' => 'Starter',
        'slug' => '',
        'payment_type' => 'recurring',
        'is_taxable' => true,
        'quantity_mode' => 'single',
        'recurring_cycles_limit' => 0,
        'fixed_term_days' => 0,
        'auto_setup' => 'on_payment',
        'prorata_day' => 1,
        'prorata_charge_next_month_day' => 0,
        'free_domain' => 'none',
        ...$overrides,
    ];
}

it('forbids listing products without permission', function () {
    actingAsStaff(['catalog.addons.view']);

    $this->get(route('admin.catalog.products.index'))->assertForbidden();
});

it('lists products with price, stock, auto-setup and active service counts', function () {
    actingAsStaff(['catalog.products.view']);
    $product = Product::factory()->stock(3)
        ->withPrice('USD', BillingCycle::Annually, 9900)
        ->withPrice('USD', BillingCycle::Monthly, 999)
        ->create(['is_taxable' => false]);
    FakeCatalogUsage::bind(new FakeCatalogUsage(activeCounts: [$product->id => 7]));

    $this->get(route('admin.catalog.products.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/catalog/products/index')
            ->has('products.data', 1)
            ->where('products.data.0.id', $product->id)
            ->where('products.data.0.price_from.amount', 999)
            ->where('products.data.0.price_from.cycle', 'monthly')
            ->where('products.data.0.stock', 3)
            ->where('products.data.0.is_taxable', false)
            ->where('products.data.0.auto_setup', 'on_payment')
            ->where('products.data.0.active_services', 7)
            ->where('currency', 'USD'));
});

it('filters products by group, type, status and search', function (array $query, string $expected) {
    actingAsStaff(['catalog.products.view']);
    $vps = ProductGroup::factory()->create();
    Product::factory()->create(['name' => 'Starter Plan']);
    Product::factory()->for($vps, 'group')->create(['name' => 'VPS Small', 'type' => 'server']);
    Product::factory()->retired()->create(['name' => 'Legacy Plan']);
    Product::factory()->hidden()->create(['name' => 'Secret Plan']);

    $query = array_map(fn ($value) => $value === ':vps' ? $vps->id : $value, $query);

    $this->get(route('admin.catalog.products.index', $query))
        ->assertInertia(fn (Assert $page) => $page->has('products.data', 1)->where('products.data.0.name', $expected));
})->with([
    'group' => [['group' => ':vps'], 'VPS Small'],
    'type' => [['type' => 'server'], 'VPS Small'],
    'retired' => [['status' => 'retired'], 'Legacy Plan'],
    'hidden' => [['status' => 'hidden'], 'Secret Plan'],
    'search is case-insensitive' => [['search' => 'starter'], 'Starter Plan'],
]);

it('prefills new products with the catalog settings defaults', function () {
    actingAsStaff(['catalog.products.manage']);
    app(Settings::class)->setMany(['catalog.default_taxable' => false, 'catalog.default_auto_setup' => 'on_accept']);

    $this->get(route('admin.catalog.products.create'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/catalog/products/create')
            ->where('defaults.is_taxable', false)
            ->where('defaults.auto_setup', 'on_accept'));
});

it('creates a product and continues to its pricing tab', function () {
    actingAsStaff(['catalog.products.manage']);
    $group = ProductGroup::factory()->create();

    $response = $this->post(route('admin.catalog.products.store'), productPayload($group, [
        'stock_enabled' => true,
        'stock_quantity' => 10,
        'free_domain' => 'once',
        'free_domain_tlds' => 'COM, .net',
        'free_domain_cycles' => ['annually'],
        'welcome_email_key' => 'hosting.welcome',
    ]));

    $product = Product::query()->where('name', 'Starter')->firstOrFail();
    $response->assertRedirect(route('admin.catalog.products.edit', ['product' => $product, 'tab' => 'pricing']));
    expect($product)
        ->slug->toBe('starter')
        ->stock_quantity->toBe(10)
        ->free_domain_tlds->toBe(['.com', '.net'])
        ->auto_setup->toBe(AutoSetup::OnPayment)
        ->welcome_email_key->toBe('hosting.welcome');
    $this->assertDatabaseHas('activity_log', ['description' => 'Product created', 'subject_id' => $product->id]);
});

it('validates product details', function () {
    actingAsStaff(['catalog.products.manage']);

    $this->post(route('admin.catalog.products.store'), [
        'type' => 'spaceship',
        'payment_type' => 'sometimes',
        'prorata_day' => 40,
        'prorata_charge_next_month_day' => 33,
        'stock_enabled' => true,
        'free_domain_tlds' => ['not a tld'],
    ])->assertSessionHasErrors(['product_group_id', 'type', 'name', 'payment_type', 'prorata_day', 'prorata_charge_next_month_day', 'stock_quantity', 'free_domain_tlds.0']);
});

it('refuses multiple services per line for products with configurable options or required fields', function () {
    actingAsStaff(['catalog.products.manage']);
    $product = Product::factory()->create();
    $product->configOptionGroups()->attach(ConfigOptionGroup::factory()->create());
    $withField = Product::factory()->create();
    ProductCustomField::factory()->for($withField)->create(['is_required' => true]);

    foreach ([$product, $withField] as $subject) {
        $this->put(route('admin.catalog.products.update', $subject), productPayload($subject->group, ['name' => $subject->name, 'slug' => $subject->slug, 'quantity_mode' => 'multiple_services']))
            ->assertSessionHasErrors('quantity_mode');
    }
});

it('retires a product and logs it', function () {
    actingAsStaff(['catalog.products.manage']);
    $product = Product::factory()->create();

    $this->put(route('admin.catalog.products.update', $product), productPayload($product->group, ['name' => $product->name, 'slug' => $product->slug, 'is_retired' => true]))
        ->assertRedirect(route('admin.catalog.products.edit', ['product' => $product, 'tab' => 'details']));

    expect($product->fresh()->is_retired)->toBeTrue();
    $this->assertDatabaseHas('activity_log', ['description' => 'Product retired', 'subject_id' => $product->id]);
});

it('renders the edit page with every tab dataset', function () {
    actingAsStaff(['catalog.products.view']);
    $product = Product::factory()->withPrice('USD', BillingCycle::Monthly, 1000, 500)->create();
    FakeCatalogUsage::bind(new FakeCatalogUsage(activeCounts: [$product->id => 2]));

    $this->get(route('admin.catalog.products.edit', ['product' => $product, 'tab' => 'pricing']))
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/catalog/products/edit')
            ->where('product.id', $product->id)
            ->where('tab', 'pricing')
            ->where('activeServices', 2)
            ->where('canManage', false)
            ->has('pricing.cycles', 6)
            ->where('pricing.rows.0', ['currency' => 'USD', 'cycle' => 'monthly', 'enabled' => true, 'amount' => 1000, 'setup_fee' => 500, 'manual' => false])
            ->has('currencies', 1)
            ->has('configOptionGroups')
            ->has('customFields')
            ->has('addons')
            ->has('upgradeCandidates')
            ->has('drivers'));
});

it('refuses to delete a product with services and asks to retire it', function () {
    actingAsStaff(['catalog.products.manage']);
    $product = Product::factory()->create(['name' => 'Pro']);
    FakeCatalogUsage::bind(new FakeCatalogUsage(productsInUse: [$product->id]));

    $this->delete(route('admin.catalog.products.destroy', $product))
        ->assertSessionHasErrors(['product' => 'Pro has services, so it can\'t be deleted. Retire it instead.']);

    $this->assertNotSoftDeleted($product);
});

it('deletes a product without services and removes paths pointing to it', function () {
    actingAsStaff(['catalog.products.manage']);
    $product = Product::factory()->create();
    $source = Product::factory()->create();
    $source->upgradeTargets()->attach($product);

    $this->delete(route('admin.catalog.products.destroy', $product))->assertRedirect(route('admin.catalog.products.index'));

    $this->assertSoftDeleted($product);
    $this->assertDatabaseMissing('product_upgrade_paths', ['target_product_id' => $product->id]);
});

it('gives the slug of a deleted product to a new one, never the slug of a live one', function () {
    actingAsStaff(['catalog.products.manage']);
    $group = ProductGroup::factory()->create();
    Product::factory()->create(['slug' => 'starter'])->delete();
    Product::factory()->create(['slug' => 'pro']);

    $this->post(route('admin.catalog.products.store'), productPayload($group, ['slug' => 'starter']))->assertSessionHasNoErrors();
    $this->post(route('admin.catalog.products.store'), productPayload($group, ['name' => 'Pro', 'slug' => 'pro']))->assertSessionHasErrors('slug');

    expect(Product::withTrashed()->where('slug', 'starter')->count())->toBe(2)
        ->and(fn () => Product::factory()->create(['slug' => 'starter']))->toThrow(UniqueConstraintViolationException::class);
});

it('duplicates a product as a hidden copy with prices and relations', function () {
    actingAsStaff(['catalog.products.manage']);
    $product = Product::factory()->stock(5)->withPrice('USD', BillingCycle::Monthly, 1000)->create(['name' => 'Pro', 'slug' => 'pro']);
    $product->configOptionGroups()->attach($group = ConfigOptionGroup::factory()->create());
    $product->upgradeTargets()->attach($target = Product::factory()->create());
    ProductCustomField::factory()->for($product)->create(['name' => 'Username', 'type' => 'dropdown', 'options' => ['a', 'b']]);

    $this->post(route('admin.catalog.products.duplicate', $product))->assertRedirect();

    $copy = Product::query()->where('slug', 'pro-copy')->firstOrFail();
    expect($copy)
        ->name->toBe('Pro (copy)')
        ->is_hidden->toBeTrue()
        ->stock_quantity->toBe(0)
        ->and($copy->prices()->first()?->amount)->toBe(1000)
        ->and($copy->configOptionGroups()->pluck('config_option_groups.id')->all())->toBe([$group->id])
        ->and($copy->upgradeTargets()->pluck('products.id')->all())->toBe([$target->id])
        ->and($copy->customFields()->first()?->options)->toBe(['a', 'b']);
});

it('keeps stock reserved by checkouts when staff save the details form', function () {
    actingAsStaff(['catalog.products.manage']);
    $product = Product::factory()->stock(5)->create();
    $loadedStock = $product->stock_quantity;

    app(PriceBook::class)->reserveStock($product->id);
    app(PriceBook::class)->reserveStock($product->id);
    app(PriceBook::class)->reserveStock($product->id);

    $this->put(route('admin.catalog.products.update', $product), productPayload($product->group, [
        'name' => 'Typo fixed', 'slug' => $product->slug, 'stock_enabled' => true, 'stock_quantity' => $loadedStock,
    ]))->assertSessionHasNoErrors();

    expect($product->fresh())->name->toBe('Typo fixed')->stock_quantity->toBe(2);
});

it('adjusts stock relative to the live quantity', function () {
    actingAsStaff(['catalog.products.manage']);
    $product = Product::factory()->stock(5)->create();
    app(PriceBook::class)->reserveStock($product->id);

    $this->post(route('admin.catalog.products.stock.adjust', $product), ['adjustment' => 10, 'reason' => 'Delivery'])
        ->assertRedirect(route('admin.catalog.products.edit', ['product' => $product, 'tab' => 'details']));
    $this->post(route('admin.catalog.products.stock.adjust', $product), ['adjustment' => -4])->assertSessionHasNoErrors();

    expect($product->fresh()->stock_quantity)->toBe(10);
    $this->assertDatabaseHas('activity_log', ['description' => 'Product stock adjusted', 'subject_id' => $product->id]);
});

it('refuses to remove more stock than is left', function () {
    actingAsStaff(['catalog.products.manage']);
    $product = Product::factory()->stock(2)->create();

    $this->post(route('admin.catalog.products.stock.adjust', $product), ['adjustment' => -3])
        ->assertSessionHasErrors(['adjustment' => 'Only 2 in stock, so you can remove at most 2.']);
    $this->post(route('admin.catalog.products.stock.adjust', $product), ['adjustment' => 0])
        ->assertSessionHasErrors(['adjustment' => 'Enter a number other than 0.']);

    expect($product->fresh()->stock_quantity)->toBe(2);
});

it('forbids stock adjustments without manage permission and for products without stock control', function () {
    actingAsStaff(['catalog.products.view']);
    $product = Product::factory()->stock(2)->create();
    $this->post(route('admin.catalog.products.stock.adjust', $product), ['adjustment' => 1])->assertForbidden();

    actingAsStaff(['catalog.products.manage']);
    $this->post(route('admin.catalog.products.stock.adjust', Product::factory()->create()), ['adjustment' => 1])->assertNotFound();
});
