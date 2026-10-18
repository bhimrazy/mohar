<?php

namespace App\Modules\Billing\Http\Resources;

use App\Modules\Billing\Models\InvoiceItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin InvoiceItem
 */
class InvoiceItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'billable_id' => $this->billable_id,
            'description' => $this->description,
            'quantity' => $this->quantity,
            'unit_amount' => $this->unit_amount,
            'amount' => $this->amount,
            'taxable' => $this->taxable,
            'tax_amount' => $this->tax_amount,
            'period_start' => $this->period_start?->toDateString(),
            'period_end' => $this->period_end?->toDateString(),
            'cancelled' => $this->cancelled_at !== null,
        ];
    }
}
