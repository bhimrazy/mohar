<?php

use App\Enums\BillingCycle;
use App\Modules\Catalog\Enums\ConfigOptionType;
use App\Modules\Catalog\Models\ConfigOption;
use App\Modules\Catalog\Models\ConfigOptionGroup;
use App\Modules\Catalog\Models\ConfigOptionValue;
use App\Modules\Catalog\Models\Price;
use App\Modules\Catalog\Models\Product;
use App\Modules\Core\Models\Currency;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Fakes\Catalog\FakeCatalogUsage;

beforeEach(function () {
    Currency::factory()->create();
});

it('forbids staff without option permissions', function () {
    actingAsStaff(['catalog.products.*']);
    $group = ConfigOptionGroup::factory()->create();

    $this->get(route('admin.catalog.option-groups.index'))->assertForbidden();
    $this->post(route('admin.catalog.option-groups.options.store', $group), ['name' => 'RAM', 'type' => 'dropdown'])->assertForbidden();
});

it('lists option groups with counts', function () {
    actingAsStaff(['catalog.options.view']);
    $group = ConfigOptionGroup::factory()->create(['name' => 'VPS resources']);
    ConfigOption::factory()->for($group, 'group')->create(['name' => 'RAM']);
    $group->products()->attach(Product::factory()->create());

    $this->get(route('admin.catalog.option-groups.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/catalog/option-groups/index')
            ->where('groups.data.0.name', 'VPS resources')
            ->where('groups.data.0.options_count', 1)
            ->where('groups.data.0.products_count', 1)
            ->where('groups.data.0.option_names', ['RAM']));
});

it('creates a group and opens its editor', function () {
    actingAsStaff(['catalog.options.manage']);

    $response = $this->post(route('admin.catalog.option-groups.store'), ['name' => 'VPS resources', 'product_ids' => []]);

    $group = ConfigOptionGroup::query()->firstOrFail();
    $response->assertRedirect(route('admin.catalog.option-groups.edit', $group));
});

it('shows options with values and prices in the editor', function () {
    actingAsStaff(['catalog.options.view']);
    $option = ConfigOption::factory()->create(['name' => 'RAM']);
    ConfigOptionValue::factory()->for($option, 'option')->withPrice('USD', BillingCycle::Monthly, 500)->create(['label' => '4 GB']);

    $this->get(route('admin.catalog.option-groups.edit', $option->config_option_group_id))
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/catalog/option-groups/edit')
            ->where('options.0.name', 'RAM')
            ->where('options.0.values.0.label', '4 GB')
            ->where('options.0.values.0.prices.0.amount', 500)
            ->has('cycles', 7)
            ->where('canManage', false));
});

it('assigns products only when the options are priced for their cycles', function () {
    actingAsStaff(['catalog.options.manage']);
    $option = ConfigOption::factory()->create(['name' => 'RAM']);
    ConfigOptionValue::factory()->for($option, 'option')->withPrice('USD', BillingCycle::Monthly, 500)->create();
    $priced = Product::factory()->withPrice('USD', BillingCycle::Monthly, 1000)->create();
    $unpriced = Product::factory()->withPrice('USD', BillingCycle::Annually, 9000)->create(['name' => 'Annual VPS']);

    $this->put(route('admin.catalog.option-groups.update', $option->config_option_group_id), ['name' => 'G', 'product_ids' => [$priced->id, $unpriced->id]])
        ->assertSessionHasErrors('product_ids');

    $this->put(route('admin.catalog.option-groups.update', $option->config_option_group_id), ['name' => 'G', 'product_ids' => [$priced->id]])
        ->assertSessionHasNoErrors();
    expect($option->group->products()->pluck('products.id')->all())->toBe([$priced->id]);
});

it('creates the single value row for yes/no and quantity options', function (string $type, string $label) {
    actingAsStaff(['catalog.options.manage']);
    $group = ConfigOptionGroup::factory()->create();

    $this->post(route('admin.catalog.option-groups.options.store', $group), ['name' => 'Opt', 'type' => $type, 'quantity_min' => 0, 'quantity_max' => 8])->assertRedirect();

    $option = ConfigOption::query()->firstOrFail();
    expect($option->values()->pluck('label')->all())->toBe([$label]);
})->with([
    'yes/no' => ['yes_no', 'Yes'],
    'quantity' => ['quantity', 'Unit'],
]);

