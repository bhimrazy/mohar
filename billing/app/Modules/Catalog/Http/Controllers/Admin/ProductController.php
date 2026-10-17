<?php

namespace App\Modules\Catalog\Http\Controllers\Admin;

use App\Contracts\Services\CatalogUsage;
use App\Contracts\Services\ProvisioningDrivers;
use App\Enums\BillingCycle;
use App\Http\Controllers\Controller;
use App\Modules\Catalog\Actions\DeleteProduct;
use App\Modules\Catalog\Actions\DuplicateProduct;
use App\Modules\Catalog\Actions\SaveProduct;
use App\Modules\Catalog\Enums\AutoSetup;
use App\Modules\Catalog\Enums\CustomFieldType;
use App\Modules\Catalog\Enums\FreeDomain;
use App\Modules\Catalog\Enums\PaymentType;
use App\Modules\Catalog\Enums\ProductType;
use App\Modules\Catalog\Enums\QuantityMode;
use App\Modules\Catalog\Http\Requests\ProductRequest;
use App\Modules\Catalog\Http\Resources\ProductResource;
use App\Modules\Catalog\Models\Addon;
use App\Modules\Catalog\Models\ConfigOptionGroup;
use App\Modules\Catalog\Models\Price;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductCustomField;
use App\Modules\Catalog\Models\ProductGroup;
use App\Modules\Catalog\Support\CyclePicker;
use App\Modules\Core\Models\Currency;
use App\Modules\Core\Support\Settings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProductController extends Controller
{
    public const TABS = ['details', 'pricing', 'options', 'fields', 'addons', 'upgrades', 'provisioning'];

    public function index(Request $request, CatalogUsage $usage): Response
    {
        $this->authorize('catalog.products.view');

        $currency = Currency::default()->code;
        $status = $request->string('status')->toString();

        $products = Product::query()
            ->with(['group:id,name', 'prices' => fn ($query) => $query->where('currency', $currency)->where('enabled', true)])
            ->search($request->string('search')->toString())
            ->when($request->integer('group') > 0, fn (Builder $query) => $query->where('product_group_id', $request->integer('group')))
            ->when(ProductType::tryFrom($request->string('type')->toString()), fn (Builder $query, ProductType $type) => $query->where('type', $type))
            ->when($status === 'active', fn (Builder $query) => $query->where('is_hidden', false)->where('is_retired', false))
            ->when($status === 'hidden', fn (Builder $query) => $query->where('is_hidden', true)->where('is_retired', false))
            ->when($status === 'retired', fn (Builder $query) => $query->where('is_retired', true))
            ->orderBy('product_group_id')
            ->ordered()
            ->paginate(25)
            ->withQueryString();

        $counts = $usage->activeServiceCountsByProduct(array_values(array_map('intval', $products->pluck('id')->all())));

        return Inertia::render('admin/catalog/products/index', [
            'products' => $products->through(fn (Product $product): array => $this->listRow($product, $currency, $counts[$product->id] ?? 0)),
            'filters' => $request->only(['search', 'group', 'type', 'status']),
            'groups' => ProductGroup::query()->ordered()->get(['id', 'name']),
            'types' => ProductType::options(),
            'currency' => $currency,
            'canManage' => $request->user()?->hasPermission('catalog.products.manage') ?? false,
        ]);
    }

    public function create(Request $request, Settings $settings): Response
    {
        $this->authorize('catalog.products.manage');

        return Inertia::render('admin/catalog/products/create', [
            'defaults' => [
                'product_group_id' => $request->integer('group') ?: ProductGroup::query()->ordered()->value('id'),
                'auto_setup' => (AutoSetup::tryFrom((string) $settings->get('catalog.default_auto_setup')) ?? AutoSetup::OnPayment)->value,
                'is_taxable' => (bool) $settings->get('catalog.default_taxable', true),
            ],
            'groups' => ProductGroup::query()->ordered()->get(['id', 'name']),
            'enums' => $this->enums(),
        ]);
    }

    public function store(ProductRequest $request, SaveProduct $save): RedirectResponse
    {
        $product = $save->handle($request->productData());
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Product :name created. Add its prices next.', ['name' => $product->name])]);

        return to_route('admin.catalog.products.edit', ['product' => $product, 'tab' => $product->payment_type === PaymentType::Free ? 'details' : 'pricing']);
    }

    public function edit(Request $request, Product $product, CatalogUsage $usage, ProvisioningDrivers $drivers): Response
    {
        $this->authorize('catalog.products.view');

        $product->load(['group:id,name', 'prices', 'configOptionGroups:id', 'addons:id', 'upgradeTargets:id', 'customFields']);
        $tab = in_array($tab = $request->string('tab')->toString(), self::TABS, true) ? $tab : 'details';

        return Inertia::render('admin/catalog/products/edit', [
            'product' => new ProductResource($product),
            'tab' => $tab,
            'activeServices' => $usage->activeServiceCountsByProduct([$product->id])[$product->id] ?? 0,
            'canManage' => $request->user()?->hasPermission('catalog.products.manage') ?? false,
            'groups' => ProductGroup::query()->ordered()->get(['id', 'name']),
            'enums' => $this->enums(),
            'currencies' => Currency::query()->orderByDesc('is_default')->orderBy('code')->get(['code', 'name', 'prefix', 'is_default']),
            'pricing' => [
                'cycles' => CyclePicker::options($product->payment_type->cycles()),
                'rows' => $product->prices->map(fn (Price $price): array => $price->toMatrixRow())->values(),
            ],
            'configOptionGroups' => ConfigOptionGroup::query()->withCount('options')->orderBy('name')->get(['id', 'name', 'description']),
            'assignedGroupIds' => $product->configOptionGroups->modelKeys(),
            'customFields' => $product->customFields->map(fn (ProductCustomField $field): array => [
                ...$field->only(['id', 'name', 'module_key', 'description', 'validation_regex', 'is_required', 'is_admin_only', 'show_on_order', 'show_on_invoice', 'sort_order']),
                'type' => $field->type->value,
                'options' => $field->options ?? [],
            ])->values(),
            'addons' => Addon::query()
                ->where(fn (Builder $query) => $query->where('is_retired', false)->orWhereIn('id', $product->addons->modelKeys()))
                ->orderBy('weight')->orderBy('name')
                ->get()
                ->map(fn (Addon $addon): array => ['id' => $addon->id, 'name' => $addon->name, 'status' => $addon->status(), 'payment_type' => $addon->payment_type->value, 'show_on_order' => $addon->show_on_order]),
            'assignedAddonIds' => $product->addons->modelKeys(),
            'upgradeCandidates' => Product::query()
                ->whereKeyNot($product->id)
                ->with('group:id,name')
                ->orderBy('product_group_id')->ordered()
                ->get()
                ->map(fn (Product $candidate): array => ['id' => $candidate->id, 'name' => $candidate->name, 'group' => $candidate->group->name, 'status' => $candidate->status()]),
            'upgradeTargetIds' => $product->upgradeTargets->modelKeys(),
            'drivers' => $drivers->all(),
        ]);
    }

    public function update(ProductRequest $request, Product $product, SaveProduct $save): RedirectResponse
    {
        $save->handle($request->productData(), $product);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Product :name updated.', ['name' => $product->name])]);

        return to_route('admin.catalog.products.edit', ['product' => $product, 'tab' => 'details']);
    }

    public function destroy(Product $product, DeleteProduct $delete): RedirectResponse
    {
        $this->authorize('catalog.products.manage');

        $delete->handle($product);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Product :name deleted.', ['name' => $product->name])]);

        return to_route('admin.catalog.products.index');
    }

    public function duplicate(Product $product, DuplicateProduct $duplicate): RedirectResponse
    {
        $this->authorize('catalog.products.manage');

        $copy = $duplicate->handle($product);
        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name created as a hidden copy.', ['name' => $copy->name])]);

        return to_route('admin.catalog.products.edit', $copy);
    }

    /**
     * @return array<string, mixed>
     */
    private function listRow(Product $product, string $currency, int $activeServices): array
    {
        $cheapest = collect($product->enabledPricesIn($currency))
            ->filter(fn (Price $price): bool => in_array($price->cycle, $product->payment_type->cycles(), true))
            ->first();

        return [
            'id' => $product->id,
            'name' => $product->name,
            'slug' => $product->slug,
            'group' => $product->group->only(['id', 'name']),
            'type' => $product->type->value,
            'type_label' => $product->type->label(),
            'payment_type' => $product->payment_type->value,
            'payment_type_label' => $product->payment_type->label(),
            'status' => $product->status(),
            'is_taxable' => $product->is_taxable,
            'stock' => $product->stock_enabled ? $product->stock_quantity : null,
            'auto_setup' => $product->auto_setup->value,
            'auto_setup_label' => $product->auto_setup->label(),
            'active_services' => $activeServices,
            'price_from' => $cheapest === null ? null : [
                'amount' => $cheapest->amount,
                'setup_fee' => $cheapest->setup_fee,
                'cycle' => $cheapest->cycle->value,
                'cycle_label' => CyclePicker::label($cheapest->cycle),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function enums(): array
    {
        return [
            'types' => ProductType::options(),
            'paymentTypes' => PaymentType::options(),
            'quantityModes' => QuantityMode::options(),
            'autoSetups' => AutoSetup::options(),
            'freeDomains' => FreeDomain::options(),
            'customFieldTypes' => CustomFieldType::options(),
            'recurringCycles' => CyclePicker::options(BillingCycle::recurring()),
        ];
    }
}
