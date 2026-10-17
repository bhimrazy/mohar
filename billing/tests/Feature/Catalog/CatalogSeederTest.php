<?php

use App\Contracts\Catalog\PriceBook;
use App\Data\Catalog\ProductSelection;
use App\Enums\BillingCycle;
use App\Modules\Catalog\Database\Seeders\CatalogSeeder;
use App\Modules\Catalog\Models\Addon;
use App\Modules\Catalog\Models\Price;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductGroup;
use App\Modules\Core\Database\Seeders\CoreSeeder;
use App\Modules\Core\Models\Currency;

beforeEach(function () {
    $this->seed(CoreSeeder::class);
    $this->seed(CatalogSeeder::class);
    $this->seed(CatalogSeeder::class);
});

it('seeds a sellable demo catalog in every configured currency once', function () {
    $vps = Product::query()->where('slug', 'vps-m')->firstOrFail();
    $codes = Currency::query()->orderBy('code')->pluck('code')->all();

    expect(Product::query()->where('slug', 'starter')->count())->toBe(1)
        ->and(Price::query()->distinct()->orderBy('currency')->pluck('currency')->all())->toBe($codes);

    foreach ($codes as $currency) {
        expect(array_column(app(PriceBook::class)->storefront($currency), 'name'))->toBe(['Shared Hosting', 'VPS', 'SSL Certificates'])
            ->and(app(PriceBook::class)->availableCycles($vps->id, $currency))->toBe([BillingCycle::Monthly, BillingCycle::Quarterly, BillingCycle::Annually]);

        foreach (app(PriceBook::class)->availableCycles($vps->id, $currency) as $cycle) {
            expect(app(PriceBook::class)->quote(new ProductSelection($vps->id, $cycle, $currency)))->not->toBeNull();
        }
    }
});

it('binds demo products only to drivers and gateways that ship with the platform', function () {
    $drivers = Product::query()->pluck('provisioning_driver')->merge(Addon::query()->pluck('provisioning_driver'))->unique()->values()->all();
    $gateways = ProductGroup::query()->pluck('allowed_gateways')->filter()->flatten()->unique()->values()->all();

    expect(array_diff($drivers, ['none', 'log']))->toBe([])
        ->and(array_diff($gateways, ['manual', 'bank_transfer', 'sandbox_card']))->toBe([]);
});
