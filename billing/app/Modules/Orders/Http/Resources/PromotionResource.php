<?php

namespace App\Modules\Orders\Http\Resources;

use App\Modules\Orders\Models\Promotion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Promotion
 */
class PromotionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'type' => $this->type->value,
            'type_label' => $this->type->label(),
            'value' => $this->value,
            'summary' => $this->summary(),
            'status' => $this->displayStatus(),
            'recurring' => $this->recurring,
            'recur_count' => $this->recur_count,
            'applies_to_products' => $this->applies_to['product_ids'] ?? [],
            'applies_to_addons' => $this->applies_to['addon_ids'] ?? [],
            'applies_to_tlds' => implode(', ', $this->applies_to['tlds'] ?? []),
            'requires_products' => $this->requires['product_ids'] ?? [],
            'requires_existing_ok' => $this->requires_existing_ok,
            'cycles' => $this->cycles ?? [],
            'starts_on' => $this->starts_on?->toDateString(),
            'expires_on' => $this->expires_on?->toDateString(),
            'max_uses' => $this->max_uses,
            'uses' => $this->uses,
            'apply_once' => $this->apply_once,
            'once_per_client' => $this->once_per_client,
            'new_clients_only' => $this->new_clients_only,
            'existing_clients_only' => $this->existing_clients_only,
            'lifetime' => $this->lifetime,
            'allow_upgrades' => $this->allow_upgrades,
            'min_cart_amount' => $this->min_cart_amount,
            'discount_setup_fee' => $this->discount_setup_fee,
            'admin_notes' => $this->admin_notes,
            'expired_at' => $this->expired_at?->toIso8601String(),
        ];
    }

    private function displayStatus(): string
    {
        return match (true) {
            $this->expired_at !== null, $this->expires_on !== null && $this->expires_on->isPast() && ! $this->expires_on->isToday() => 'expired',
            $this->starts_on !== null && $this->starts_on->isFuture() => 'scheduled',
            $this->max_uses > 0 && $this->uses >= $this->max_uses => 'completed',
            default => 'active',
        };
    }
}
