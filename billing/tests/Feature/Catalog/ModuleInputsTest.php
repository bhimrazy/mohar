<?php

use App\Contracts\Catalog\PriceBook;
use App\Modules\Catalog\Enums\ConfigOptionType;
use App\Modules\Catalog\Enums\CustomFieldType;
use App\Modules\Catalog\Models\ConfigOption;
use App\Modules\Catalog\Models\ConfigOptionGroup;
use App\Modules\Catalog\Models\ConfigOptionValue;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductCustomField;

test('module inputs map selections and custom fields by module key, hidden, admin-only and retired included', function () {
    $product = Product::factory()->create(['is_retired' => true]);
    $group = ConfigOptionGroup::factory()->create();
    $product->configOptionGroups()->attach($group);

    $os = ConfigOption::factory()->create(['config_option_group_id' => $group->id, 'module_key' => 'os', 'type' => ConfigOptionType::Dropdown, 'is_hidden' => true]);
    $ubuntu = ConfigOptionValue::factory()->create(['config_option_id' => $os->id, 'label' => 'Ubuntu 24.04', 'module_value' => 'ubuntu-2404', 'is_hidden' => true]);
    $plain = ConfigOption::factory()->create(['config_option_group_id' => $group->id, 'module_key' => 'region', 'type' => ConfigOptionType::Radio]);
    $europe = ConfigOptionValue::factory()->create(['config_option_id' => $plain->id, 'label' => 'Europe', 'module_value' => null]);
    $ram = ConfigOption::factory()->create(['config_option_group_id' => $group->id, 'module_key' => 'ram_gb', 'type' => ConfigOptionType::Quantity]);
    $backups = ConfigOption::factory()->create(['config_option_group_id' => $group->id, 'module_key' => 'backups', 'type' => ConfigOptionType::YesNo]);
    $unkeyed = ConfigOption::factory()->create(['config_option_group_id' => $group->id, 'module_key' => null]);

    $hostname = ProductCustomField::factory()->create(['product_id' => $product->id, 'module_key' => 'hostname', 'type' => CustomFieldType::Text, 'is_admin_only' => true, 'show_on_order' => false]);
    $pin = ProductCustomField::factory()->create(['product_id' => $product->id, 'module_key' => null, 'type' => CustomFieldType::Password]);

    $inputs = app(PriceBook::class)->moduleInputs(
        $product->id,
        [$os->id => $ubuntu->id, $plain->id => $europe->id, $ram->id => 4, $backups->id => 0, $unkeyed->id => 1],
        [$hostname->id => 'vps1.example.com', $pin->id => 'pin-4242'],
    );

    expect($inputs->configOptions)->toBe(['os' => 'ubuntu-2404', 'region' => 'Europe', 'ram_gb' => 4, 'backups' => '0'])
        ->and($inputs->customFields)->toBe(['hostname' => 'vps1.example.com'])
        ->and($inputs->secretValues)->toBe(['pin-4242'])
        ->and(app(PriceBook::class)->moduleInputs(999_999, [], [])->configOptions)->toBe([]);
});
