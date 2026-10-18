<?php

namespace App\Modules\Billing\Http\Resources;

use App\Modules\Billing\Models\InvoiceAdjustment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin InvoiceAdjustment
 */
class AdjustmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind->value,
            'reason' => $this->reason->value,
            'description' => $this->description,
            'amount' => $this->amount,
            'signed_amount' => $this->signedAmount(),
            'tax_amount' => $this->tax_amount,
            'created_by' => $this->when((bool) $request->user()?->is_staff && $this->relationLoaded('creator'), fn () => $this->creator?->name),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
