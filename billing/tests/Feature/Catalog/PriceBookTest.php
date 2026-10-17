<?php

use App\Contracts\Catalog\PriceBook;
use App\Data\Catalog\ProductSelection;
use App\Enums\BillingCycle;
use App\Modules\Catalog\Enums\AutoSetup;
use App\Modules\Catalog\Models\Addon;
use App\Modules\Catalog\Models\ConfigOption;
use App\Modules\Catalog\Models\ConfigOptionValue;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductCustomField;
use App\Modules\Catalog\Models\ProductGroup;
use App\Modules\Catalog\Services\CatalogPriceBook;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\ClientGroup;
use App\Modules\Core\Models\Currency;

function priceBook(): PriceBook
{
    return app(PriceBook::class);
}

/**
 * A product with one option group; returns [product, option].
 *
 * @return array{0: Product, 1: ConfigOption}
 */
function productWithOption(ConfigOption $option): array
{
    $product = Product::factory()->withPrice('EUR', BillingCycle::Monthly, 1000, 500)->create();
    $product->configOptionGroups()->attach($option->config_option_group_id);

    return [$product, $option];
}

it('binds the catalog implementation of the PriceBook contract', function () {
    expect(priceBook())->toBeInstanceOf(CatalogPriceBook::class);
});

describe('availableCycles', function () {
    it('returns the enabled cycles in the currency from shortest to longest', function () {
        $product = Product::factory()
            ->withPrice('EUR', BillingCycle::Annually, 10000)
            ->withPrice('EUR', BillingCycle::Monthly, 1000)
            ->withPrice('EUR', BillingCycle::Quarterly, 2800, enabled: false)
            ->withPrice('USD', BillingCycle::Biennially, 20000)
            ->create();

        expect(priceBook()->availableCycles($product->id, 'eur'))->toBe([BillingCycle::Monthly, BillingCycle::Annually]);
    });

    it('returns nothing for a currency without prices so no conversion happens', function () {
        $product = Product::factory()->withPrice('EUR', BillingCycle::Monthly, 1000)->create();

        expect(priceBook()->availableCycles($product->id, 'USD'))->toBe([])
            ->and(priceBook()->quote(new ProductSelection($product->id, BillingCycle::Monthly, 'USD')))->toBeNull();
    });

    it('offers only the free cycle for free products', function () {
        $product = Product::factory()->free()->create();

        expect(priceBook()->availableCycles($product->id, 'USD'))->toBe([BillingCycle::Free]);
    });

    it('ignores price rows that do not match the payment type', function () {
        $product = Product::factory()->oneTime()
            ->withPrice('USD', BillingCycle::OneTime, 4900)
            ->withPrice('USD', BillingCycle::Monthly, 500)
            ->create();

        expect(priceBook()->availableCycles($product->id, 'USD'))->toBe([BillingCycle::OneTime]);
    });

    it('offers nothing for retired or unknown products', function () {
        $retired = Product::factory()->retired()->withPrice('USD', BillingCycle::Monthly, 1000)->create();

        expect(priceBook()->availableCycles($retired->id, 'USD'))->toBe([])
            ->and(priceBook()->availableCycles($retired->id + 1000, 'USD'))->toBe([]);
    });
});

