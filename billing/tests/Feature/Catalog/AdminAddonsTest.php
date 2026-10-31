<?php

use App\Enums\BillingCycle;
use App\Modules\Catalog\Models\Addon;
use App\Modules\Catalog\Models\Product;
use App\Modules\Core\Models\Currency;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Fakes\Catalog\FakeCatalogUsage;
use Tests\Fakes\Catalog\FakeProvisioningDrivers;

beforeEach(function () {
    Currency::factory()->create();
});

/**
 * @return array<string, mixed>
 */
function addonPayload(array $overrides = []): array
{
    return [
        'name' => 'Daily backups',
        'kind' => 'feature',
        'payment_type' => 'recurring',
        'auto_setup' => 'on_payment',
        'provisioning_driver' => 'none',
        'is_taxable' => true,
        'show_on_order' => true,
        'driver_config' => [],
        'product_ids' => [],
        'prices' => [],
        ...$overrides,
    ];
}

it('forbids staff without addon permissions', function () {
    actingAsStaff(['catalog.products.*']);

    $this->get(route('admin.catalog.addons.index'))->assertForbidden();
    $this->post(route('admin.catalog.addons.store'), addonPayload())->assertForbidden();
});

it('lists addons with their cheapest default-currency price', function () {
    actingAsStaff(['catalog.addons.view']);
    $addon = Addon::factory()->withPrice('USD', BillingCycle::Annually, 3000)->withPrice('USD', BillingCycle::Monthly, 300)->create();
    Addon::factory()->retired()->create();

    $this->get(route('admin.catalog.addons.index', ['status' => 'active']))
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/catalog/addons/index')
            ->has('addons.data', 1)
            ->where('addons.data.0.id', $addon->id)
            ->where('addons.data.0.price_from.amount', 300));
});

it('creates an addon with prices, products and driver settings', function () {
    actingAsStaff(['catalog.addons.manage']);
    $product = Product::factory()->create();

    $this->post(route('admin.catalog.addons.store'), addonPayload([
        'product_ids' => [$product->id],
        'driver_config' => [['key' => 'retention', 'value' => '7']],
        'prices' => [['currency' => 'USD', 'cycle' => 'monthly', 'enabled' => true, 'amount' => 300, 'setup_fee' => 0]],
    ]))->assertRedirect();

    $addon = Addon::query()->where('name', 'Daily backups')->firstOrFail();
    expect($addon->driver_config)->toBe(['retention' => '7'])
        ->and($addon->products()->pluck('products.id')->all())->toBe([$product->id])
        ->and($addon->prices()->first()?->amount)->toBe(300);
});

it('rejects drivers that are not installed when Services reports drivers', function () {
    actingAsStaff(['catalog.addons.manage']);
    FakeProvisioningDrivers::withLogDriver();

    $this->post(route('admin.catalog.addons.store'), addonPayload(['provisioning_driver' => 'cpanel']))->assertSessionHasErrors('provisioning_driver');
    $this->post(route('admin.catalog.addons.store'), addonPayload(['provisioning_driver' => 'log', 'driver_config' => [['key' => 'plan', 'value' => 'backup']]]))->assertSessionHasNoErrors();
});

it('rejects prices for cycles the addon payment type does not use', function () {
    actingAsStaff(['catalog.addons.manage']);

    $this->post(route('admin.catalog.addons.store'), addonPayload([
        'payment_type' => 'one_time',
        'prices' => [['currency' => 'USD', 'cycle' => 'monthly', 'enabled' => true, 'amount' => 300, 'setup_fee' => 0]],
    ]))->assertSessionHasErrors('prices.0.cycle');
});

it('renders the addon editor', function () {
    actingAsStaff(['catalog.addons.view']);
    $addon = Addon::factory()->withPrice('USD', BillingCycle::Monthly, 300)->create();

    $this->get(route('admin.catalog.addons.edit', $addon))
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/catalog/addons/form')
            ->where('addon.id', $addon->id)
            ->where('addon.prices.0.amount', 300)
            ->has('cyclesByPaymentType.recurring', 6)
            ->where('canManage', false));
});

it('refuses to delete an addon used by services', function () {
    actingAsStaff(['catalog.addons.manage']);
    $addon = Addon::factory()->create(['name' => 'Backups']);
    FakeCatalogUsage::bind(new FakeCatalogUsage(addonsInUse: [$addon->id]));

    $this->delete(route('admin.catalog.addons.destroy', $addon))
        ->assertSessionHasErrors(['addon' => 'Backups is used by existing services, so it can\'t be deleted. Retire it instead.']);
});

it('deletes an unused addon', function () {
    actingAsStaff(['catalog.addons.manage']);
    $addon = Addon::factory()->withPrice('USD', BillingCycle::Monthly, 300)->create();

    $this->delete(route('admin.catalog.addons.destroy', $addon))->assertRedirect(route('admin.catalog.addons.index'));

    $this->assertModelMissing($addon);
    $this->assertDatabaseCount('prices', 0);
});

test('a failed add-on save never flashes secret driver settings into the session', function () {
    actingAsStaff(['catalog.addons.manage']);
    FakeProvisioningDrivers::withLogDriver();
    confirmPassword();

    $this->post(route('admin.catalog.addons.store'), addonPayload([
        'name' => '',
        'provisioning_driver' => 'log',
        'driver_config' => [['key' => 'plan', 'value' => 'backup'], ['key' => 'api_token', 'value' => 'Addon-S3cret']],
    ]))->assertSessionHasErrors('name');

    expect(json_encode(session()->all()))->not->toContain('Addon-S3cret')
        ->and(session()->getOldInput('driver_config.0.value'))->toBe('backup');
});
