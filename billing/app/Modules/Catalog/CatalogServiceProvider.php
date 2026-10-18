<?php

namespace App\Modules\Catalog;

use App\Contracts\Catalog\PriceBook;
use App\Contracts\Notifications\MergeFieldRegistry;
use App\Events\Core\ExchangeRatesUpdated;
use App\Events\Orders\OrderCancelled;
use App\Events\Orders\OrderPlaced;
use App\Events\Services\ServiceActivated;
use App\Modules\Catalog\Actions\ConvertPrices;
use App\Modules\Catalog\Enums\AutoSetup;
use App\Modules\Catalog\Listeners\TrackStockReservations;
use App\Modules\Catalog\Models\Addon;
use App\Modules\Catalog\Models\ConfigOptionValue;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Services\CatalogPriceBook;
use App\Modules\Core\Support\Settings;
use App\Support\Automation\Automation;
use App\Support\Currencies\PriceConverters;
use App\Support\Modules\ModuleServiceProvider;
use App\Support\Navigation\Navigation;
use App\Support\Navigation\NavItem;
use App\Support\Permissions\PermissionRegistry;
use App\Support\Search\GlobalSearch;
use App\Support\Settings\SettingDefinition;
use App\Support\Settings\SettingsRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Event;

class CatalogServiceProvider extends ModuleServiceProvider
{
    public function moduleKey(): string
    {
        return 'catalog';
    }

    protected function registerModule(): void
    {
        $this->app->singleton(PriceBook::class, CatalogPriceBook::class);
    }

    protected function bootModule(): void
    {
        Relation::morphMap([
            'product' => Product::class,
            'addon' => Addon::class,
            'config_option_value' => ConfigOptionValue::class,
        ]);

        $this->registerNavigation();
        $this->registerPermissions();
        $this->registerSettings();
        $this->registerSearch();
        $this->registerMergeFields();

        Event::listen(OrderPlaced::class, [TrackStockReservations::class, 'orderPlaced']);
        Event::listen(ServiceActivated::class, [TrackStockReservations::class, 'serviceActivated']);
        Event::listen(OrderCancelled::class, [TrackStockReservations::class, 'orderCancelled']);

        $this->app->make(PriceConverters::class)->register(ConvertPrices::class);
        Event::listen(ExchangeRatesUpdated::class, function (): void {
            $convert = $this->app->make(ConvertPrices::class);

            if ($convert->automatic()) {
                $convert->apply();
            }
        });

        $this->app->make(Automation::class)->task('catalog.convert-prices', Automation::SYNC, function (CarbonImmutable $today): int|string {
            return $this->app->make(Settings::class)->get('catalog.auto_convert_prices', false)
                ? $this->app->make(ConvertPrices::class)->handle()
                : 'disabled';
        });
    }

    private function registerNavigation(): void
    {
        $navigation = $this->app->make(Navigation::class);
        $navigation->add('admin', new NavItem('Products', 'admin.catalog.products.index', 'Package', 'Setup', 10, 'catalog.products.view', 'admin.catalog.products.*'));
        $navigation->add('admin', new NavItem('Product groups', 'admin.catalog.groups.index', 'Boxes', 'Setup', 11, 'catalog.products.view', 'admin.catalog.groups.*'));
        $navigation->add('admin', new NavItem('Configurable options', 'admin.catalog.option-groups.index', 'SlidersHorizontal', 'Setup', 12, 'catalog.options.view', 'admin.catalog.option-groups.*'));
        $navigation->add('admin', new NavItem('Addons', 'admin.catalog.addons.index', 'Puzzle', 'Setup', 13, 'catalog.addons.view', 'admin.catalog.addons.*'));
    }

    private function registerPermissions(): void
    {
        $this->app->make(PermissionRegistry::class)->register('Catalog', [
            'catalog.products.view' => 'View products and product groups',
            'catalog.products.manage' => 'Create, edit, price and delete products and product groups',
            'catalog.options.view' => 'View configurable options',
            'catalog.options.manage' => 'Create, edit, price and delete configurable options',
            'catalog.addons.view' => 'View product addons',
            'catalog.addons.manage' => 'Create, edit, price and delete product addons',
            'catalog.settings.manage' => 'Change catalog settings',
        ]);
    }

    private function registerSettings(): void
    {
        $this->app->make(SettingsRegistry::class)->section('catalog', 'Catalog', [
            new SettingDefinition('catalog.default_auto_setup', 'Default auto-setup for new products', 'select',
                'When new products are provisioned unless changed on the product.',
                array_column(AutoSetup::options(), 'label', 'value'),
                ['in:'.implode(',', array_column(AutoSetup::cases(), 'value'))]),
            new SettingDefinition('catalog.default_taxable', 'New products are taxable', 'boolean',
                'Prevents silently untaxed invoices for products created without ticking "taxable".'),
            new SettingDefinition('catalog.show_monthly_breakdown', 'Show a monthly equivalent for longer billing cycles', 'boolean',
                'The store shows e.g. an annual price as "$8.25/mo".'),
        ], 'catalog.settings.manage');

        // Converting prices rewrites them from exchange rates, so it sits with the sensitive rate permission.
        $this->app->make(SettingsRegistry::class)->section('catalog_conversion', 'Converted product prices', [
            new SettingDefinition('catalog.auto_convert_prices', 'Convert prices from the default currency automatically', 'boolean',
                'After exchange rates change, prices in other currencies are recalculated from the default-currency price with each currency\'s buffer and rounding. Prices marked "set manually" are kept. Preview first in Currencies → Converted prices.'),
        ], 'core.currencies.rates');
    }

    private function registerSearch(): void
    {
        $this->app->make(GlobalSearch::class)->provider('Products', fn (string $term): array => array_values(Product::query()
            ->with('group:id,name')
            ->search($term)
            ->orderBy('name')
            ->limit(5)
            ->get()
            ->map(fn (Product $product): array => [
                'title' => $product->name,
                'subtitle' => $product->group->name.' · '.$product->type->label(),
                'href' => route('admin.catalog.products.edit', $product),
            ])
            ->all()), 'catalog.products.view');
    }

    private function registerMergeFields(): void
    {
        $this->app->make(MergeFieldRegistry::class)->register('product', function (int $id): array {
            $product = Product::withTrashed()->with('group:id,name')->findOrFail($id);

            return [
                'product_id' => $product->id,
                'name' => $product->name,
                'group_name' => $product->group->name,
                'type' => $product->type->value,
                'type_label' => $product->type->label(),
                'tagline' => $product->tagline,
                'description' => $product->description,
                'payment_type' => $product->payment_type->value,
                'requires_domain' => $product->requires_domain,
            ];
        });
    }
}
