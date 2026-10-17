<?php

namespace App\Modules\Catalog\Models;

use App\Modules\Catalog\Database\Factories\ProductFactory;
use App\Modules\Catalog\Enums\AutoSetup;
use App\Modules\Catalog\Enums\FreeDomain;
use App\Modules\Catalog\Enums\PaymentType;
use App\Modules\Catalog\Enums\ProductType;
use App\Modules\Catalog\Enums\QuantityMode;
use App\Modules\Catalog\Models\Concerns\HasPrices;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property int $product_group_id
 * @property ProductType $type
 * @property string $name
 * @property string $slug
 * @property string|null $tagline
 * @property string|null $short_description
 * @property string|null $description
 * @property int $sort_order
 * @property PaymentType $payment_type
 * @property bool $is_hidden
 * @property bool $is_retired
 * @property bool $is_featured
 * @property bool $is_taxable
 * @property bool $requires_domain
 * @property bool $stock_enabled
 * @property int $stock_quantity
 * @property QuantityMode $quantity_mode
 * @property int $recurring_cycles_limit
 * @property int $fixed_term_days
 * @property string|null $fixed_term_email_key
 * @property string|null $welcome_email_key
 * @property string|null $upgrade_email_key
 * @property AutoSetup $auto_setup
 * @property string $provisioning_driver
 * @property int|null $server_group_id
 * @property array<string, mixed>|null $driver_config
 * @property bool $allow_config_option_upgrades
 * @property bool $prorata_enabled
 * @property int $prorata_day
 * @property int $prorata_charge_next_month_day
 * @property FreeDomain $free_domain
 * @property list<string>|null $free_domain_cycles
 * @property list<string>|null $free_domain_tlds
 * @property int|null $suspend_days_override
 * @property int|null $terminate_days_override
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property CarbonImmutable|null $deleted_at
 * @property-read ProductGroup $group
 */
#[UseFactory(ProductFactory::class)]
#[Fillable([
    'product_group_id', 'type', 'name', 'slug', 'tagline', 'short_description', 'description', 'sort_order',
    'payment_type', 'is_hidden', 'is_retired', 'is_featured', 'is_taxable', 'requires_domain',
    'stock_enabled', 'stock_quantity', 'quantity_mode', 'recurring_cycles_limit', 'fixed_term_days',
    'fixed_term_email_key', 'welcome_email_key', 'upgrade_email_key', 'auto_setup',
    'provisioning_driver', 'server_group_id', 'driver_config', 'allow_config_option_upgrades',
    'prorata_enabled', 'prorata_day', 'prorata_charge_next_month_day',
    'free_domain', 'free_domain_cycles', 'free_domain_tlds', 'suspend_days_override', 'terminate_days_override',
])]
class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory, HasPrices, SoftDeletes;

    protected function casts(): array
    {
        return [
            'type' => ProductType::class,
            'payment_type' => PaymentType::class,
            'quantity_mode' => QuantityMode::class,
            'auto_setup' => AutoSetup::class,
            'free_domain' => FreeDomain::class,
            'sort_order' => 'integer',
            'is_hidden' => 'boolean',
            'is_retired' => 'boolean',
            'is_featured' => 'boolean',
            'is_taxable' => 'boolean',
            'requires_domain' => 'boolean',
            'stock_enabled' => 'boolean',
            'stock_quantity' => 'integer',
            'recurring_cycles_limit' => 'integer',
            'fixed_term_days' => 'integer',
            'server_group_id' => 'integer',
            'driver_config' => 'array',
            'allow_config_option_upgrades' => 'boolean',
            'prorata_enabled' => 'boolean',
            'prorata_day' => 'integer',
            'prorata_charge_next_month_day' => 'integer',
            'free_domain_cycles' => 'array',
            'free_domain_tlds' => 'array',
            'suspend_days_override' => 'integer',
            'terminate_days_override' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<ProductGroup, $this>
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(ProductGroup::class, 'product_group_id');
    }

    /**
     * @return BelongsToMany<ConfigOptionGroup, $this>
     */
    public function configOptionGroups(): BelongsToMany
    {
        return $this->belongsToMany(ConfigOptionGroup::class);
    }

    /**
     * @return HasMany<ProductCustomField, $this>
     */
    public function customFields(): HasMany
    {
        return $this->hasMany(ProductCustomField::class)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * @return BelongsToMany<Addon, $this>
     */
    public function addons(): BelongsToMany
    {
        return $this->belongsToMany(Addon::class);
    }

    /**
     * Products this product may be upgraded or downgraded to.
     *
     * @return BelongsToMany<Product, $this>
     */
    public function upgradeTargets(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'product_upgrade_paths', 'product_id', 'target_product_id');
    }

    public function isOutOfStock(): bool
    {
        return $this->stock_enabled && $this->stock_quantity <= 0;
    }

    public function status(): string
    {
        return match (true) {
            $this->is_retired => 'retired',
            $this->is_hidden => 'hidden',
            default => 'active',
        };
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('sort_order')->orderBy('id');
    }

    /**
     * Products that can be ordered (not retired); hidden products stay orderable by direct link.
     *
     * @param  Builder<self>  $query
     */
    public function scopeOrderable(Builder $query): void
    {
        $query->where('is_retired', false);
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeSearch(Builder $query, ?string $term): void
    {
        if ($term === null || $term === '') {
            return;
        }

        $query->where(function (Builder $query) use ($term): void {
            $query->whereLike('name', "%{$term}%")->orWhereLike('slug', "%{$term}%");

            if (ctype_digit($term)) {
                $query->orWhere('id', (int) $term);
            }
        });
    }
}
