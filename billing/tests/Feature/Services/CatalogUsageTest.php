<?php

use App\Contracts\Services\CatalogUsage;
use App\Modules\Core\Models\Currency;
use App\Modules\Services\Enums\ServiceStatus;
use App\Modules\Services\Models\Service;
use App\Modules\Services\Models\ServiceAddon;
use App\Modules\Services\Services\EloquentCatalogUsage;

beforeEach(function () {
    Currency::factory()->create();
});

test('the services module implements catalog usage', function () {
    expect(app(CatalogUsage::class))->toBeInstanceOf(EloquentCatalogUsage::class);
});

test('active services are counted per product', function () {
    Service::factory()->count(2)->create(['product_id' => 41]);
    Service::factory()->suspended()->create(['product_id' => 41]);
    Service::factory()->create(['product_id' => 42]);
    Service::factory()->create(['product_id' => 43]);
    Service::factory()->create(['product_id' => 41])->delete();

    expect(app(CatalogUsage::class)->activeServiceCountsByProduct([41, 42, 44]))->toBe([41 => 2, 42 => 1])
        ->and(app(CatalogUsage::class)->activeServiceCountsByProduct([]))->toBe([]);
});

test('a product is in use by any non-deleted service regardless of status', function () {
    Service::factory()->status(ServiceStatus::Terminated)->create(['product_id' => 51]);
    Service::factory()->create(['product_id' => 52])->delete();

    expect(app(CatalogUsage::class)->productInUse(51))->toBeTrue()
        ->and(app(CatalogUsage::class)->productInUse(52))->toBeFalse()
        ->and(app(CatalogUsage::class)->productInUse(53))->toBeFalse();
});

test('config option values are found in service selections', function () {
    Service::factory()->create()->syncConfigOptions([7 => 70, 8 => 3]);
    $deleted = Service::factory()->create();
    $deleted->syncConfigOptions([9 => 90]);
    $deleted->delete();

    $usage = app(CatalogUsage::class);

    expect($usage->configOptionValueInUse(7, 70))->toBeTrue()
        ->and($usage->configOptionValueInUse(7, 71))->toBeFalse()
        ->and($usage->configOptionValueInUse(8, 3))->toBeTrue()
        ->and($usage->configOptionValueInUse(70, 7))->toBeFalse()
        ->and($usage->configOptionValueInUse(9, 90))->toBeFalse();
});

test('catalog addons are in use by addons of non-deleted services', function () {
    ServiceAddon::factory()->create(['addon_id' => 61]);
    $deleted = ServiceAddon::factory()->create(['addon_id' => 62]);
    $deleted->service->delete();

    expect(app(CatalogUsage::class)->addonInUse(61))->toBeTrue()
        ->and(app(CatalogUsage::class)->addonInUse(62))->toBeFalse()
        ->and(app(CatalogUsage::class)->addonInUse(63))->toBeFalse();
});
