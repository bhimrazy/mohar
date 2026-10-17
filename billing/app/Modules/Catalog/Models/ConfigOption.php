<?php

namespace App\Modules\Catalog\Models;

use App\Modules\Catalog\Database\Factories\ConfigOptionFactory;
use App\Modules\Catalog\Enums\ConfigOptionType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $config_option_group_id
 * @property string $name display label
 * @property string|null $module_key value passed to the provisioning driver
 * @property ConfigOptionType $type
 * @property int|null $quantity_min
 * @property int|null $quantity_max
 * @property int $quantity_step
 * @property bool $is_hidden
 * @property bool $is_required
 * @property int $sort_order
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read ConfigOptionGroup $group
 */
#[UseFactory(ConfigOptionFactory::class)]
#[Fillable(['config_option_group_id', 'name', 'module_key', 'type', 'quantity_min', 'quantity_max', 'quantity_step', 'is_hidden', 'is_required', 'sort_order'])]
class ConfigOption extends Model
{
    /** @use HasFactory<ConfigOptionFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'type' => ConfigOptionType::class,
            'quantity_min' => 'integer',
            'quantity_max' => 'integer',
            'quantity_step' => 'integer',
            'is_hidden' => 'boolean',
            'is_required' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<ConfigOptionGroup, $this>
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(ConfigOptionGroup::class, 'config_option_group_id');
    }

    /**
     * @return HasMany<ConfigOptionValue, $this>
     */
    public function values(): HasMany
    {
        return $this->hasMany(ConfigOptionValue::class)->orderBy('sort_order')->orderBy('id');
    }
}
