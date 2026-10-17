<?php

namespace App\Modules\Catalog\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Actions\SyncPrices;
use App\Modules\Catalog\Actions\SyncProductRelations;
use App\Modules\Catalog\Http\Requests\ProductAddonsRequest;
use App\Modules\Catalog\Http\Requests\ProductConfigOptionsRequest;
use App\Modules\Catalog\Http\Requests\ProductPricingRequest;
use App\Modules\Catalog\Http\Requests\ProductProvisioningRequest;
use App\Modules\Catalog\Http\Requests\ProductUpgradesRequest;
use App\Modules\Catalog\Models\Product;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * The product edit tabs other than details: pricing, configurable options, addons, upgrades, provisioning.
 */
class ProductSettingsController extends Controller
{
    public function pricing(ProductPricingRequest $request, Product $product, SyncPrices $sync): RedirectResponse
    {
        $sync->handle($product, $request->prices());

        return $this->saved($product, 'pricing', __('Prices for :name saved. Existing services keep their current price.', ['name' => $product->name]));
    }

    public function configOptions(ProductConfigOptionsRequest $request, Product $product, SyncProductRelations $sync): RedirectResponse
    {
        $sync->configOptionGroups($product, $request->groupIds());

        return $this->saved($product, 'options', __('Configurable options for :name saved.', ['name' => $product->name]));
    }

    public function addons(ProductAddonsRequest $request, Product $product, SyncProductRelations $sync): RedirectResponse
    {
        $sync->addons($product, $request->addonIds());

        return $this->saved($product, 'addons', __('Addons for :name saved.', ['name' => $product->name]));
    }

    public function upgrades(ProductUpgradesRequest $request, Product $product, SyncProductRelations $sync): RedirectResponse
    {
        $sync->upgradePaths($product, $request->targetIds(), $request->boolean('allow_config_option_upgrades'));

        return $this->saved($product, 'upgrades', __('Upgrade paths for :name saved.', ['name' => $product->name]));
    }

    public function provisioning(ProductProvisioningRequest $request, Product $product, SyncProductRelations $sync): RedirectResponse
    {
        $sync->provisioning($product, $request->provisioningData());

        return $this->saved($product, 'provisioning', __('Provisioning settings for :name saved.', ['name' => $product->name]));
    }

    private function saved(Product $product, string $tab, string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return to_route('admin.catalog.products.edit', ['product' => $product, 'tab' => $tab]);
    }
}