it('requires quantity min and max together', function () {
    actingAsStaff(['catalog.options.manage']);
    $group = ConfigOptionGroup::factory()->create();

    $this->post(route('admin.catalog.option-groups.options.store', $group), ['name' => 'IPs', 'type' => 'quantity', 'quantity_min' => 1])
        ->assertSessionHasErrors(['quantity_max' => 'Set both a minimum and a maximum, or leave both empty.']);
    $this->post(route('admin.catalog.option-groups.options.store', $group), ['name' => 'IPs', 'type' => 'quantity', 'quantity_min' => 5, 'quantity_max' => 2])
        ->assertSessionHasErrors(['quantity_max' => 'The maximum must be at least the minimum.']);
});

it('refuses turning a multi-value option into a single-value type', function () {
    actingAsStaff(['catalog.options.manage']);
    $option = ConfigOption::factory()->create();
    ConfigOptionValue::factory()->count(2)->for($option, 'option')->create();

    $this->put(route('admin.catalog.options.update', $option), ['name' => 'X', 'type' => 'yes_no'])->assertSessionHasErrors('type');
});

it('adds a value with its price matrix', function () {
    actingAsStaff(['catalog.options.manage']);
    $option = ConfigOption::factory()->create();

    $this->post(route('admin.catalog.options.values.store', $option), [
        'label' => '8 GB',
        'module_value' => '8192',
        'prices' => [
            ['currency' => 'USD', 'cycle' => 'monthly', 'enabled' => true, 'amount' => 800, 'setup_fee' => 0],
            ['currency' => 'USD', 'cycle' => 'onetime', 'enabled' => true, 'amount' => 2000, 'setup_fee' => 0],
        ],
    ])->assertRedirect(route('admin.catalog.option-groups.edit', $option->config_option_group_id));

    $value = ConfigOptionValue::query()->where('label', '8 GB')->firstOrFail();
    expect($value->module_value)->toBe('8192')->and($value->prices()->count())->toBe(2);
});

it('refuses a second value on a single-value option', function () {
    actingAsStaff(['catalog.options.manage']);
    $option = ConfigOption::factory()->yesNo()->create();
    ConfigOptionValue::factory()->for($option, 'option')->create();

    $this->post(route('admin.catalog.options.values.store', $option), ['label' => 'Also yes', 'prices' => []])->assertSessionHasErrors('label');
});

it('updates a value price matrix and hides it', function () {
    actingAsStaff(['catalog.options.manage']);
    $value = ConfigOptionValue::factory()->withPrice('USD', BillingCycle::Monthly, 500)->create();

    $this->put(route('admin.catalog.values.update', $value), [
        'label' => $value->label,
        'is_hidden' => true,
        'prices' => [['currency' => 'USD', 'cycle' => 'monthly', 'enabled' => true, 'amount' => 650, 'setup_fee' => 100]],
    ])->assertRedirect();

    expect($value->fresh()->is_hidden)->toBeTrue()
        ->and($value->prices()->first()?->amount)->toBe(650);
});

it('refuses to delete a value selected by services and asks to hide it', function () {
    actingAsStaff(['catalog.options.manage']);
    $option = ConfigOption::factory()->create(['name' => 'RAM']);
    $value = ConfigOptionValue::factory()->for($option, 'option')->create(['label' => '4 GB']);
    FakeCatalogUsage::bind(new FakeCatalogUsage(valuesInUse: [[$option->id, $value->id]]));

    $this->delete(route('admin.catalog.values.destroy', $value))
        ->assertSessionHasErrors(['value' => 'RAM › 4 GB is selected by existing services, so it can\'t be deleted. Hide it instead.']);
    $this->delete(route('admin.catalog.options.destroy', $option))->assertSessionHasErrors('value');
    $this->delete(route('admin.catalog.option-groups.destroy', $option->config_option_group_id))->assertSessionHasErrors('value');

    $this->assertModelExists($value);
});

