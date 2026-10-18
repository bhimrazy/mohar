<?php

namespace App\Modules\Catalog\Actions;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductCustomField;
use App\Modules\Core\Support\ActivityLogger;
use Illuminate\Support\Facades\DB;

class SaveCustomField
{
    public function __construct(private ActivityLogger $activity) {}

    /**
     * @param  array<string, mixed>  $data  validated ProductCustomFieldRequest data
     */
    public function handle(Product $product, array $data, ?ProductCustomField $field = null): ProductCustomField
    {
        return DB::transaction(function () use ($product, $data, $field): ProductCustomField {
            $field ??= new ProductCustomField([
                'product_id' => $product->id,
                'sort_order' => (int) $product->customFields()->max('sort_order') + 1,
            ]);
            $field->fill($data)->save();
            $this->activity->log('Product custom field saved', $product, null, ['field' => $field->name]);

            return $field;
        });
    }
}
