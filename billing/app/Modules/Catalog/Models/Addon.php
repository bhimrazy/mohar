<?php

namespace App\Modules\Catalog\Models;

use App\Modules\Catalog\Database\Factories\AddonFactory;
use App\Modules\Catalog\Enums\AddonKind;
use App\Modules\Catalog\Enums\AutoSetup;
use App\Modules\Catalog\Enums\PaymentType;
use App\Modules\Catalog\Models\Concerns\HasPrices;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @property int $id
 * @property string $name
 * @property string|null $description
 * @property AddonKind $kind
 * @property PaymentType $payment_type
 * @property bool $is_taxable
 * @property bool $show_on_order
 * @property bool $is_hidden
 * @property bool $is_retired
 * @property bool $suspend_parent_on_overdue
 * @property string|null $welcome_email_key
 * @property int $weight
 * @property bool $allow_quantity
 * @property bool $prorata_with_parent
 * @property AutoSetup $auto_setup
 * @property string $provisioning_driver
 * @property array<string, mixed>|null $driver_config
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[UseFactory(AddonFactory::class)]
#[Fillable([
    'name', 'description', 'kind', 'payment_type', 'is_taxable', 'show_on_order', 'is_hidden', 'is_retired',
    'suspend_parent_on_overdue', 'welcome_email_key', 'weight', 'allow_quantity', 'prorata_with_parent',
    'auto_setup', 'provisioning_driver', 'driver_config',
])]
class Addon extends Model
{
    /** @use HasFactory<AddonFactory> */
    use HasFactory, HasPrices;

    protected function casts(): array
    {
        return [
            'kind' => AddonKind::class,
            'payment_type' => PaymentType::class,
            'auto_setup' => AutoSetup::class,
            'is_taxable' => 'boolean',
            'show_on_order' => 'boolean',
            'is_hidden' => 'boolean',
            'is_retired' => 'boolean',
            'suspend_parent_on_overdue' => 'boolean',
            'weight' => 'integer',
            'allow_quantity' => 'boolean',
            'prorata_with_parent' => 'boolean',
            'driver_config' => 'encrypted:array',
        ];
    }

    /**
     * Products this addon can be ordered with.
     *
     * @return BelongsToMany<Product, $this>
     */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class);
    }

    public function status(): string
    {
        return match (true) {
            $this->is_retired => 'retired',
            $this->is_hidden => 'hidden',
            default => 'active',
        };
    }
}
