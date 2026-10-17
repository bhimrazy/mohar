<?php

namespace App\Modules\Catalog\Models;

use App\Modules\Catalog\Database\Factories\ProductCustomFieldFactory;
use App\Modules\Catalog\Enums\CustomFieldType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Definition of a per-service field. Values are stored by Services.
 *
 * @property int $id
 * @property int $product_id
 * @property string $name
 * @property string|null $module_key
 * @property CustomFieldType $type
 * @property list<string>|null $options dropdown choices
 * @property string|null $description
 * @property string|null $validation_regex
 * @property bool $is_required
 * @property bool $is_admin_only
 * @property bool $show_on_order
 * @property bool $show_on_invoice
 * @property int $sort_order
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[UseFactory(ProductCustomFieldFactory::class)]
#[Fillable(['product_id', 'name', 'module_key', 'type', 'options', 'description', 'validation_regex', 'is_required', 'is_admin_only', 'show_on_order', 'show_on_invoice', 'sort_order'])]
class ProductCustomField extends Model
{
    /** @use HasFactory<ProductCustomFieldFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'type' => CustomFieldType::class,
            'options' => 'array',
            'is_required' => 'boolean',
            'is_admin_only' => 'boolean',
            'show_on_order' => 'boolean',
            'show_on_invoice' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
