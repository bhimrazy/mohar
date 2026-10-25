<?php

use App\Enums\BillingCycle;
use App\Enums\PriceRounding;
use App\Events\Catalog\ProductPriceChanged;
use App\Modules\Catalog\Actions\ConvertPrices;
use App\Modules\Catalog\Models\Addon;
use App\Modules\Catalog\Models\Product;
use App\Modules\Core\Models\Currency;
use App\Modules\Domains\Services\RenewalInvoices;
use App\Modules\Orders\Services\CurrencyConverter;
use App\Support\Dashboard\Dashboard;
use Illuminate\Support\Facades\Event;
use Tests\Fakes\Catalog\FakeProvisioningDrivers;

beforeEach(function () {
    Currency::factory()->create();
    $this->npr = Currency::factory()->npr()->create(['exchange_rate' => '151.94', 'price_rounding' => PriceRounding::Whole]);
});

describe('price conversion events', function () {
    it('dispatches ProductPriceChanged for every converted row with the old and new amount', function () {
        $product = Product::factory()->withPrice('USD', BillingCycle::Monthly, 999)->create();
        Event::fake([ProductPriceChanged::class]);

        app(ConvertPrices::class)->apply();

        Event::assertDispatchedTimes(ProductPriceChanged::class, 1);
        Event::assertDispatched(ProductPriceChanged::class, fn (ProductPriceChanged $event): bool => $event->priceableType === 'product'
            && $event->priceableId === $product->id && $event->currency === 'NPR' && $event->cycle === 'monthly'
            && $event->oldAmount === null && $event->newAmount === 151_800);

        $this->npr->update(['exchange_rate' => '160']);
        app(ConvertPrices::class)->apply();

        Event::assertDispatchedTimes(ProductPriceChanged::class, 2);
        Event::assertDispatched(ProductPriceChanged::class, fn (ProductPriceChanged $event): bool => $event->oldAmount === 151_800 && $event->newAmount === 159_800);
    });

    it('dispatches nothing when a run changes nothing, and reports a turned-off row as removed', function () {
        $product = Product::factory()->withPrice('USD', BillingCycle::Monthly, 999)->create();
        app(ConvertPrices::class)->apply();
        Event::fake([ProductPriceChanged::class]);

        app(ConvertPrices::class)->apply();
        Event::assertNotDispatched(ProductPriceChanged::class);

        $product->prices()->where('currency', 'USD')->delete();
        app(ConvertPrices::class)->apply();

        Event::assertDispatchedTimes(ProductPriceChanged::class, 1);
        Event::assertDispatched(ProductPriceChanged::class, fn (ProductPriceChanged $event): bool => $event->currency === 'NPR' && $event->oldAmount === 151_800 && $event->newAmount === null);
    });
});

describe('one rounding rule for converted fees', function () {
    it('rounds a converted domain late-renewal fee like a converted price', function () {
        // 5.00 × 151.94 = 759.70 → nearest whole unit
        expect(app(RenewalInvoices::class)->convertFromDefault(500, 'NPR'))->toBe(76_000)
            ->and(app(RenewalInvoices::class)->convertFromDefault(500, 'USD'))->toBe(500);
    });

    it('rounds converted promotion and minimum-cart amounts like a converted price', function () {
        expect(app(CurrencyConverter::class)->fromDefault(500, 'NPR'))->toBe(76_000)
            ->and(app(CurrencyConverter::class)->fromDefault(500, 'USD'))->toBe(500);
    });
});

describe('driver change resets secrets', function () {
    function twoDrivers(): void
    {
        $fields = [['key' => 'api_token', 'label' => 'API token', 'type' => 'password', 'required' => false, 'secret' => true]];
        FakeProvisioningDrivers::bind(new FakeProvisioningDrivers([
            ['key' => 'log', 'label' => 'Log only', 'config_fields' => $fields, 'fields' => $fields],
            ['key' => 'other', 'label' => 'Other', 'config_fields' => $fields, 'fields' => $fields],
        ]));
    }

    it('does not carry a product secret over to another driver', function () {
        actingAsStaff(['catalog.products.manage']);
        twoDrivers();
        $product = Product::factory()->create(['provisioning_driver' => 'log', 'driver_config' => ['api_token' => 'tok-12345']]);
        $url = route('admin.catalog.products.provisioning.update', $product);

        $this->put($url, ['provisioning_driver' => 'other', 'driver_config' => [['key' => 'api_token', 'value' => '']]])->assertSessionHasNoErrors();
        expect($product->fresh())->provisioning_driver->toBe('other')->driver_config->toBe(['api_token' => '']);

        $product->update(['driver_config' => ['api_token' => 'tok-other']]);
        $this->put($url, ['provisioning_driver' => 'other', 'driver_config' => [['key' => 'api_token', 'value' => '']]])->assertSessionHasNoErrors();
        expect($product->fresh()->driver_config)->toBe(['api_token' => 'tok-other']); // same driver still keeps it
    });

    it('does not carry an addon secret over to another driver', function () {
        actingAsStaff(['catalog.addons.manage']);
        twoDrivers();
        $addon = Addon::factory()->create(['provisioning_driver' => 'log', 'driver_config' => ['api_token' => 'tok-12345']]);

        $this->put(route('admin.catalog.addons.update', $addon), [
            'name' => $addon->name, 'kind' => 'feature', 'payment_type' => 'recurring', 'auto_setup' => 'on_payment', 'provisioning_driver' => 'other',
            'driver_config' => [['key' => 'api_token', 'value' => '']], 'product_ids' => [], 'prices' => [],
        ])->assertSessionHasNoErrors();

        expect($addon->fresh()->driver_config)->toBe(['api_token' => '']);
    });
});

describe('buffer and unpriced warning', function () {
    it('carries the currency buffer into converted promotion and minimum-cart amounts', function () {
        $this->npr->update(['price_buffer_percent' => '3', 'price_rounding' => PriceRounding::Tens]);

        // 10.00 × 151.94 = 1519.40, +3% = 1564.98, nearest 10 = 1560.00
        expect(app(CurrencyConverter::class)->fromDefault(1000, 'NPR'))->toBe(156_000);
    });

    it('warns staff how many sellable products have no price in the default currency', function () {
        Currency::query()->where('code', 'USD')->update(['is_default' => false]);
        $this->npr->update(['is_default' => true]);
        Product::factory()->withPrice('NPR', BillingCycle::Monthly, 100_000)->create();
        Product::factory()->withPrice('USD', BillingCycle::Monthly, 1000)->create();
        Product::factory()->withPrice('NPR', BillingCycle::Monthly, 100_000, enabled: false)->create();
        Product::factory()->hidden()->create();
        Product::factory()->retired()->create();
        Product::factory()->free()->create();
        $user = actingAsStaff(['catalog.products.view']);

        $todos = collect(app(Dashboard::class)->resolveTodos('admin', $user))->keyBy('key');

        expect($todos['catalog.unpriced_products'])->label->toBe('Products with no NPR price')->count->toBe(2);
    });
});
