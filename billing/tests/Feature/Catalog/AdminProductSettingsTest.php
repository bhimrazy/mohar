<?php

use App\Enums\BillingCycle;
use App\Modules\Catalog\Enums\QuantityMode;
use App\Modules\Catalog\Models\Addon;
use App\Modules\Catalog\Models\ConfigOption;
use App\Modules\Catalog\Models\ConfigOptionValue;
use App\Modules\Catalog\Models\Price;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductCustomField;
use App\Modules\Core\Models\Currency;

beforeEach(function () {
    Currency::factory()->create();
    Currency::factory()->euro()->create();
});

/**
 * @return array{currency: string, cycle: string, enabled: bool, amount: int|null, setup_fee: int}
 */
function priceRow(string $currency, string $cycle, ?int $amount, int $setup = 0, bool $enabled = true): array
{
    return ['currency' => $currency, 'cycle' => $cycle, 'enabled' => $enabled, 'amount' => $amount, 'setup_fee' => $setup];
}

describe('pricing', function () {
    it('forbids staff without manage permission', function () {
        actingAsStaff(['catalog.products.view']);
        $product = Product::factory()->create();

        $this->put(route('admin.catalog.products.pricing.update', $product), ['prices' => []])->assertForbidden();
    });

    it('replaces the price matrix and logs the changes', function () {
        actingAsStaff(['catalog.products.manage']);
        $product = Product::factory()
            ->withPrice('USD', BillingCycle::Monthly, 1000)
            ->withPrice('USD', BillingCycle::Quarterly, 2700)
            ->create();

        $this->put(route('admin.catalog.products.pricing.update', $product), ['prices' => [
            priceRow('USD', 'monthly', 1200, 500),
            priceRow('EUR', 'annually', 11000, 0, false),
        ]])->assertRedirect(route('admin.catalog.products.edit', ['product' => $product, 'tab' => 'pricing']));

        expect($product->prices()->orderBy('id')->get()->map->toMatrixRow()->all())->toBe([
            priceRow('USD', 'monthly', 1200, 500),
            priceRow('EUR', 'annually', 11000, 0, false),
        ]);
        $this->assertDatabaseHas('activity_log', ['description' => 'Prices updated', 'subject_id' => $product->id]);
    });

    it('rejects negative, fractional and missing amounts, unknown currencies and duplicates', function () {
        actingAsStaff(['catalog.products.manage']);
        $product = Product::factory()->create();

        $this->put(route('admin.catalog.products.pricing.update', $product), ['prices' => [
            priceRow('USD', 'monthly', -1),
            ['currency' => 'USD', 'cycle' => 'annually', 'enabled' => true, 'amount' => 12.5, 'setup_fee' => 0],
            priceRow('USD', 'quarterly', null),
            priceRow('JPY', 'monthly', 100),
            priceRow('EUR', 'monthly', 100),
            priceRow('eur', 'monthly', 100),
        ]])->assertSessionHasErrors([
            'prices.0.amount' => 'Prices can\'t be negative.',
            'prices.1.amount' => 'Enter the price as a whole number of minor units (cents).',
            'prices.2.amount',
            'prices.3.currency' => 'This currency is not configured.',
        ]);
    });

    it('rejects cycles the payment type does not use', function () {
        actingAsStaff(['catalog.products.manage']);
        $product = Product::factory()->oneTime()->create();

        $this->put(route('admin.catalog.products.pricing.update', $product), ['prices' => [priceRow('USD', 'monthly', 100)]])
            ->assertSessionHasErrors(['prices.0.cycle']);
    });

    it('rejects duplicate currency and cycle rows', function () {
        actingAsStaff(['catalog.products.manage']);
        $product = Product::factory()->create();

        $this->put(route('admin.catalog.products.pricing.update', $product), ['prices' => [priceRow('USD', 'monthly', 100), priceRow('USD', 'monthly', 200)]])
            ->assertSessionHasErrors(['prices.1.cycle' => 'Each currency and billing cycle can only be priced once.']);
    });

    it('blocks enabling a cycle that its configurable options are not priced for', function () {
        actingAsStaff(['catalog.products.manage']);
        $product = Product::factory()->create();
        $option = ConfigOption::factory()->create(['name' => 'RAM']);
        ConfigOptionValue::factory()->for($option, 'option')->withPrice('USD', BillingCycle::Monthly, 500)->create(['label' => '4 GB']);
        ConfigOptionValue::factory()->for($option, 'option')->hidden()->create();
        $product->configOptionGroups()->attach($option->config_option_group_id);

        $this->put(route('admin.catalog.products.pricing.update', $product), ['prices' => [priceRow('USD', 'monthly', 1000), priceRow('USD', 'annually', 10000)]])
            ->assertSessionHasErrors(['prices' => 'Price every configurable option for the cycles you enable, or unpriced options would be free. Missing: RAM › 4 GB (USD, annually)']);

        $this->put(route('admin.catalog.products.pricing.update', $product), ['prices' => [priceRow('USD', 'monthly', 1000), priceRow('USD', 'annually', 10000, enabled: false)]])
            ->assertSessionHasNoErrors();
    });

    it('does not change what existing services pay', function () {
        actingAsStaff(['catalog.products.manage']);
        $product = Product::factory()->withPrice('USD', BillingCycle::Monthly, 1000)->create();

        $this->put(route('admin.catalog.products.pricing.update', $product), ['prices' => [priceRow('USD', 'monthly', 1200)]])
            ->assertInertiaFlash('toast.message', "Prices for {$product->name} saved. Existing services keep their current price.");

        expect(Price::query()->count())->toBe(1);
    });
});

