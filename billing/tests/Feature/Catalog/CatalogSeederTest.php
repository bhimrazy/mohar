<?php

use App\Contracts\Catalog\PriceBook;
use App\Data\Catalog\ProductSelection;
use App\Enums\BillingCycle;
use App\Modules\Catalog\Database\Seeders\CatalogSeeder;
use App\Modules\Catalog\Models\Product;

it('seeds a sellable demo catalog in three currencies once', function () {
    $this->seed(CatalogSeeder::class);
    $this->seed(CatalogSeeder::class);

    $vps = Product::query()->where('slug', 'vps-m')->firstOrFail();

    expect(Product::query()->where('slug', 'starter')->count())->toBe(1)
        ->and(array_column(app(PriceBook::class)->storefront('GBP'), 'name'))->toBe(['Shared Hosting', 'VPS', 'SSL Certificates']);

    foreach (['USD', 'EUR', 'GBP'] as $currency) {
        foreach (app(PriceBook::class)->availableCycles($vps->id, $currency) as $cycle) {
            expect(app(PriceBook::class)->quote(new ProductSelection($vps->id, $cycle, $currency)))->not->toBeNull();
        }
    }

    expect(app(PriceBook::class)->availableCycles($vps->id, 'EUR'))->toBe([BillingCycle::Monthly, BillingCycle::Quarterly, BillingCycle::Annually]);
});
