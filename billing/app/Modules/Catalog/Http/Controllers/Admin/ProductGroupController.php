<?php

namespace App\Modules\Catalog\Http\Controllers\Admin;

use App\Contracts\Billing\GatewayDirectory;
use App\Http\Controllers\Controller;
use App\Modules\Catalog\Actions\DeleteProductGroup;
use App\Modules\Catalog\Actions\ReorderProductGroups;
use App\Modules\Catalog\Actions\SaveProductGroup;
use App\Modules\Catalog\Http\Requests\ProductGroupRequest;
use App\Modules\Catalog\Http\Requests\ReorderProductGroupsRequest;
use App\Modules\Catalog\Models\ProductGroup;
use App\Modules\Catalog\Support\Gateways;
use App\Modules\Core\Models\Currency;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class ProductGroupController extends Controller
{
    public function index(GatewayDirectory $gateways): Response
    {
        $this->authorize('catalog.products.view');

        $gatewayNames = array_column($gateways->available(Currency::default()->code), 'label', 'key');

        $groups = ProductGroup::query()->ordered()->withCount('products')->get();

        return Inertia::render('admin/catalog/groups/index', [
            'groups' => $groups->map(fn (ProductGroup $group): array => [
                'id' => $group->id,
                'name' => $group->name,
                'slug' => $group->slug,
                'headline' => $group->headline,
                'is_hidden' => $group->is_hidden,
                'allowed_gateways' => $group->allowed_gateways,
                'products_count' => $group->products_count,
            ])->values(),
            'gatewayNames' => (object) $gatewayNames,
            'gatewayConflicts' => $this->gatewayConflicts($groups),
            'canManage' => request()->user()?->hasPermission('catalog.products.manage') ?? false,
        ]);
    }

    public function create(): Response
    {
        $this->authorize('catalog.products.manage');

        return Inertia::render('admin/catalog/groups/form', ['group' => null]);
    }

    public function store(ProductGroupRequest $request, SaveProductGroup $save): RedirectResponse
    {
        $group = $save->handle($request->groupData());
        $this->flashSaved(__('Product group :name created.', ['name' => $group->name]));

        return to_route('admin.catalog.groups.index');
    }

    public function edit(ProductGroup $group): Response
    {
        $this->authorize('catalog.products.manage');

        return Inertia::render('admin/catalog/groups/form', [
            'group' => [
                ...$group->only(['id', 'name', 'slug', 'headline', 'tagline', 'description', 'is_hidden', 'order_form_template']),
                'allowed_gateways' => $group->allowed_gateways,
            ],
        ]);
    }

    public function update(ProductGroupRequest $request, ProductGroup $group, SaveProductGroup $save): RedirectResponse
    {
        $save->handle($request->groupData(), $group);
        $this->flashSaved(__('Product group :name updated.', ['name' => $group->name]));

        return to_route('admin.catalog.groups.index');
    }

    public function destroy(ProductGroup $group, DeleteProductGroup $delete): RedirectResponse
    {
        $this->authorize('catalog.products.manage');

        $delete->handle($group);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Product group :name deleted.', ['name' => $group->name])]);

        return to_route('admin.catalog.groups.index');
    }

    public function reorder(ReorderProductGroupsRequest $request, ReorderProductGroups $reorder): RedirectResponse
    {
        $reorder->handle($request->orderedIds());
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Product group order saved.')]);

        return to_route('admin.catalog.groups.index');
    }

    /**
     * Success toast, or a warning when visible groups can no longer be paid for together.
     */
    private function flashSaved(string $message): void
    {
        $conflicts = $this->gatewayConflicts(ProductGroup::query()->ordered()->get());

        Inertia::flash('toast', $conflicts === []
            ? ['type' => 'success', 'message' => $message]
            : ['type' => 'warning', 'message' => $message.' '.__('Warning: :pairs share no payment gateway, so a cart with both can\'t be paid.', [
                'pairs' => implode(', ', array_map(fn (array $pair): string => "{$pair[0]} & {$pair[1]}", $conflicts)),
            ])]);
    }

    /**
     * @param  iterable<ProductGroup>  $groups
     * @return list<array{0: string, 1: string}>
     */
    private function gatewayConflicts(iterable $groups): array
    {
        $visible = [];

        foreach ($groups as $group) {
            if (! $group->is_hidden) {
                $visible[$group->name] = $group->allowed_gateways === null ? null : array_map('strval', $group->allowed_gateways);
            }
        }

        return Gateways::conflicts($visible);
    }
}
