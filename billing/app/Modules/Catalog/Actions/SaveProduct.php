<?php

namespace App\Modules\Catalog\Actions;

use App\Modules\Catalog\Models\Product;
use App\Modules\Core\Support\ActivityLogger;
use Illuminate\Support\Facades\DB;

/**
 * Create a product or update its details (not prices or relations).
 */
class SaveProduct
{
    public function __construct(private ActivityLogger $activity) {}

    /**
     * @param  array<string, mixed>  $data  validated ProductRequest data
     */
    public function handle(array $data, ?Product $product = null): Product
    {
        return DB::transaction(function () use ($data, $product): Product {
            $product ??= new Product(['driver_config' => []]);
            $creating = ! $product->exists;
            $wasRetired = $product->is_retired ?? false;

            if ($creating) {
                $data['sort_order'] ??= (int) Product::query()->where('product_group_id', $data['product_group_id'])->max('sort_order') + 1;
            }

            $product->fill($data)->save();

            $description = match (true) {
                $creating => 'Product created',
                ! $wasRetired && $product->is_retired => 'Product retired',
                default => 'Product updated',
            };
            $this->activity->log($description, $product, null, $creating ? [] : ['changed' => array_keys($product->getChanges())]);

            return $product;
        });
    }
}
