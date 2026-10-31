<?php

namespace App\Modules\Catalog\Http\Controllers\Admin;

use App\Enums\BillingCycle;
use App\Http\Controllers\Controller;
use App\Modules\Catalog\Actions\DeleteConfigOptionItem;
use App\Modules\Catalog\Actions\SaveConfigOptionGroup;
use App\Modules\Catalog\Enums\ConfigOptionType;
use App\Modules\Catalog\Http\Requests\ConfigOptionGroupRequest;
use App\Modules\Catalog\Models\ConfigOption;
use App\Modules\Catalog\Models\ConfigOptionGroup;
use App\Modules\Catalog\Models\ConfigOptionValue;
use App\Modules\Catalog\Models\Price;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Support\CyclePicker;
use App\Modules\Core\Models\Currency;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ConfigOptionGroupController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('catalog.options.view');

        $groups = ConfigOptionGroup::query()
            ->withCount(['options', 'products'])
            ->with('options:id,config_option_group_id,name')
            ->when($request->filled('search'), fn ($query) => $query->whereLike('name', '%'.$request->string('search')->toString().'%'))
            ->orderBy('name')->orderBy('id')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('admin/catalog/option-groups/index', [
            'groups' => $groups->through(fn (ConfigOptionGroup $group): array => [
                'id' => $group->id,
                'name' => $group->name,
                'description' => $group->description,
                'options_count' => $group->options_count,
                'products_count' => $group->products_count,
                'option_names' => $group->options->pluck('name')->take(4)->values(),
            ]),
            'filters' => $request->only(['search']),
            'canManage' => $request->user()?->hasPermission('catalog.options.manage') ?? false,
        ]);
    }

    public function create(): Response
    {
        $this->authorize('catalog.options.manage');

        return Inertia::render('admin/catalog/option-groups/create', ['products' => $this->productChoices()]);
    }

    public function store(ConfigOptionGroupRequest $request, SaveConfigOptionGroup $save): RedirectResponse
    {
        $group = $save->handle($request->groupData());
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Option group :name created. Add its options next.', ['name' => $group->name])]);

        return to_route('admin.catalog.option-groups.edit', $group);
    }

    public function edit(Request $request, ConfigOptionGroup $optionGroup): Response
    {
        $this->authorize('catalog.options.view');

        $optionGroup->load(['products:id', 'options.values.prices']);

        return Inertia::render('admin/catalog/option-groups/edit', [
            'group' => [
                'id' => $optionGroup->id,
                'name' => $optionGroup->name,
                'description' => $optionGroup->description,
                'product_ids' => $optionGroup->products->modelKeys(),
            ],
            'options' => $optionGroup->options->map(fn (ConfigOption $option): array => [
                ...$option->only(['id', 'name', 'module_key', 'quantity_min', 'quantity_max', 'quantity_step', 'is_hidden', 'is_required', 'sort_order']),
                'type' => $option->type->value,
                'values' => $option->values->map(fn (ConfigOptionValue $value): array => [
                    ...$value->only(['id', 'label', 'module_value', 'is_hidden', 'sort_order']),
                    'prices' => array_map(fn (Price $price) => $price->toMatrixRow(), $value->prices->values()->all()),
                ])->values(),
            ])->values(),
            'products' => $this->productChoices($optionGroup->products->modelKeys()),
            'currencies' => Currency::query()->orderByDesc('is_default')->orderBy('code')->get(['code', 'name', 'prefix', 'is_default']),
            'cycles' => CyclePicker::options([...BillingCycle::recurring(), BillingCycle::OneTime]),
            'optionTypes' => ConfigOptionType::options(),
            'canManage' => $request->user()?->hasPermission('catalog.options.manage') ?? false,
        ]);
    }

    public function update(ConfigOptionGroupRequest $request, ConfigOptionGroup $optionGroup, SaveConfigOptionGroup $save): RedirectResponse
    {
        $save->handle($request->groupData(), $optionGroup);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Option group :name saved.', ['name' => $optionGroup->name])]);

        return to_route('admin.catalog.option-groups.edit', $optionGroup);
    }

    public function destroy(ConfigOptionGroup $optionGroup, DeleteConfigOptionItem $delete): RedirectResponse
    {
        $this->authorize('catalog.options.manage');

        $delete->handle($optionGroup);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Option group :name deleted.', ['name' => $optionGroup->name])]);

        return to_route('admin.catalog.option-groups.index');
    }

    /**
     * Products that can be assigned: everything not retired, plus retired products already assigned.
     *
     * @param  array<int, int|string>  $assignedIds
     * @return list<array{id: int, name: string, group: string, status: string}>
     */
    private function productChoices(array $assignedIds = []): array
    {
        return array_values(Product::query()
            ->where(fn ($query) => $query->where('is_retired', false)->orWhereIn('id', $assignedIds))
            ->with('group:id,name')->orderBy('product_group_id')->ordered()->get()
            ->map(fn (Product $product): array => ['id' => $product->id, 'name' => $product->name, 'group' => $product->group->name, 'status' => $product->status()])
            ->all());
    }
}
