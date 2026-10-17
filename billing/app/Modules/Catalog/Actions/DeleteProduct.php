<?php

namespace App\Modules\Catalog\Actions;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Services\ServiceUsage;
use App\Modules\Core\Support\ActivityLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DeleteProduct
{
    public function __construct(private ServiceUsage $usage, private ActivityLogger $activity) {}

    /**
     * @throws ValidationException when services use the product (retire it instead)
     */
    public function handle(Product $product): void
    {
        if ($this->usage->productHasServices($product->id)) {
            throw ValidationException::withMessages([
                'product' => __(':product has services, so it can\'t be deleted. Retire it instead.', ['product' => $product->name]),
            ]);
        }

        DB::transaction(function () use ($product): void {
            DB::table('product_upgrade_paths')->where('target_product_id', $product->id)->delete();
            $product->delete();
            $this->activity->log('Product deleted', $product);
        });
    }
}
