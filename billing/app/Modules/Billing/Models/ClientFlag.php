<?php

namespace App\Modules\Billing\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Billing's own read-model of facts other modules announce via events (e.g. OrderAccepted).
 *
 * @property int $client_id
 * @property CarbonImmutable|null $first_order_accepted_at
 * @property bool|null $separate_invoices null = use the billing.separate_invoices default
 */
#[Fillable(['client_id', 'first_order_accepted_at', 'separate_invoices'])]
class ClientFlag extends Model
{
    protected $table = 'billing_client_flags';

    protected $primaryKey = 'client_id';

    public $incrementing = false;

    protected function casts(): array
    {
        return ['first_order_accepted_at' => 'datetime', 'separate_invoices' => 'boolean'];
    }
}