it('deletes unused values with their prices', function () {
    actingAsStaff(['catalog.options.manage']);
    $value = ConfigOptionValue::factory()->withPrice('USD', BillingCycle::Monthly, 500)->create();
    FakeCatalogUsage::bind();

    $this->delete(route('admin.catalog.values.destroy', $value))->assertRedirect();

    $this->assertModelMissing($value);
    expect(Price::query()->count())->toBe(0);
});

it('deletes a whole option group when nothing is in use', function () {
    actingAsStaff(['catalog.options.manage']);
    $option = ConfigOption::factory()->create();
    ConfigOptionValue::factory()->for($option, 'option')->withPrice('USD', BillingCycle::Monthly, 500)->create();
    FakeCatalogUsage::bind();

    $this->delete(route('admin.catalog.option-groups.destroy', $option->config_option_group_id))->assertRedirect(route('admin.catalog.option-groups.index'));

    $this->assertModelMissing($option);
    expect(Price::query()->count())->toBe(0);
});

it('cannot delete the only value of a single-value option', function () {
    actingAsStaff(['catalog.options.manage']);
    $option = ConfigOption::factory()->create(['type' => ConfigOptionType::Quantity]);
    $value = ConfigOptionValue::factory()->for($option, 'option')->create();

    $this->delete(route('admin.catalog.values.destroy', $value))->assertSessionHasErrors('value');
});

it('refuses a visible value without prices for cycles its products sell', function () {
    actingAsStaff(['catalog.options.manage']);
    $option = ConfigOption::factory()->create();
    $product = Product::factory()->withPrice('USD', BillingCycle::Monthly, 1000)->create(['name' => 'VPS']);
    $product->configOptionGroups()->attach($option->config_option_group_id);
    $prices = [['currency' => 'USD', 'cycle' => 'annually', 'enabled' => true, 'amount' => 900, 'setup_fee' => 0]];

    $this->post(route('admin.catalog.options.values.store', $option), ['label' => '8 GB', 'prices' => $prices])
        ->assertSessionHasErrors(['prices' => 'Products using this option sell cycles this value has no price for: VPS (USD, monthly). Price them or hide the value.']);
    $this->post(route('admin.catalog.options.values.store', $option), ['label' => '8 GB', 'is_hidden' => true, 'prices' => $prices])
        ->assertSessionHasNoErrors();
});

it('hides retired products from the assignment list unless already assigned', function () {
    actingAsStaff(['catalog.options.view']);
    $group = ConfigOptionGroup::factory()->create();
    $assigned = Product::factory()->retired()->create();
    $group->products()->attach($assigned);
    Product::factory()->retired()->create();
    $active = Product::factory()->create();

    $this->get(route('admin.catalog.option-groups.edit', $group))
        ->assertInertia(fn (Assert $page) => $page->where('products', fn ($products) => collect($products)->pluck('id')->sort()->values()->all() === collect([$assigned->id, $active->id])->sort()->values()->all()));
});

it('refuses to unhide an option that is not priced for the cycles its products sell', function () {
    actingAsStaff(['catalog.options.manage']);
    $option = ConfigOption::factory()->hidden()->create(['name' => 'RAM']);
    ConfigOptionValue::factory()->for($option, 'option')->withPrice('USD', BillingCycle::Annually, 900)->create(['label' => '8 GB']);
    $product = Product::factory()->withPrice('USD', BillingCycle::Monthly, 1000)->create(['name' => 'VPS']);
    $product->configOptionGroups()->attach($option->config_option_group_id);

    $this->put(route('admin.catalog.options.update', $option), ['name' => 'RAM', 'type' => 'dropdown', 'is_hidden' => false])
        ->assertSessionHasErrors(['is_hidden' => 'Price this option for every cycle VPS sells before showing it. Missing: RAM › 8 GB (USD, monthly)']);
    $this->put(route('admin.catalog.options.update', $option), ['name' => 'RAM', 'type' => 'dropdown', 'is_hidden' => true])
        ->assertSessionHasNoErrors();

    expect($option->fresh()->is_hidden)->toBeTrue();
});
