<?php

namespace App\Modules\Orders\Http\Resources;

use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\CheckoutGateways;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Order
 */
class OrderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $staff = (bool) $request->user()?->is_staff;

        return [
            'id' => $this->id,
            'number' => $this->number,
            'kind' => $this->kind->value,
            'status' => $this->status->value,
            'currency' => $this->currency,
            'subtotal' => $this->subtotal,
            'discount_total' => $this->discount_total,
            'setup_total' => $this->setup_total,
            'tax_total' => $this->tax_total,
            'total' => $this->total,
            'promotion_code' => $this->promotion_code,
            'payment_gateway' => $this->payment_gateway,
            'payment_gateway_label' => app(CheckoutGateways::class)->label($this->payment_gateway, $this->currency),
            'invoice_id' => $this->invoice_id,
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
            'accepted_at' => $this->accepted_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'items_count' => $this->whenCounted('items'),
            'items' => OrderItemResource::collection($this->whenLoaded('items')),
            'client' => $this->whenLoaded('client', fn (): array => [
                'id' => $this->client->id,
                'display_name' => $this->client->display_name,
                'email' => $this->client->email,
            ]),
            ...($staff ? [
                'ip_address' => $this->ip_address,
                'user_agent' => $this->user_agent,
                'referral_code' => $this->referral_code,
                'admin_notes' => $this->admin_notes,
                'fraud_provider' => $this->fraud_provider,
                'fraud_score' => $this->fraud_score,
                'fraud_decision' => $this->fraud_decision?->value,
                'fraud_reasons' => $this->fraud_payload['reasons'] ?? [],
                'placed_by_staff' => $this->placed_by_staff_id !== null,
            ] : []),
        ];
    }
}