describe('configurable options', function () {
    it('assigns option groups priced for every product cycle', function () {
        actingAsStaff(['catalog.products.manage']);
        $product = Product::factory()->withPrice('USD', BillingCycle::Monthly, 1000)->create();
        $option = ConfigOption::factory()->create();
        ConfigOptionValue::factory()->for($option, 'option')->withPrice('USD', BillingCycle::Monthly, 500)->create();

        $this->put(route('admin.catalog.products.config-options.update', $product), ['group_ids' => [$option->config_option_group_id]])
            ->assertRedirect(route('admin.catalog.products.edit', ['product' => $product, 'tab' => 'options']));

        expect($product->configOptionGroups()->pluck('config_option_groups.id')->all())->toBe([$option->config_option_group_id]);
    });

    it('blocks groups missing prices for the product cycles', function () {
        actingAsStaff(['catalog.products.manage']);
        $product = Product::factory()->withPrice('EUR', BillingCycle::Monthly, 1000)->create();
        $option = ConfigOption::factory()->create();
        ConfigOptionValue::factory()->for($option, 'option')->withPrice('USD', BillingCycle::Monthly, 500)->create();

        $this->put(route('admin.catalog.products.config-options.update', $product), ['group_ids' => [$option->config_option_group_id]])
            ->assertSessionHasErrors('group_ids');
    });

    it('blocks options on products that create multiple services per line', function () {
        actingAsStaff(['catalog.products.manage']);
        $product = Product::factory()->create(['quantity_mode' => QuantityMode::MultipleServices]);
        $option = ConfigOption::factory()->create();

        $this->put(route('admin.catalog.products.config-options.update', $product), ['group_ids' => [$option->config_option_group_id]])
            ->assertSessionHasErrors(['group_ids' => 'Products that create multiple services per order line can\'t have configurable options.']);
    });
});

