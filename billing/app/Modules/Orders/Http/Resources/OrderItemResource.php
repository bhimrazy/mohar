<?php

namespace App\Modules\Orders\Http\Resources;

use App\Modules\Orders\Models\OrderItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin OrderItem
 */
class OrderItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->item_type->value,
            'item_id' => $this->item_id,
            'parent_id' => $this->parent_id,
            'status' => $this->status->value,
            'description' => $this->description,
            'domain' => $this->domain,
            'cycle' => $this->cycle?->value,
            'first_amount' => $this->first_amount,
            'recurring_amount' => $this->recurring_amount,
            'setup_fee' => $this->setup_fee,
            'discount_amount' => $this->discount_amount,
            'promotion_cycles_remaining' => $this->promotion_cycles_remaining,
            'auto_setup' => $this->when($request->user()?->is_staff, $this->auto_setup),
        ];
    }
}