describe('quote', function () {
    it('adds the selected option value to the product price', function () {
        $option = ConfigOption::factory()->create(['name' => 'RAM']);
        $value = ConfigOptionValue::factory()->for($option, 'option')->withPrice('EUR', BillingCycle::Monthly, 500, 250)->create(['label' => '4GB RAM']);
        [$product] = productWithOption($option);

        $quote = priceBook()->quote(new ProductSelection($product->id, BillingCycle::Monthly, 'EUR', [$option->id => $value->id]));

        expect($quote)
            ->recurring->toBe(1500)
            ->setup->toBe(750)
            ->cycle->toBe(BillingCycle::Monthly)
            ->currency->toBe('EUR')
            ->and($quote->breakdown[1])->toBe(['label' => 'RAM: 4GB RAM', 'recurring' => 500, 'setup' => 250]);
    });

    it('multiplies quantity options by the chosen quantity', function () {
        $option = ConfigOption::factory()->quantity(0, 10)->create(['name' => 'Extra IPs']);
        ConfigOptionValue::factory()->for($option, 'option')->withPrice('EUR', BillingCycle::Monthly, 200)->create();
        [$product] = productWithOption($option);

        $quote = priceBook()->quote(new ProductSelection($product->id, BillingCycle::Monthly, 'EUR', [$option->id => 3]));

        expect($quote->recurring)->toBe(1000 + 600)
            ->and($quote->breakdown[1]['label'])->toBe('Extra IPs × 3');
    });

    it('rejects quantities outside the option limits', function () {
        $option = ConfigOption::factory()->quantity(1, 5)->create();
        ConfigOptionValue::factory()->for($option, 'option')->withPrice('EUR', BillingCycle::Monthly, 200)->create();
        [$product] = productWithOption($option);

        priceBook()->quote(new ProductSelection($product->id, BillingCycle::Monthly, 'EUR', [$option->id => 6]));
    })->throws(InvalidArgumentException::class);

    it('rejects a value that belongs to another option', function () {
        $option = ConfigOption::factory()->create();
        ConfigOptionValue::factory()->for($option, 'option')->withPrice('EUR', BillingCycle::Monthly, 200)->create();
        $foreign = ConfigOptionValue::factory()->create();
        [$product] = productWithOption($option);

        priceBook()->quote(new ProductSelection($product->id, BillingCycle::Monthly, 'EUR', [$option->id => $foreign->id]));
    })->throws(InvalidArgumentException::class);

    it('charges yes/no options only when ticked', function () {
        $option = ConfigOption::factory()->yesNo()->create(['name' => 'Backups']);
        ConfigOptionValue::factory()->for($option, 'option')->withPrice('EUR', BillingCycle::Monthly, 300)->create(['label' => 'Yes']);
        [$product] = productWithOption($option);

        expect(priceBook()->quote(new ProductSelection($product->id, BillingCycle::Monthly, 'EUR', [$option->id => 1]))->recurring)->toBe(1300)
            ->and(priceBook()->quote(new ProductSelection($product->id, BillingCycle::Monthly, 'EUR', [$option->id => 0]))->recurring)->toBe(1000)
            ->and(priceBook()->quote(new ProductSelection($product->id, BillingCycle::Monthly, 'EUR'))->recurring)->toBe(1000);
    });

    it('defaults dropdowns to the first visible value', function () {
        $option = ConfigOption::factory()->create();
        ConfigOptionValue::factory()->for($option, 'option')->hidden()->withPrice('EUR', BillingCycle::Monthly, 900)->create(['sort_order' => 1]);
        ConfigOptionValue::factory()->for($option, 'option')->withPrice('EUR', BillingCycle::Monthly, 100)->create(['sort_order' => 2]);
        [$product] = productWithOption($option);

        expect(priceBook()->quote(new ProductSelection($product->id, BillingCycle::Monthly, 'EUR'))->recurring)->toBe(1100);
    });

    it('keeps pricing hidden values that an existing service selected', function () {
        $option = ConfigOption::factory()->create();
        $legacy = ConfigOptionValue::factory()->for($option, 'option')->hidden()->withPrice('EUR', BillingCycle::Monthly, 700)->create();
        [$product] = productWithOption($option);

        expect(priceBook()->quote(new ProductSelection($product->id, BillingCycle::Monthly, 'EUR', [$option->id => $legacy->id]))->recurring)->toBe(1700);
    });

    it('returns null instead of pricing an unpriced option value at zero', function () {
        $option = ConfigOption::factory()->create();
        $value = ConfigOptionValue::factory()->for($option, 'option')->withPrice('EUR', BillingCycle::Annually, 500)->create();
        [$product] = productWithOption($option);

        expect(priceBook()->quote(new ProductSelection($product->id, BillingCycle::Monthly, 'EUR', [$option->id => $value->id])))->toBeNull();
    });

    it('returns null for a disabled cycle, a cycle the payment type does not use, or a retired product', function () {
        $product = Product::factory()->withPrice('USD', BillingCycle::Monthly, 1000, enabled: false)->withPrice('USD', BillingCycle::OneTime, 1000)->create();
        $retired = Product::factory()->retired()->withPrice('USD', BillingCycle::Monthly, 1000)->create();

        expect(priceBook()->quote(new ProductSelection($product->id, BillingCycle::Monthly, 'USD')))->toBeNull()
            ->and(priceBook()->quote(new ProductSelection($product->id, BillingCycle::OneTime, 'USD')))->toBeNull()
            ->and(priceBook()->quote(new ProductSelection($product->id, BillingCycle::Free, 'USD')))->toBeNull()
            ->and(priceBook()->quote(new ProductSelection($retired->id, BillingCycle::Monthly, 'USD')))->toBeNull();
    });

    it('quotes hidden products, which stay orderable by direct link', function () {
        $product = Product::factory()->hidden()->withPrice('USD', BillingCycle::Monthly, 1000)->create();

        expect(priceBook()->quote(new ProductSelection($product->id, BillingCycle::Monthly, 'USD'))->recurring)->toBe(1000);
    });

    it('quotes free products at zero on the free cycle', function () {
        $product = Product::factory()->free()->create();

        $quote = priceBook()->quote(new ProductSelection($product->id, BillingCycle::Monthly, 'USD'));

        expect($quote)->recurring->toBe(0)->setup->toBe(0)->cycle->toBe(BillingCycle::Free);
    });

    it('applies the client group discount when quoting for a client', function () {
        Currency::factory()->create();
        $group = ClientGroup::query()->create(['name' => 'Resellers', 'discount_percent' => 10]);
        $client = Client::factory()->create(['client_group_id' => $group->id]);
        $option = ConfigOption::factory()->create();
        $value = ConfigOptionValue::factory()->for($option, 'option')->withPrice('EUR', BillingCycle::Monthly, 500, 500)->create();
        [$product] = productWithOption($option);

        $quote = app(CatalogPriceBook::class)->quoteForClient(new ProductSelection($product->id, BillingCycle::Monthly, 'EUR', [$option->id => $value->id]), $client->id);

        expect($quote)->recurring->toBe(1350)->setup->toBe(900);
    });
});

