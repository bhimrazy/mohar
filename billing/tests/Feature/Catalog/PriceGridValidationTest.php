<?php

use App\Modules\Catalog\Models\Addon;
use App\Modules\Catalog\Models\ConfigOption;
use App\Modules\Catalog\Models\ConfigOptionValue;
use App\Modules\Catalog\Models\Price;
use App\Modules\Catalog\Models\Product;
use App\Modules\Core\Models\Currency;

/*
 * The grid sends raw text when a price does not parse, so these are the values staff can actually submit.
 */

beforeEach(function () {
    Currency::factory()->create();
    actingAsStaff(['catalog.*']);
});

/**
 * @return array{0: string, 1: array<string, mixed>}
 */
function gridRequest(string $grid, array $row): array
{
    $prices = [['currency' => 'USD', 'cycle' => 'monthly', 'enabled' => true, 'amount' => 1000, 'setup_fee' => 0, ...$row]];

    return match ($grid) {
        'product' => [route('admin.catalog.products.pricing.update', Product::factory()->create()), ['_method' => 'PUT', 'prices' => $prices]],
        'addon' => [route('admin.catalog.addons.update', Addon::factory()->create()), [
            '_method' => 'PUT', 'name' => 'Backups', 'kind' => 'feature', 'payment_type' => 'recurring', 'auto_setup' => 'on_payment',
            'provisioning_driver' => 'none', 'driver_config' => [], 'product_ids' => [], 'prices' => $prices,
        ]],
        'option value' => [route('admin.catalog.options.values.store', ConfigOption::factory()->create()), ['label' => '4 GB', 'prices' => $prices]],
    };
}

dataset('grids', ['product', 'addon', 'option value']);

it('rejects prices that are not amounts with a message staff understand', function (string $grid, mixed $amount, string $message) {
    [$url, $payload] = gridRequest($grid, ['amount' => $amount]);

    $this->post($url, $payload)->assertSessionHasErrors(['prices.0.amount' => $message]);

    expect(Price::query()->count())->toBe(0);
})->with('grids')->with([
    'letters' => ['abc', 'Enter a valid price, such as 12.50.'],
    'comma decimal' => ['12,50', 'Enter a valid price, such as 12.50.'],
    'decimal text' => ['12.50', 'Enter a valid price, such as 12.50.'],
    'empty' => [null, 'Enter a price, or turn this cycle off.'],
    'negative' => [-100, 'Prices can\'t be negative.'],
    'overflow' => [100_000_000_001, 'This price is too large.'],
    'beyond integer range' => ['99999999999999999999', 'Enter a valid price, such as 12.50.'],
]);

it('rejects an invalid setup fee on the setup fee field', function (string $grid, mixed $setupFee, string $message) {
    [$url, $payload] = gridRequest($grid, ['setup_fee' => $setupFee]);

    $this->post($url, $payload)->assertSessionHasErrors(['prices.0.setup_fee' => $message])->assertSessionDoesntHaveErrors('prices.0.amount');
})->with('grids')->with([
    'letters' => ['xyz', 'Enter a valid setup fee, such as 5.00, or leave it empty.'],
    'negative' => [-1, 'Setup fees can\'t be negative.'],
]);

it('saves valid amounts exactly as sent', function (string $grid) {
    [$url, $payload] = gridRequest($grid, ['amount' => 1250, 'setup_fee' => 500]);

    $this->post($url, $payload)->assertSessionHasNoErrors();

    expect(Price::query()->sole()->only(['amount', 'setup_fee']))->toBe(['amount' => 1250, 'setup_fee' => 500]);
})->with('grids');

it('keeps the option value untouched when its grid is invalid', function () {
    $value = ConfigOptionValue::factory()->create(['label' => 'Before']);

    $this->put(route('admin.catalog.values.update', $value), ['label' => 'After', 'prices' => [['currency' => 'USD', 'cycle' => 'monthly', 'enabled' => true, 'amount' => 'abc', 'setup_fee' => 0]]])
        ->assertSessionHasErrors('prices.0.amount');

    expect($value->fresh()->label)->toBe('Before');
});
