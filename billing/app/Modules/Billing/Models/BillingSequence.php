<?php

namespace App\Modules\Billing\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Gap-free document number sequence per (sequence, reset period).
 *
 * @property int $id
 * @property string $sequence invoice|proforma|quote
 * @property string $period "" (never resets), "2026" (yearly) or "2026-10" (monthly)
 * @property int $next_number
 */
#[Fillable(['sequence', 'period', 'next_number'])]
class BillingSequence extends Model
{
    protected function casts(): array
    {
        return ['next_number' => 'integer'];
    }
}