describe('addonQuote', function () {
    it('uses the addon shortest cycle when it is not priced for the parent cycle', function () {
        $addon = Addon::factory()->withPrice('USD', BillingCycle::Annually, 5000)->withPrice('USD', BillingCycle::Biennially, 9000)->create();

        expect(priceBook()->addonQuote($addon->id, BillingCycle::Monthly, 'USD'))
            ->cycle->toBe(BillingCycle::Annually)
            ->recurring->toBe(5000);
    });

    it('uses the parent cycle when the addon is priced for it', function () {
        $addon = Addon::factory()->withPrice('USD', BillingCycle::Monthly, 500, 100)->withPrice('USD', BillingCycle::Annually, 5000)->create();

        expect(priceBook()->addonQuote($addon->id, BillingCycle::Monthly, 'USD'))
            ->cycle->toBe(BillingCycle::Monthly)
            ->recurring->toBe(500)
            ->setup->toBe(100);
    });

    it('returns null for retired addons or currencies without prices', function () {
        $retired = Addon::factory()->retired()->withPrice('USD', BillingCycle::Monthly, 500)->create();
        $addon = Addon::factory()->withPrice('USD', BillingCycle::Monthly, 500)->create();

        expect(priceBook()->addonQuote($retired->id, BillingCycle::Monthly, 'USD'))->toBeNull()
            ->and(priceBook()->addonQuote($addon->id, BillingCycle::Monthly, 'GBP'))->toBeNull();
    });
});

describe('canUpgrade', function () {
    it('allows a configured path to a sellable product', function () {
        $from = Product::factory()->create();
        $to = Product::factory()->withPrice('USD', BillingCycle::Monthly, 2000)->create();
        $from->upgradeTargets()->attach($to);

        expect(priceBook()->canUpgrade($from->id, $to->id))->toBeTrue()
            ->and(priceBook()->canUpgrade($to->id, $from->id))->toBeFalse();
    });

    it('refuses retired targets', function () {
        $from = Product::factory()->create();
        $to = Product::factory()->retired()->withPrice('USD', BillingCycle::Monthly, 2000)->create();
        $from->upgradeTargets()->attach($to);

        expect(priceBook()->canUpgrade($from->id, $to->id))->toBeFalse();
    });

    it('refuses targets without any enabled price', function () {
        $from = Product::factory()->create();
        $to = Product::factory()->withPrice('USD', BillingCycle::Monthly, 2000, enabled: false)->create();
        $from->upgradeTargets()->attach($to);

        expect(priceBook()->canUpgrade($from->id, $to->id))->toBeFalse();
    });
});

describe('product', function () {
    it('summarises the provisioning binding and policy', function () {
        $product = Product::factory()->stock(4)->create([
            'name' => 'VPS M',
            'provisioning_driver' => 'proxmox',
            'server_group_id' => 7,
            'driver_config' => ['plan' => 'm'],
            'auto_setup' => AutoSetup::OnOrder,
            'welcome_email_key' => 'welcome.vps',
            'requires_domain' => true,
        ]);

        $summary = priceBook()->product($product->id);

        expect($summary)
            ->name->toBe('VPS M')
            ->groupName->toBe($product->group->name)
            ->provisioningDriver->toBe('proxmox')
            ->serverGroupId->toBe(7)
            ->driverConfig->toBe(['plan' => 'm'])
            ->autoSetup->toBe('order')
            ->welcomeEmail->toBe('welcome.vps')
            ->requiresDomain->toBeTrue()
            ->taxable->toBeTrue()
            ->stock->toBe(4)
            ->active->toBeTrue();
    });

    it('marks retired products inactive and returns null for unknown ones', function () {
        $product = Product::factory()->retired()->create();

        expect(priceBook()->product($product->id)?->active)->toBeFalse()
            ->and(priceBook()->product($product->id + 1000))->toBeNull()
            ->and(priceBook()->product($product->id)?->stock)->toBeNull();
    });
});