describe('custom fields', function () {
    it('adds, updates and deletes fields', function () {
        actingAsStaff(['catalog.products.manage']);
        $product = Product::factory()->create();

        $this->post(route('admin.catalog.products.fields.store', $product), [
            'name' => 'Site type', 'type' => 'dropdown', 'options' => 'WordPress, Laravel', 'validation_regex' => '/^[A-Za-z]+$/', 'is_required' => true, 'show_on_order' => true,
        ])->assertRedirect(route('admin.catalog.products.edit', ['product' => $product, 'tab' => 'fields']));

        $field = ProductCustomField::query()->where('product_id', $product->id)->firstOrFail();
        expect($field->options)->toBe(['WordPress', 'Laravel'])->and($field->is_required)->toBeTrue();

        $this->put(route('admin.catalog.products.fields.update', [$product, $field]), ['name' => 'Stack', 'type' => 'text', 'is_admin_only' => true])->assertRedirect();
        expect($field->fresh())->name->toBe('Stack')->options->toBeNull()->is_admin_only->toBeTrue();

        $this->delete(route('admin.catalog.products.fields.destroy', [$product, $field]))->assertRedirect();
        $this->assertModelMissing($field);
    });

    it('rejects invalid patterns and dropdowns without choices', function () {
        actingAsStaff(['catalog.products.manage']);
        $product = Product::factory()->create();

        $this->post(route('admin.catalog.products.fields.store', $product), ['name' => 'X', 'type' => 'dropdown', 'validation_regex' => '/[a-z/'])
            ->assertSessionHasErrors([
                'options' => 'List the choices for the dropdown, separated by commas.',
                'validation_regex' => 'Enter a valid regular expression, e.g. /^[a-z0-9]+$/i.',
            ]);
    });

    it('returns 404 for a field of another product', function () {
        actingAsStaff(['catalog.products.manage']);
        $product = Product::factory()->create();
        $foreign = ProductCustomField::factory()->create();

        $this->put(route('admin.catalog.products.fields.update', [$product, $foreign]), ['name' => 'X', 'type' => 'text'])->assertNotFound();
        $this->delete(route('admin.catalog.products.fields.destroy', [$product, $foreign]))->assertNotFound();
    });

    it('refuses required fields on products that create multiple services per line', function () {
        actingAsStaff(['catalog.products.manage']);
        $product = Product::factory()->create(['quantity_mode' => QuantityMode::MultipleServices]);

        $this->post(route('admin.catalog.products.fields.store', $product), ['name' => 'X', 'type' => 'text', 'is_required' => true])
            ->assertSessionHasErrors('is_required');
    });
});

it('syncs applicable addons', function () {
    actingAsStaff(['catalog.products.manage']);
    $product = Product::factory()->create();
    $addons = Addon::factory()->count(2)->create();

    $this->put(route('admin.catalog.products.addons.update', $product), ['addon_ids' => $addons->modelKeys()])->assertRedirect();

    expect($product->addons()->orderBy('addons.id')->pluck('addons.id')->all())->toBe($addons->modelKeys());
});

it('syncs upgrade paths and the config option upgrade flag', function () {
    actingAsStaff(['catalog.products.manage']);
    $product = Product::factory()->create();
    $target = Product::factory()->create();

    $this->put(route('admin.catalog.products.upgrades.update', $product), ['target_ids' => [$target->id], 'allow_config_option_upgrades' => true])->assertRedirect();

    expect($product->upgradeTargets()->pluck('products.id')->all())->toBe([$target->id])
        ->and($product->fresh()->allow_config_option_upgrades)->toBeTrue();
});

it('refuses a product as its own upgrade target', function () {
    actingAsStaff(['catalog.products.manage']);
    $product = Product::factory()->create();

    $this->put(route('admin.catalog.products.upgrades.update', $product), ['target_ids' => [$product->id]])
        ->assertSessionHasErrors(['target_ids.0' => 'A product can\'t be an upgrade of itself.']);
});

it('stores the provisioning binding as given', function () {
    actingAsStaff(['catalog.products.manage']);
    $product = Product::factory()->create();

    $this->put(route('admin.catalog.products.provisioning.update', $product), [
        'provisioning_driver' => 'cpanel',
        'server_group_id' => 3,
        'driver_config' => [['key' => 'package', 'value' => 'starter'], ['key' => 'disk_quota', 'value' => '10240']],
    ])->assertRedirect(route('admin.catalog.products.edit', ['product' => $product, 'tab' => 'provisioning']));

    expect($product->fresh())
        ->provisioning_driver->toBe('cpanel')
        ->server_group_id->toBe(3)
        ->driver_config->toBe(['package' => 'starter', 'disk_quota' => '10240']);
});

it('validates the provisioning binding', function () {
    actingAsStaff(['catalog.products.manage']);
    $product = Product::factory()->create();

    $this->put(route('admin.catalog.products.provisioning.update', $product), [
        'provisioning_driver' => 'cPanel Pro',
        'driver_config' => [['key' => 'a', 'value' => '1'], ['key' => 'a', 'value' => '2']],
    ])->assertSessionHasErrors(['provisioning_driver', 'driver_config.1.key' => 'Each setting name can only be used once.']);
});
