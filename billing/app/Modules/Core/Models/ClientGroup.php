<?php

namespace App\Modules\Core\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $name
 * @property string|null $colour
 * @property string $discount_percent
 * @property bool $exempt_from_suspension
 * @property bool $separate_invoices
 */
#[Fillable(['name', 'colour', 'discount_percent', 'exempt_from_suspension', 'separate_invoices'])]
class ClientGroup extends Model
{
    protected function casts(): array
    {
        return [
            'discount_percent' => 'decimal:2',
            'exempt_from_suspension' => 'boolean',
            'separate_invoices' => 'boolean',
        ];
    }

    /**
     * @return HasMany<Client, $this>
     */
    public function clients(): HasMany
    {
        return $this->hasMany(Client::class);
    }
}