describe('storefront', function () {
    it('lists visible groups with orderable products priced in the currency', function () {
        $second = ProductGroup::factory()->create(['sort_order' => 2, 'name' => 'VPS']);
        $first = ProductGroup::factory()->gateways(['stripe'])->create(['sort_order' => 1, 'name' => 'Hosting']);
        $hiddenGroup = ProductGroup::factory()->hidden()->create();
        Product::factory()->for($first, 'group')->withPrice('USD', BillingCycle::Monthly, 500)->withPrice('USD', BillingCycle::Annually, 5000, 1000)->create(['name' => 'Starter']);
        Product::factory()->for($first, 'group')->hidden()->withPrice('USD', BillingCycle::Monthly, 500)->create();
        Product::factory()->for($first, 'group')->retired()->withPrice('USD', BillingCycle::Monthly, 500)->create();
        Product::factory()->for($first, 'group')->withPrice('EUR', BillingCycle::Monthly, 500)->create();
        Product::factory()->for($second, 'group')->stock(0)->withPrice('USD', BillingCycle::Monthly, 900)->create(['name' => 'VPS S']);
        Product::factory()->for($hiddenGroup, 'group')->withPrice('USD', BillingCycle::Monthly, 500)->create();

        $store = priceBook()->storefront('usd');

        expect(array_column($store, 'name'))->toBe(['Hosting', 'VPS'])
            ->and($store[0]['allowed_gateways'])->toBe(['stripe'])
            ->and(array_column($store[0]['products'], 'name'))->toBe(['Starter'])
            ->and($store[0]['products'][0]['pricing'])->toBe([
                ['cycle' => 'monthly', 'recurring' => 500, 'setup' => 0, 'monthly' => 500],
                ['cycle' => 'annually', 'recurring' => 5000, 'setup' => 1000, 'monthly' => 417],
            ])
            ->and($store[1]['products'][0]['in_stock'])->toBeFalse();
    });
});

describe('order form data', function () {
    it('lists visible config options with prices for the product cycles', function () {
        $option = ConfigOption::factory()->create(['name' => 'RAM', 'is_required' => true]);
        ConfigOptionValue::factory()->for($option, 'option')->withPrice('EUR', BillingCycle::Monthly, 500)->withPrice('EUR', BillingCycle::Annually, 5000)->create(['label' => '4 GB', 'sort_order' => 1]);
        ConfigOptionValue::factory()->for($option, 'option')->hidden()->withPrice('EUR', BillingCycle::Monthly, 900)->create();
        ConfigOption::factory()->hidden()->create(['config_option_group_id' => $option->config_option_group_id]);
        [$product] = productWithOption($option);

        $options = priceBook()->configOptionsFor($product->id, 'EUR');

        expect($options)->toHaveCount(1)
            ->and($options[0])->toMatchArray(['id' => $option->id, 'name' => 'RAM', 'type' => 'dropdown', 'required' => true])
            ->and($options[0]['values'])->toHaveCount(1)
            ->and($options[0]['values'][0]['pricing'])->toBe([['cycle' => 'monthly', 'recurring' => 500, 'setup' => 0, 'monthly' => 500]]);
    });

    it('lists addons offered with the product in a new order', function () {
        $product = Product::factory()->create();
        $offered = Addon::factory()->withPrice('USD', BillingCycle::Monthly, 300)->create(['name' => 'Backups']);
        $existingOnly = Addon::factory()->withPrice('USD', BillingCycle::Monthly, 300)->create(['show_on_order' => false]);
        $hidden = Addon::factory()->hidden()->withPrice('USD', BillingCycle::Monthly, 300)->create();
        $unpriced = Addon::factory()->withPrice('EUR', BillingCycle::Monthly, 300)->create();
        Addon::factory()->withPrice('USD', BillingCycle::Monthly, 300)->create();
        $product->addons()->attach([$offered->id, $existingOnly->id, $hidden->id, $unpriced->id]);

        $addons = priceBook()->addonsFor($product->id, 'USD');

        expect(array_column($addons, 'name'))->toBe(['Backups'])
            ->and($addons[0]['pricing'][0])->toMatchArray(['cycle' => 'monthly', 'recurring' => 300]);
    });

    it('lists custom fields clients fill in on the order form', function () {
        $product = Product::factory()->create();
        ProductCustomField::factory()->for($product)->create(['name' => 'Site', 'type' => 'dropdown', 'options' => ['WordPress', 'None'], 'is_required' => true, 'sort_order' => 1]);
        ProductCustomField::factory()->for($product)->adminOnly()->create();
        ProductCustomField::factory()->for($product)->create(['show_on_order' => false]);

        expect(priceBook()->customFieldsFor($product->id))->toHaveCount(1)
            ->and(priceBook()->customFieldsFor($product->id)[0])->toMatchArray(['name' => 'Site', 'type' => 'dropdown', 'required' => true, 'options' => ['WordPress', 'None']]);
    });
});
