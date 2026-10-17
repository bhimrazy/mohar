<?php

use App\Contracts\Notifications\MergeFieldRegistry;
use App\Enums\BillingCycle;
use App\Modules\Catalog\Models\Price;
use App\Modules\Catalog\Models\Product;
use App\Modules\Core\Models\Currency;
use App\Modules\Core\Support\Settings;
use App\Support\Navigation\Navigation;
use App\Support\Permissions\PermissionRegistry;
use App\Support\Search\GlobalSearch;
use App\Support\Settings\SettingsRegistry;

it('adds the setup navigation items for permitted staff', function () {
    $user = actingAsStaff(['catalog.products.view']);

    $setup = collect(app(Navigation::class)->forUser('admin', $user))->firstWhere('title', 'Setup');

    expect(array_column($setup['items'], 'title'))->toBe(['Products', 'Product groups']);
});

it('uses navigation icons that the frontend knows', function () {
    $icons = file_get_contents(resource_path('js/lib/nav-icons.ts'));

    foreach (['Package', 'Boxes', 'SlidersHorizontal', 'Puzzle'] as $icon) {
        expect($icons)->toContain("    {$icon},");
    }
});

it('registers every permission the module checks', function () {
    $registry = app(PermissionRegistry::class);

    foreach (['catalog.products.view', 'catalog.products.manage', 'catalog.options.view', 'catalog.options.manage', 'catalog.addons.view', 'catalog.addons.manage', 'catalog.settings.manage'] as $permission) {
        expect($registry->exists($permission))->toBeTrue();
    }
});

it('registers the catalog settings with spec defaults', function () {
    $section = app(SettingsRegistry::class)->sections()['catalog'];

    expect(array_map(fn ($definition) => $definition->key, $section['settings']))
        ->toBe(['catalog.default_auto_setup', 'catalog.default_taxable', 'catalog.auto_convert_prices', 'catalog.show_monthly_breakdown'])
        ->and(config('catalog.default_auto_setup'))->toBe('on_payment')
        ->and(config('catalog.default_taxable'))->toBeTrue()
        ->and(config('catalog.auto_convert_prices'))->toBeFalse();
});

it('finds products in global search for permitted staff only', function () {
    $product = Product::factory()->create(['name' => 'Business Hosting']);

    $staff = actingAsStaff(['catalog.products.view']);
    $results = app(GlobalSearch::class)->search('business', $staff);
    expect(collect($results)->firstWhere('group', 'Products')['results'][0])
        ->toMatchArray(['title' => 'Business Hosting', 'href' => route('admin.catalog.products.edit', $product)]);

    $other = actingAsStaff(['core.clients.view']);
    expect(collect(app(GlobalSearch::class)->search('business', $other))->firstWhere('group', 'Products'))->toBeNull();
});

it('resolves product merge fields', function () {
    $product = Product::factory()->create(['name' => 'VPS M']);

    expect(app(MergeFieldRegistry::class)->resolve('product', $product->id))
        ->toMatchArray(['product_id' => $product->id, 'name' => 'VPS M', 'group_name' => $product->group->name, 'type' => 'shared_hosting']);
});

it('converts prices from the default currency when the setting is on', function () {
    Currency::factory()->create();
    Currency::factory()->euro()->create();
    $product = Product::factory()->withPrice('USD', BillingCycle::Monthly, 999, 500)->withPrice('EUR', BillingCycle::Monthly, 1, 1)->create();

    $this->artisan('automation:run', ['--only' => 'catalog.convert-prices'])->assertSuccessful();
    expect($product->prices()->where('currency', 'EUR')->value('amount'))->toBe(1);

    app(Settings::class)->set('catalog.auto_convert_prices', true);
    $this->artisan('automation:run', ['--only' => 'catalog.convert-prices', '--date' => now()->addDay()->toDateString()])->assertSuccessful();

    $eur = Price::query()->where('currency', 'EUR')->firstOrFail();
    expect($eur->amount)->toBe(919)->and($eur->setup_fee)->toBe(460);
});
