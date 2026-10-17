<?php

namespace App\Modules\Catalog\Models;

use App\Modules\Catalog\Database\Factories\ConfigOptionGroupFactory;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $name
 * @property string|null $description admin only
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[UseFactory(ConfigOptionGroupFactory::class)]
#[Fillable(['name', 'description'])]
class ConfigOptionGroup extends Model
{
    /** @use HasFactory<ConfigOptionGroupFactory> */
    use HasFactory;

    /**
     * @return HasMany<ConfigOption, $this>
     */
    public function options(): HasMany
    {
        return $this->hasMany(ConfigOption::class)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * @return BelongsToMany<Product, $this>
     */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class);
    }
}
