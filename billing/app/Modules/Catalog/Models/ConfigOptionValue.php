<?php

namespace App\Modules\Catalog\Models;

use App\Modules\Catalog\Database\Factories\ConfigOptionValueFactory;
use App\Modules\Catalog\Models\Concerns\HasPrices;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A selectable value of a configurable option. Quantity options price one unit; yes/no options price "yes".
 *
 * @property int $id
 * @property int $config_option_id
 * @property string $label shown to clients and on invoices
 * @property string|null $module_value machine value passed to the provisioning driver
 * @property bool $is_hidden
 * @property int $sort_order
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read ConfigOption $option
 */
#[UseFactory(ConfigOptionValueFactory::class)]
#[Fillable(['config_option_id', 'label', 'module_value', 'is_hidden', 'sort_order'])]
class ConfigOptionValue extends Model
{
    /** @use HasFactory<ConfigOptionValueFactory> */
    use HasFactory, HasPrices;

    protected function casts(): array
    {
        return [
            'is_hidden' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<ConfigOption, $this>
     */
    public function option(): BelongsTo
    {
        return $this->belongsTo(ConfigOption::class, 'config_option_id');
    }
}
