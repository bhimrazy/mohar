<?php

namespace App\Modules\Catalog\Http\Controllers\Admin;

use App\Contracts\Services\ProvisioningDrivers;
use App\Http\Controllers\Controller;
use App\Modules\Catalog\Actions\DeleteAddon;
use App\Modules\Catalog\Actions\SaveAddon;
use App\Modules\Catalog\Enums\AddonKind;
use App\Modules\Catalog\Enums\AutoSetup;
use App\Modules\Catalog\Enums\PaymentType;
use App\Modules\Catalog\Http\Requests\AddonRequest;
use App\Modules\Catalog\Models\Addon;
use App\Modules\Catalog\Models\Price;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Support\CyclePicker;
use App\Modules\Core\Models\Currency;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AddonController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('catalog.addons.view');

        $currency = Currency::default()->code;
        $status = $request->string('status')->toString();

        $addons = Addon::query()
            ->withCount('products')
            ->with(['prices' => fn ($query) => $query->where('currency', $currency)->where('enabled', true)])
            ->when($request->filled('search'), fn (Builder $query) => $query->whereLike('name', '%'.$request->string('search')->toString().'%'))
            ->when($status === 'active', fn (Builder $query) => $query->where('is_hidden', false)->where('is_retired', false))
            ->when($status === 'hidden', fn (Builder $query) => $query->where('is_hidden', true)->where('is_retired', false))
            ->when($status === 'retired', fn (Builder $query) => $query->where('is_retired', true))
            ->orderBy('weight')->orderBy('name')->orderBy('id')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('admin/catalog/addons/index', [
            'addons' => $addons->through(function (Addon $addon) use ($currency): array {
                $cheapest = collect($addon->enabledPricesIn($currency))->first(fn (Price $price): bool => in_array($price->cycle, $addon->payment_type->cycles(), true));

                return [
                    'id' => $addon->id,
                    'name' => $addon->name,
                    'kind' => $addon->kind->value,
                    'payment_type' => $addon->payment_type->value,
                    'payment_type_label' => $addon->payment_type->label(),
                    'status' => $addon->status(),
                    'show_on_order' => $addon->show_on_order,
                    'is_taxable' => $addon->is_taxable,
                    'products_count' => $addon->products_count,
                    'price_from' => $cheapest === null ? null : ['amount' => $cheapest->amount, 'cycle_label' => CyclePicker::label($cheapest->cycle)],
                ];
            }),
            'filters' => $request->only(['search', 'status']),
            'currency' => $currency,
            'canManage' => $request->user()?->hasPermission('catalog.addons.manage') ?? false,
        ]);
    }

    public function create(): Response
    {
        $this->authorize('catalog.addons.manage');

        return Inertia::render('admin/catalog/addons/form', [...$this->formOptions(), 'addon' => null]);
    }

    public function store(AddonRequest $request, SaveAddon $save): RedirectResponse
    {
        $addon = $save->handle($request->addonData());
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Addon :name created.', ['name' => $addon->name])]);

        return to_route('admin.catalog.addons.edit', $addon);
    }

    public function edit(Addon $addon): Response
    {
        $this->authorize('catalog.addons.view');

        $addon->load(['prices', 'products:id']);

        return Inertia::render('admin/catalog/addons/form', [
            ...$this->formOptions(),
            'addon' => [
                ...$addon->only(['id', 'name', 'description', 'is_taxable', 'show_on_order', 'is_hidden', 'is_retired', 'suspend_parent_on_overdue', 'welcome_email_key', 'weight', 'allow_quantity', 'prorata_with_parent', 'provisioning_driver']),
                'kind' => $addon->kind->value,
                'payment_type' => $addon->payment_type->value,
                'auto_setup' => $addon->auto_setup->value,
                'status' => $addon->status(),
                'driver_config' => $addon->driver_config ?? [],
                'product_ids' => $addon->products->modelKeys(),
                'prices' => $addon->prices->map(fn (Price $price): array => $price->toMatrixRow())->values(),
            ],
        ]);
    }

    public function update(AddonRequest $request, Addon $addon, SaveAddon $save): RedirectResponse
    {
        $save->handle($request->addonData(), $addon);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Addon :name saved. Existing services keep their current price.', ['name' => $addon->name])]);

        return to_route('admin.catalog.addons.edit', $addon);
    }

    public function destroy(Addon $addon, DeleteAddon $delete): RedirectResponse
    {
        $this->authorize('catalog.addons.manage');

        $delete->handle($addon);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Addon :name deleted.', ['name' => $addon->name])]);

        return to_route('admin.catalog.addons.index');
    }

    /**
     * @return array<string, mixed>
     */
    private function formOptions(): array
    {
        return [
            'kinds' => AddonKind::options(),
            'paymentTypes' => PaymentType::options(),
            'autoSetups' => AutoSetup::options(),
            'cyclesByPaymentType' => collect(PaymentType::cases())->mapWithKeys(fn (PaymentType $type): array => [$type->value => CyclePicker::options($type->cycles())]),
            'currencies' => Currency::query()->orderByDesc('is_default')->orderBy('code')->get(['code', 'name', 'prefix', 'is_default']),
            'products' => Product::query()->with('group:id,name')->orderBy('product_group_id')->ordered()->get()
                ->map(fn (Product $product): array => ['id' => $product->id, 'name' => $product->name, 'group' => $product->group->name, 'status' => $product->status()]),
            'drivers' => app(ProvisioningDrivers::class)->all(),
            'canManage' => request()->user()?->hasPermission('catalog.addons.manage') ?? false,
        ];
    }
}
