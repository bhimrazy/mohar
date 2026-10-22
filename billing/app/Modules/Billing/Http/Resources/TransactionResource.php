<?php

namespace App\Modules\Billing\Http\Resources;

use App\Modules\Billing\Models\Gateway;
use App\Modules\Billing\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Transaction
 */
class TransactionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $staff = (bool) $request->user()?->is_staff;

        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'status' => $this->status->value,
            'gateway' => $this->gateway,
            // The staff-set name ("eSewa"), so the client area never title-cases the key ("Esewa").
            'gateway_label' => $this->gatewayNames($request)[$this->gateway] ?? null,
            'gateway_txn_id' => $this->gateway_txn_id,
            'amount_in' => $this->amount_in,
            'amount_out' => $this->amount_out,
            'fee' => $this->when($staff, $this->fee),
            'currency' => $this->currency,
            'gateway_amount' => $this->gateway_amount,
            'gateway_currency' => $this->gateway_currency,
            'exchange_rate' => $this->exchange_rate,
            'refundable_remaining' => $this->refundable_remaining,
            'refundable' => $this->isRefundable(),
            // The part of a chargeback or provider refund staff still have to review (staff only).
            'to_review' => $this->when($staff, $this->reviewed_at === null ? $this->review_amount : null),
            'description' => $this->description,
            'parent_transaction_id' => $this->parent_transaction_id,
            'invoice_id' => $this->invoice_id,
            'invoice_number' => $this->whenLoaded('invoice', fn (): ?string => $this->invoice?->displayNumber()),
            'client' => $this->whenLoaded('client', fn (): array => ['id' => $this->client->id, 'display_name' => $this->client->display_name]),
            'occurred_at' => $this->occurred_at->toIso8601String(),
        ];
    }

    /**
     * Display names of every gateway, read once per request.
     *
     * @return array<string, string>
     */
    private function gatewayNames(Request $request): array
    {
        $names = $request->attributes->get('gateway_names');

        if (! is_array($names)) {
            $names = Gateway::query()->pluck('display_name', 'key')->all();
            $request->attributes->set('gateway_names', $names);
        }

        return $names;
    }
}
