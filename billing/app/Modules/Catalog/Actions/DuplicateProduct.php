<?php

namespace App\Modules\Catalog\Actions;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductCustomField;
use App\Modules\Core\Support\ActivityLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Copy a product with its prices, option-group links, custom fields, addon links and upgrade paths.
 * The copy starts hidden so it is not sold before staff review it.
 */
class DuplicateProduct
{
    private const FIELD_ATTRIBUTES = ['name', 'module_key', 'type', 'options', 'description', 'validation_regex', 'is_required', 'is_admin_only', 'show_on_order', 'show_on_invoice', 'sort_order'];

    public function __construct(private ActivityLogger $activity) {}

    public function handle(Product $source): Product
    {
        return DB::transaction(function () use ($source): Product {
            $source->load(['prices', 'customFields', 'configOptionGroups:id', 'addons:id', 'upgradeTargets:id']);

            $copy = $source->replicate(['stock_quantity']);
            $copy->name = __(':name (copy)', ['name' => $source->name]);
            $copy->slug = $this->uniqueSlug($source->slug.'-copy');
            $copy->is_hidden = true;
            $copy->stock_quantity = 0;
            $copy->sort_order = $source->sort_order + 1;
            $copy->save();

            $copy->prices()->createMany($source->prices->map(fn ($price): array => $price->only(['currency', 'cycle', 'enabled', 'amount', 'setup_fee']))->all());
            $copy->customFields()->createMany($source->customFields->map(fn (ProductCustomField $field): array => $field->only(self::FIELD_ATTRIBUTES))->all());
            $copy->configOptionGroups()->sync($source->configOptionGroups->modelKeys());
            $copy->addons()->sync($source->addons->modelKeys());
            $copy->upgradeTargets()->sync($source->upgradeTargets->modelKeys());

            $this->activity->log('Product duplicated', $copy, null, ['source_id' => $source->id]);

            return $copy;
        });
    }

    private function uniqueSlug(string $base): string
    {
        $slug = Str::limit($base, 240, '');

        for ($i = 2; Product::withTrashed()->where('slug', $slug)->exists(); $i++) {
            $slug = Str::limit($base, 240, '').'-'.$i;
        }

        return $slug;
    }
}
