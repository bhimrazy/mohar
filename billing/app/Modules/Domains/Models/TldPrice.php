<?php

namespace App\Modules\Domains\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One price slab row: TLD × currency × client group (null = base) × term. Null amount = term not offered.
 *
 * @property int $id
 * @property int $tld_id
 * @property string $currency
 * @property int|null $client_group_id
 * @property int $years
 * @property int|null $register
 * @property int|null $transfer
 * @property int|null $renew
 */
#[Fillable(['tld_id', 'currency', 'client_group_id', 'years', 'register', 'transfer', 'renew'])]
class TldPrice extends Model
{
    public const ACTIONS = ['register', 'transfer', 'renew'];

    protected function casts(): array
    {
        return [
            'years' => 'integer',
            'register' => 'integer',
            'transfer' => 'integer',
            'renew' => 'integer',
            'client_group_id' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Tld, $this>
     */
    public function tld(): BelongsTo
    {
        return $this->belongsTo(Tld::class);
    }

    public function amountFor(string $action): ?int
    {
        return match ($action) {
            'register' => $this->register,
            'transfer' => $this->transfer,
            'renew' => $this->renew,
            default => null,
        };
    }
}
