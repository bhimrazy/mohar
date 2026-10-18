<?php

namespace App\Modules\Catalog\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Actions\SaveCustomField;
use App\Modules\Catalog\Http\Requests\ProductCustomFieldRequest;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductCustomField;
use App\Modules\Core\Support\ActivityLogger;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class ProductCustomFieldController extends Controller
{
    public function store(ProductCustomFieldRequest $request, Product $product, SaveCustomField $save): RedirectResponse
    {
        $field = $save->handle($product, $request->fieldData());

        return $this->saved($product, __('Custom field :name added.', ['name' => $field->name]));
    }

    public function update(ProductCustomFieldRequest $request, Product $product, ProductCustomField $field, SaveCustomField $save): RedirectResponse
    {
        abort_unless($field->product_id === $product->id, 404);
        $save->handle($product, $request->fieldData(), $field);

        return $this->saved($product, __('Custom field :name updated.', ['name' => $field->name]));
    }

    public function destroy(Product $product, ProductCustomField $field, ActivityLogger $activity): RedirectResponse
    {
        $this->authorize('catalog.products.manage');
        abort_unless($field->product_id === $product->id, 404);

        $field->delete();
        $activity->log('Product custom field deleted', $product, null, ['field' => $field->name]);

        return $this->saved($product, __('Custom field :name deleted.', ['name' => $field->name]));
    }

    private function saved(Product $product, string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return to_route('admin.catalog.products.edit', ['product' => $product, 'tab' => 'fields']);
    }
}
