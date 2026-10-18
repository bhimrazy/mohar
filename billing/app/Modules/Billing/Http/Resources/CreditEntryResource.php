<?php

namespace App\Modules\Billing\Http\Resources;

use App\Modules\Billing\Models\CreditLedgerEntry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CreditLedgerEntry
 */
class CreditEntryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'currency' => $this->currency,
            'amount' => $this->amount,
            'balance_after' => $this->balance_after,
            'reason' => $this->reason->value,
            'description' => $this->description,
            'invoice_id' => $this->invoice_id,
            'client' => $this->whenLoaded('client', fn (): array => ['id' => $this->client->id, 'display_name' => $this->client->display_name]),
            'staff' => $this->when((bool) $request->user()?->is_staff && $this->relationLoaded('staff'), fn () => $this->staff?->name),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
