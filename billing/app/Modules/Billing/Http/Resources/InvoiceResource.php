<?php

namespace App\Modules\Billing\Http\Resources;

use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Support\InvoiceBsDates;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Invoice
 */
class InvoiceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $staff = (bool) $request->user()?->is_staff;
        $bs = app(InvoiceBsDates::class);

        return [
            'id' => $this->id,
            'number' => $this->displayNumber(),
            'legal_number' => $this->number,
            'proforma_number' => $this->proforma_number,
            'status' => $this->status->value,
            'display_status' => $this->displayStatus(),
            'kind' => $this->kind->value,
            'currency' => $this->currency,
            'issue_date' => $this->issue_date?->toDateString(),
            'due_date' => $this->due_date?->toDateString(),
            'issue_date_bs' => $bs->label($this->issue_date),
            'due_date_bs' => $bs->label($this->due_date),
            'paid_at' => $this->paid_at?->toIso8601String(),
            'subtotal' => $this->subtotal,
            'discount_total' => $this->discount_total,
            'taxes' => array_values(array_filter([
                $this->tax1_name !== null ? ['name' => $this->tax1_name, 'rate' => (float) $this->tax1_rate, 'amount' => $this->tax1_amount] : null,
                $this->tax2_name !== null ? ['name' => $this->tax2_name, 'rate' => (float) $this->tax2_rate, 'amount' => $this->tax2_amount] : null,
            ])),
            'tax_inclusive' => $this->tax_inclusive,
            'tax_exempt_reason' => $this->tax_exempt_reason?->value,
            'total' => $this->total,
            'amount_paid' => $this->amount_paid,
            'credit_applied' => $this->credit_applied,
            'adjustments_total' => $this->adjustments_total,
            'balance' => $this->balance,
            'payment_gateway' => $this->payment_gateway,
            'notes' => $this->notes,
            'admin_notes' => $this->when($staff, $this->admin_notes),
            'client_snapshot' => $this->client_snapshot,
            'client' => $this->whenLoaded('client', fn (): array => ['id' => $this->client->id, 'display_name' => $this->client->display_name]),
            'items' => $this->whenLoaded('items', fn () => InvoiceItemResource::collection($this->items)),
            'adjustments' => $this->whenLoaded('adjustments', fn () => AdjustmentResource::collection($this->adjustments)),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
