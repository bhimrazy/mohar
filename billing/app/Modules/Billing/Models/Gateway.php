<?php

namespace App\Modules\Billing\Models;

use App\Modules\Billing\Database\Factories\GatewayFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Staff configuration of a gateway driver (key = driver key).
 *
 * @property int $id
 * @property string $key
 * @property string $display_name
 * @property bool $is_active
 * @property bool $show_on_order_form
 * @property int $sort_order
 * @property string|null $convert_to_currency
 * @property array<string, mixed>|null $config
 */
#[UseFactory(GatewayFactory::class)]
#[Fillable(['key', 'display_name', 'is_active', 'show_on_order_form', 'sort_order', 'convert_to_currency', 'config'])]
#[Hidden(['config'])]
class Gateway extends Model
{
    /** @use HasFactory<GatewayFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'show_on_order_form' => 'boolean',
            'config' => 'encrypted:array',
        ];
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        return ($this->config ?? [])[$key] ?? $default;
    }
}
