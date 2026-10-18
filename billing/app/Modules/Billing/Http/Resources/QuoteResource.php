<?php

namespace App\Modules\Billing\Http\Resources;

use App\Modules\Billing\Models\Quote;
use App\Modules\Billing\Models\QuoteItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Quote
 */
class QuoteResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $staff = (bool) $request->user()?->is_staff;

        return [
            'id' => $this->id,
            'number' => $this->displayNumber(),
            'subject' => $this->subject,
            'stage' => $this->stage->value,
            'currency' => $this->currency,
            'valid_until' => $this->valid_until?->toDateString(),
            'proposal_text' => $this->proposal_text,
            'customer_notes' => $this->customer_notes,
            'admin_notes' => $this->when($staff, $this->admin_notes),
            'subtotal' => $this->subtotal,
            'taxes' => array_values(array_filter([
                $this->tax1_name !== null ? ['name' => $this->tax1_name, 'rate' => (float) $this->tax1_rate, 'amount' => $this->tax1_amount] : null,
                $this->tax2_name !== null ? ['name' => $this->tax2_name, 'rate' => (float) $this->tax2_rate, 'amount' => $this->tax2_amount] : null,
            ])),
            'total' => $this->total,
            'invoice_id' => $this->invoice_id,
            'delivered_at' => $this->delivered_at?->toIso8601String(),
            'accepted_at' => $this->accepted_at?->toIso8601String(),
            'declined_at' => $this->declined_at?->toIso8601String(),
            'client' => $this->whenLoaded('client', fn (): array => ['id' => $this->client->id, 'display_name' => $this->client->display_name]),
            'items' => $this->whenLoaded('items', fn (): array => array_values($this->items->map(fn (QuoteItem $item): array => [
                'id' => $item->id,
                'description' => $item->description,
                'quantity' => $item->quantity,
                'unit_amount' => $item->unit_amount,
                'discount_percent' => (float) $item->discount_percent,
                'amount' => $item->amount,
                'taxable' => $item->taxable,
            ])->all())),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
