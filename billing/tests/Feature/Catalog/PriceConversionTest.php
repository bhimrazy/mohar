<?php

use App\Enums\BillingCycle;
use App\Enums\PriceRounding;
use App\Events\Core\ExchangeRatesUpdated;
use App\Modules\Catalog\Actions\ConvertPrices;
use App\Modules\Catalog\Models\Price;
use App\Modules\Catalog\Models\Product;
use App\Modules\Core\Models\Currency;
use App\Modules\Core\Support\Settings;

beforeEach(function () {
    Currency::factory()->create();
    $this->npr = Currency::factory()->npr()->create(['exchange_rate' => '134.4']);
});

function nprPrice(Product $product, BillingCycle $cycle = BillingCycle::Monthly): ?Price
{
    return $product->prices()->where('currency', 'NPR')->where('cycle', $cycle)->first();
}

test('the preview counts and samples changes without writing anything', function () {
    $product = Product::factory()->create(['name' => 'Starter hosting']);
    $product->prices()->createMany([
        ['currency' => 'USD', 'cycle' => BillingCycle::Monthly, 'amount' => 999, 'setup_fee' => 0],
        ['currency' => 'USD', 'cycle' => BillingCycle::Annually, 'amount' => 9999, 'setup_fee' => 0],
        ['currency' => 'NPR', 'cycle' => BillingCycle::Annually, 'amount' => 1_343_866, 'setup_fee' => 0], // already converted
    ]);

    $preview = app(ConvertPrices::class)->preview();

    expect($preview->changes)->toBe(1)
        ->and($preview->unchanged)->toBe(1)
        ->and($preview->manual)->toBe(0)
        ->and($preview->samples[0]->toArray())->toBe(['item' => 'Starter hosting', 'currency' => 'NPR', 'period' => 'Monthly', 'before' => null, 'after' => 134266])
        ->and(nprPrice($product))->toBeNull();

    expect(app(ConvertPrices::class)->apply())->toBe(1)
        ->and(nprPrice($product)->amount)->toBe(134266)
        ->and(app(ConvertPrices::class)->preview()->changes)->toBe(0); // idempotent
});

test('the buffer and rounding rule of the currency are applied in that order', function () {
    $this->npr->update(['price_buffer_percent' => '2.5', 'price_rounding' => PriceRounding::Whole]);
    $product = Product::factory()->withPrice('USD', BillingCycle::Monthly, 999, 500)->create();

    app(ConvertPrices::class)->apply();

    // 9.99 × 134.4 = 1342.66 → +2.5% = 1376.2265 → 1376.23 → nearest whole 1376.00
    expect(nprPrice($product))->amount->toBe(137600)->setup_fee->toBe(68900); // 5 × 134.4 = 672 → 688.80 → 689
});

test('rows staff set manually survive conversion', function () {
    $product = Product::factory()->withPrice('USD', BillingCycle::Monthly, 999)->create();
    $product->prices()->create(['currency' => 'NPR', 'cycle' => BillingCycle::Monthly, 'amount' => 99_900, 'setup_fee' => 0, 'is_manual' => true]);

    expect(app(ConvertPrices::class)->preview())->changes->toBe(0)->manual->toBe(1);
    app(ConvertPrices::class)->apply();

    expect(nprPrice($product)->amount)->toBe(99_900);
});

test('the pricing form stores the manual flag and keeps it when omitted', function () {
    actingAsStaff(['catalog.products.manage']);
    $product = Product::factory()->withPrice('USD', BillingCycle::Monthly, 1000)->create();
    $row = fn (string $currency, int $amount, ?bool $manual = null): array => array_filter(
        ['currency' => $currency, 'cycle' => 'monthly', 'enabled' => true, 'amount' => $amount, 'setup_fee' => 0, 'manual' => $manual],
        fn (mixed $value): bool => $value !== null,
    );

    $this->put(route('admin.catalog.products.pricing.update', $product), ['prices' => [$row('USD', 1000), $row('NPR', 150_000, true)]])->assertRedirect();
    expect(nprPrice($product)->is_manual)->toBeTrue();

    $this->put(route('admin.catalog.products.pricing.update', $product), ['prices' => [$row('USD', 1000), $row('NPR', 155_000)]])->assertRedirect();
    expect(nprPrice($product))->is_manual->toBeTrue()->amount->toBe(155_000);

    $this->put(route('admin.catalog.products.pricing.update', $product), ['prices' => [$row('USD', 1000), $row('NPR', 155_000, false)]])->assertRedirect();
    expect(nprPrice($product)->is_manual)->toBeFalse();
});

test('a rate change converts prices only when auto-convert is on', function () {
    $product = Product::factory()->withPrice('USD', BillingCycle::Monthly, 1000)->create();

    ExchangeRatesUpdated::dispatch(['NPR'], 'automatic');
    expect(nprPrice($product))->toBeNull();

    app(Settings::class)->set('catalog.auto_convert_prices', true);
    ExchangeRatesUpdated::dispatch(['NPR'], 'automatic');
    expect(nprPrice($product)->amount)->toBe(134_400);
});
